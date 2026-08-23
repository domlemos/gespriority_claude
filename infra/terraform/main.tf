module "network" {
  source       = "./modules/network"
  project_name = var.project_name
}

module "data" {
  source                  = "./modules/data"
  project_name            = var.project_name
  vpc_id                  = module.network.vpc_id
  private_subnet_ids      = module.network.private_subnet_ids
  rds_security_group_id   = module.network.rds_security_group_id
  attachments_bucket_name = var.attachments_bucket_name
  db_multi_az             = var.db_multi_az
  app_key                 = var.app_key
}

module "ecr" {
  source          = "./modules/ecr"
  repository_name = var.ecr_repository_name
}

data "aws_caller_identity" "current" {}
