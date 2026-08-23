module "network" {
  source       = "./modules/network"
  project_name = var.project_name
}

data "aws_caller_identity" "current" {}
