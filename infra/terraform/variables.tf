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

variable "customer_subdomains" {
  description = "Subdomínios de cliente (sem o domínio) que apontam pro mesmo ALB — não usar aqui o valor de var.frontend_subdomain, que já tem tratamento próprio (CloudFront, não ALB)."
  type        = list(string)
  default     = []
}

variable "frontend_subdomain" {
  description = "Subdomínio (sem o domínio) que serve o frontend via CloudFront. A raiz do domínio (var.domain_name) redireciona pra ele."
  type        = string
  default     = "uplexis"
}

variable "attachments_bucket_name" {
  type    = string
  default = "gespriority-itsm"
}

variable "ecr_repository_name" {
  type    = string
  default = "gespriority-itsm"
}

variable "image_tag" {
  description = "Tag publicada manualmente antes do primeiro apply; o CI/CD atualiza os services diretamente depois, sem passar pelo Terraform."
  type        = string
  default     = "bootstrap"
}

variable "app_key" {
  description = "Saída de `php artisan key:generate --show`. Passar via -var ou TF_VAR_app_key, nunca commitar."
  type        = string
  sensitive   = true
}

variable "db_multi_az" {
  type    = bool
  default = false
}

variable "github_repository" {
  description = "Formato \"owner/repo\", usado na trust policy do OIDC do GitHub Actions."
  type        = string
  default     = "domlemos/gespriority_claude"
}
