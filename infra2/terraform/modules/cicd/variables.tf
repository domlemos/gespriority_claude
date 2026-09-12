variable "project_name" {
  type = string
}

variable "backend_github_repository" {
  description = "Formato \"owner/repo\" do repositório do backend, usado na trust policy do OIDC do GitHub Actions dele."
  type        = string
}

variable "instance_arn" {
  description = "ARN da instância EC2 alvo do deploy — ssm:SendCommand fica restrito a ela."
  type        = string
}
