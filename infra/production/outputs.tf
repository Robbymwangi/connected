output "app_url" {
  description = "Where the app is: the PWA, /api, and /admin."
  value       = "https://${aws_cloudfront_distribution.main.domain_name}"
}

output "cloudfront_distribution_id" {
  description = "Set as the GitHub repository variable CLOUDFRONT_DISTRIBUTION_ID (the frontend deploy clears its cache)."
  value       = aws_cloudfront_distribution.main.id
}

output "frontend_bucket" {
  description = "Set as the GitHub repository variable FRONTEND_BUCKET."
  value       = aws_s3_bucket.frontend.bucket
}

output "instance_id" {
  description = "Set as the GitHub repository variable APP_INSTANCE_ID; also what `aws ssm start-session --target` takes."
  value       = aws_instance.app.id
}
