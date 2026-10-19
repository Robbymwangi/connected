/* Postgres on RDS (ADR 0000). One availability zone: a standby doubles the cost, and daily
   backups kept for a week are enough for a pilot. The master password is created and rotated by
   RDS in Secrets Manager, so it never passes through Terraform or its state; the host reads it at
   deploy. Deletion protection and a final snapshot guard against an accidental destroy. */

resource "aws_db_subnet_group" "main" {
  name       = "connected"
  subnet_ids = data.aws_subnets.default.ids
}

resource "aws_db_instance" "main" {
  identifier     = "connected"
  engine         = "postgres"
  engine_version = var.db_engine_version
  instance_class = var.db_instance_class

  allocated_storage     = 20
  max_allocated_storage = 50
  storage_type          = "gp3"
  storage_encrypted     = true

  db_name                     = "connected"
  username                    = "connected"
  manage_master_user_password = true

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.db.id]
  publicly_accessible    = false
  multi_az               = false

  backup_retention_period    = 7
  auto_minor_version_upgrade = true
  deletion_protection        = true
  skip_final_snapshot        = false
  final_snapshot_identifier  = "connected-final"
}
