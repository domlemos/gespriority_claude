variable "project_name" {
  type    = string
  default = "gespriority-itsm"
}

variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "domain_name" {
  type    = string
  default = "gespriority.com.br"
}

variable "frontend_subdomain" {
  description = "Subdomínio (sem o domínio) que serve o frontend via CloudFront. A raiz do domínio redireciona pra ele."
  type        = string
  default     = "uplexis"
}

variable "frontend_github_repository" {
  description = "Formato \"owner/repo\" do repositório do frontend, usado na trust policy do OIDC do GitHub Actions dele."
  type        = string
  default     = "domlemos/gespriority_claude_front"
}

variable "backend_github_repository" {
  description = "Formato \"owner/repo\" do repositório do backend, usado na trust policy do OIDC do GitHub Actions de deploy (module.cicd)."
  type        = string
  default     = "domlemos/gespriority_claude"
}

variable "attachments_bucket_name" {
  type    = string
  default = "gespriority-itsm"
}

# t4g.small (2 GiB) em vez de t4g.micro (1 GiB) — essa instância roda
# nginx + PHP-FPM + Postgres + queue worker todos juntos (ver
# docker-compose.vps.yml), diferente da infra antiga onde só o RDS já
# tinha 1 GiB pra ele sozinho. Ainda assim ~$12/mês, bem abaixo do setup
# ECS+RDS+ALB+NAT anterior. Cai pra t4g.micro se sobrar folga sob uso real.
variable "instance_type" {
  type    = string
  default = "t4g.small"
}

variable "root_volume_size" {
  description = "GB do volume raiz — precisa caber imagens Docker + dados do Postgres + anexos temporários, não só o SO."
  type        = number
  default     = 30
}
