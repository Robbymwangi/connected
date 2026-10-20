/* The two things production's Terraform cannot create for itself (ADR 0015), applied once from a
   developer's machine with their own sign-in:

   1. The bucket production's state is kept in. Versioned, so an earlier state can be recovered,
      encrypted, and closed to the public. Terraform's own lock file in the same bucket
      (use_lockfile) stops two applies at once, so there is no lock table.
   2. GitHub's sign-in to AWS (OIDC) and the two roles a workflow may take. A workflow proves which
      repository and branch it runs for, and AWS hands it short-lived credentials; no AWS key is
      stored in GitHub. A merge request's workflow gets the plan role, which can read but not
      change anything; only a run on main gets the apply role. */

data "aws_caller_identity" "current" {}

resource "aws_s3_bucket" "state" {
  bucket = "connected-tfstate-${data.aws_caller_identity.current.account_id}"

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "state" {
  bucket = aws_s3_bucket.state.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "state" {
  bucket                  = aws_s3_bucket.state.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_iam_openid_connect_provider" "github" {
  url            = "https://token.actions.githubusercontent.com"
  client_id_list = ["sts.amazonaws.com"]
}

/* Who may take a role: a workflow of this repository, for the given subject, asking for AWS. */
data "aws_iam_policy_document" "github_trust" {
  for_each = {
    plan  = "${var.github_subject_prefix}:pull_request"
    apply = "${var.github_subject_prefix}:ref:refs/heads/main"
  }

  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]
    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github.arn]
    }
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:sub"
      values   = [each.value]
    }
  }
}

/* A merge request's plan: read everything, change nothing. Plans run without taking the state
   lock, so this role never writes even the lock file. */
resource "aws_iam_role" "plan" {
  name               = "connected-github-plan"
  assume_role_policy = data.aws_iam_policy_document.github_trust["plan"].json
}

resource "aws_iam_role_policy_attachment" "plan_read_only" {
  role       = aws_iam_role.plan.name
  policy_arn = "arn:aws:iam::aws:policy/ReadOnlyAccess"
}

/* main's apply and deploy. Administrator, because Terraform creates roles and policies itself;
   the guard is who can assume it: only a workflow run for main, which only a reviewed merge
   reaches (ADR 0015). */
resource "aws_iam_role" "apply" {
  name                 = "connected-github-apply"
  assume_role_policy   = data.aws_iam_policy_document.github_trust["apply"].json
  max_session_duration = 3600
}

resource "aws_iam_role_policy_attachment" "apply_admin" {
  role       = aws_iam_role.apply.name
  policy_arn = "arn:aws:iam::aws:policy/AdministratorAccess"
}
