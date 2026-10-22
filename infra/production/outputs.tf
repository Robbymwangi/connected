output "app_url" {
  description = "Where the app is: the PWA, /api, and /admin."
  value       = "https://${aws_cloudfront_distribution.main.domain_name}"
}

output "cloudfront_distribution_id" {
  description = "The CloudFront distribution in front of the app; the deploy finds it by its ConnectED comment."
  value       = aws_cloudfront_distribution.main.id
}

output "frontend_bucket" {
  description = "Where the PWA is uploaded; the deploy derives the name from the account."
  value       = aws_s3_bucket.frontend.bucket
}

output "instance_id" {
  description = "The API host; what `aws ssm start-session --target` takes. The deploy finds it by its connected-app tag."
  value       = aws_instance.app.id
}
