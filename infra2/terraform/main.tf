# Infra alternativa à infra/terraform original (ECS Fargate + ALB + NAT +
# RDS) — um único EC2 rodando tudo via docker-compose.vps.yml (nginx +
# PHP-FPM + Postgres + queue worker no mesmo host), pra fase de testes
# sem carga real. Ver docs/deploy/ec2-rollout.md.
#
# Mantém a mesma divisão de URLs de antes:
# - api.gespriority.com.br      → backend, agora servido pela instância
#   EC2 (nginx+Certbot na própria máquina, sem ALB).
# - uplexis.gespriority.com.br  → frontend, continua S3+CloudFront,
#   inalterado — reaproveita o módulo frontend/ da infra/terraform
#   original, sem duplicar código.

module "dns" {
  source       = "./modules/dns"
  project_name = var.project_name
  domain_name  = var.domain_name
}

module "storage" {
  source                  = "./modules/storage"
  project_name            = var.project_name
  attachments_bucket_name = var.attachments_bucket_name
}

module "compute" {
  source                 = "./modules/compute"
  project_name           = var.project_name
  domain_name            = var.domain_name
  zone_id                = module.dns.zone_id
  attachments_bucket_arn = module.storage.attachments_bucket_arn
  instance_type          = var.instance_type
  root_volume_size       = var.root_volume_size
}

module "frontend" {
  source              = "../../infra/terraform/modules/frontend"
  project_name        = var.project_name
  domain_name         = var.domain_name
  frontend_subdomain  = var.frontend_subdomain
  zone_id             = module.dns.zone_id
  acm_certificate_arn = module.dns.acm_certificate_arn
  github_repository   = var.frontend_github_repository
}

module "cicd" {
  source                    = "./modules/cicd"
  project_name              = var.project_name
  backend_github_repository = var.backend_github_repository
  instance_arn              = module.compute.instance_arn
}
