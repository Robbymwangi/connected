variable "region" {
  description = "Where the state bucket lives; the same region as production."
  type        = string
  default     = "af-south-1"
}

/* The repository uses GitHub's immutable OIDC subject, which names the owner and the repository
   with their numeric ids, so a repository deleted and recreated under the same name cannot take
   these roles. Read it with: gh api repos/Robbymwangi/connected/actions/oidc/customization/sub */
variable "github_subject_prefix" {
  description = "The start of the OIDC subject GitHub sends for this repository's workflows."
  type        = string
  default     = "repo:Robbymwangi@138704364/connected@1369663400"
}
