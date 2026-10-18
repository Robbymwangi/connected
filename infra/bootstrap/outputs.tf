output "state_bucket" {
  description = "Set as the GitHub repository variable TF_STATE_BUCKET, and pass to production's terraform init."
  value       = aws_s3_bucket.state.bucket
}

output "plan_role_arn" {
  description = "Set as the GitHub repository variable AWS_PLAN_ROLE_ARN."
  value       = aws_iam_role.plan.arn
}

output "apply_role_arn" {
  description = "Set as the GitHub repository variable AWS_APPLY_ROLE_ARN."
  value       = aws_iam_role.apply.arn
}
