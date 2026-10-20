variable "region" {
  description = "Cape Town, the closest AWS region to Kenya."
  type        = string
  default     = "af-south-1"
}

variable "alert_email" {
  description = "Where the monthly budget alarm is sent. Locally from terraform.tfvars; in CI from the ALERT_EMAIL repository variable."
  type        = string
}

variable "monthly_budget_usd" {
  description = "The budget alarm's monthly limit, in US dollars."
  type        = number
  default     = 50
}

variable "instance_type" {
  description = "The API host. t4g is Graviton (arm64), cheaper than the Intel equivalent."
  type        = string
  default     = "t4g.small"
}

variable "instance_architecture" {
  description = "Must match instance_type: arm64 for t4g, x86_64 for t3."
  type        = string
  default     = "arm64"
}

variable "db_instance_class" {
  description = "The database. db.t4g.micro is enough for a pilot."
  type        = string
  default     = "db.t4g.micro"
}

variable "db_engine_version" {
  description = "PostgreSQL major version on RDS."
  type        = string
  default     = "17"
}

/* Off in every normal apply, CI's included. Set true only from a machine, for taking production
   down (infra/README.md, "Taking production down"): an apply with it lifts the database's deletion
   protection and lets the two buckets be deleted with files in them, and the destroy that follows
   can then remove everything. */
variable "allow_destroy" {
  description = "True only for taking production down; lifts the database and bucket safeguards."
  type        = bool
  default     = false
}
