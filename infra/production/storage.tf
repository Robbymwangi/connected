/* Two private buckets. The frontend's built PWA, which only CloudFront may read (origin access
   control); and the report-card PDFs, which only the API host's role may read and write. A
   teacher downloads a PDF through a short-lived signed link the API makes. */

data "aws_caller_identity" "current" {}

locals {
  account_id = data.aws_caller_identity.current.account_id
}

resource "aws_s3_bucket" "frontend" {
  bucket = "connected-frontend-${local.account_id}"
}

resource "aws_s3_bucket" "reports" {
  bucket = "connected-reports-${local.account_id}"
}

resource "aws_s3_bucket_public_access_block" "private" {
  for_each = { frontend = aws_s3_bucket.frontend.id, reports = aws_s3_bucket.reports.id }

  bucket                  = each.value
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

data "aws_iam_policy_document" "frontend_from_cloudfront" {
  statement {
    actions   = ["s3:GetObject"]
    resources = ["${aws_s3_bucket.frontend.arn}/*"]
    principals {
      type        = "Service"
      identifiers = ["cloudfront.amazonaws.com"]
    }
    condition {
      test     = "StringEquals"
      variable = "AWS:SourceArn"
      values   = [aws_cloudfront_distribution.main.arn]
    }
  }
}

resource "aws_s3_bucket_policy" "frontend" {
  bucket = aws_s3_bucket.frontend.id
  policy = data.aws_iam_policy_document.frontend_from_cloudfront.json
}
