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

locals {
  ecs_env_vars = {
    APP_NAME                    = "ITSM"
    APP_ENV                     = "production"
    APP_DEBUG                   = "false"
    APP_URL                     = "https://${var.domain_name}"
    APP_LOCALE                  = "pt_BR"
    APP_FALLBACK_LOCALE         = "en"
    LOG_CHANNEL                 = "stack"
    LOG_LEVEL                   = "error"
    BCRYPT_ROUNDS               = "12"
    DB_CONNECTION               = "pgsql"
    DB_HOST                     = module.data.db_endpoint
    DB_PORT                     = tostring(module.data.db_port)
    DB_DATABASE                 = module.data.db_name
    DB_USERNAME                 = module.data.db_username
    SESSION_DRIVER               = "database"
    SESSION_LIFETIME             = "120"
    QUEUE_CONNECTION             = "database"
    CACHE_STORE                  = "database"
    FILESYSTEM_DISK               = "s3"
    AWS_DEFAULT_REGION            = var.aws_region
    AWS_BUCKET                    = module.data.attachments_bucket_name
    AWS_USE_PATH_STYLE_ENDPOINT   = "false"
    MAIL_MAILER                   = "log"
    OCTANE_SERVER                 = "frankenphp"
  }
}

module "ecs" {
  source                       = "./modules/ecs"
  project_name                 = var.project_name
  aws_region                   = var.aws_region
  vpc_id                       = module.network.vpc_id
  public_subnet_ids            = module.network.public_subnet_ids
  private_subnet_ids           = module.network.private_subnet_ids
  alb_security_group_id        = module.network.alb_security_group_id
  ecs_tasks_security_group_id  = module.network.ecs_tasks_security_group_id
  domain_name                  = var.domain_name
  ecr_repository_url           = module.ecr.repository_url
  image_tag                    = var.image_tag
  attachments_bucket_arn       = module.data.attachments_bucket_arn
  db_password_secret_arn       = module.data.db_password_secret_arn
  app_key_secret_arn           = module.data.app_key_secret_arn
  env_vars                     = local.ecs_env_vars
}

data "aws_caller_identity" "current" {}

module "cicd" {
  source             = "./modules/cicd"
  project_name       = var.project_name
  github_repository  = var.github_repository
  ecr_repository_arn = module.ecr.repository_arn
  ecs_cluster_arn    = "arn:aws:ecs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:cluster/${module.ecs.cluster_name}"
  web_service_arn    = "arn:aws:ecs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:service/${module.ecs.cluster_name}/${module.ecs.web_service_name}"
  queue_service_arn  = "arn:aws:ecs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:service/${module.ecs.cluster_name}/${module.ecs.queue_service_name}"
  execution_role_arn = module.ecs.execution_role_arn
  task_role_arn      = module.ecs.task_role_arn
}
