/* The API host (build plan 5.2): one EC2 instance running nginx, PHP-FPM, and the queue worker
   (ADR 0007). No SSH key and no open SSH port: administration is through AWS Systems Manager
   Session Manager, signed in with the same AWS login as everything else, and the deploy workflow
   runs its commands the same way. The setup script installs packages only; what runs, and how,
   arrives with each deploy from the repository. */

data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-${var.instance_architecture == "arm64" ? "arm64" : "amd64"}-server-*"]
  }
}

data "aws_iam_policy_document" "ec2_trust" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "app" {
  name               = "connected-app"
  assume_role_policy = data.aws_iam_policy_document.ec2_trust.json
}

resource "aws_iam_role_policy_attachment" "app_ssm" {
  role       = aws_iam_role.app.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

/* What the application itself may do: report PDFs in their bucket, its own settings, and the
   database password. Nothing else. */
data "aws_iam_policy_document" "app" {
  statement {
    sid       = "ReportCards"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.reports.arn}/*"]
  }
  statement {
    sid       = "ReportCardsList"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.reports.arn]
  }
  statement {
    sid       = "Settings"
    actions   = ["ssm:GetParametersByPath", "ssm:GetParameter", "ssm:GetParameters"]
    resources = ["arn:aws:ssm:${var.region}:${local.account_id}:parameter${local.parameter_prefix}*"]
  }
  statement {
    sid       = "DatabasePassword"
    actions   = ["secretsmanager:GetSecretValue"]
    resources = [aws_db_instance.main.master_user_secret[0].secret_arn]
  }
}

resource "aws_iam_role_policy" "app" {
  name   = "connected-app"
  role   = aws_iam_role.app.id
  policy = data.aws_iam_policy_document.app.json
}

resource "aws_iam_instance_profile" "app" {
  name = "connected-app"
  role = aws_iam_role.app.name
}

resource "aws_instance" "app" {
  ami                    = data.aws_ami.ubuntu.id
  instance_type          = var.instance_type
  subnet_id              = data.aws_subnets.default.ids[0]
  vpc_security_group_ids = [aws_security_group.app.id]
  iam_instance_profile   = aws_iam_instance_profile.app.name
  user_data              = file("${path.module}/user-data.sh")

  root_block_device {
    volume_type = "gp3"
    volume_size = 20
    encrypted   = true
  }

  # Instance metadata only through IMDSv2, which a forged request from the app cannot reach.
  metadata_options {
    http_tokens = "required"
  }

  tags = { Name = "connected-app" }

  # A newer Ubuntu image, or an edit to the setup script, must not replace a running host and
  # everything on it; the host is updated in place instead.
  lifecycle {
    ignore_changes = [ami, user_data]
  }
}

# A fixed address, so CloudFront's origin survives a stop and start.
resource "aws_eip" "app" {
  domain   = "vpc"
  instance = aws_instance.app.id
}
