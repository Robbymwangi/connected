terraform {
  required_version = ">= 1.10"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = ">= 6.40, < 7.0"
    }
    random = {
      source  = "hashicorp/random"
      version = ">= 3.6, < 4.0"
    }
  }

  # The bucket is the bootstrap's output, passed at init: it holds the account number, so it is
  # given, not written here. terraform init -backend-config="bucket=connected-tfstate-<account>"
  backend "s3" {
    key          = "production/terraform.tfstate"
    region       = "af-south-1"
    encrypt      = true
    use_lockfile = true
  }
}

provider "aws" {
  region = var.region

  default_tags {
    tags = { Project = "connected", ManagedBy = "terraform", Stack = "production" }
  }
}
