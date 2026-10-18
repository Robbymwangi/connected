/* The application's production settings, in Parameter Store under /connected/production/. Each
   deploy reads them all and writes the host's .env from them, so no setting lives in the
   repository or in GitHub. The two secrets are SecureString, encrypted at rest; Terraform also
   keeps them in its state, which is why that bucket is private and encrypted (bootstrap).

   Not created here, added by hand when it is decided: ANTHROPIC_API_KEY, which stays absent until
   the privacy advisory (ADR 0013), so the assistant shows its labelled stand-in. */

locals {
  parameter_prefix = "/connected/production/"

  settings = {
    APP_URL            = "https://${aws_cloudfront_distribution.main.domain_name}"
    DB_HOST            = aws_db_instance.main.address
    DB_PORT            = tostring(aws_db_instance.main.port)
    DB_DATABASE        = aws_db_instance.main.db_name
    DB_USERNAME        = aws_db_instance.main.username
    DB_SECRET_ARN      = aws_db_instance.main.master_user_secret[0].secret_arn
    AWS_BUCKET         = aws_s3_bucket.reports.bucket
    AWS_DEFAULT_REGION = var.region
  }

  secrets = {
    APP_KEY       = "base64:${random_bytes.app_key.base64}"
    ORIGIN_SECRET = random_password.origin.result
  }
}

# Laravel's encryption key: 32 random bytes, as `php artisan key:generate` would make.
resource "random_bytes" "app_key" {
  length = 32
}

resource "aws_ssm_parameter" "setting" {
  for_each = local.settings

  name  = "${local.parameter_prefix}${each.key}"
  type  = "String"
  value = each.value
}

resource "aws_ssm_parameter" "secret" {
  for_each = nonsensitive(toset(keys(local.secrets)))

  name  = "${local.parameter_prefix}${each.key}"
  type  = "SecureString"
  value = local.secrets[each.key]
}
