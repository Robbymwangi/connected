variable "region" {
  description = "Where the state bucket lives; the same region as production."
  type        = string
  default     = "af-south-1"
}

variable "github_repository" {
  description = "The repository whose workflows may sign in to AWS, as owner/name."
  type        = string
  default     = "Robbymwangi/connected"
}
