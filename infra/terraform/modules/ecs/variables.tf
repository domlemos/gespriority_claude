variable "project_name" {
  type = string
}

variable "aws_region" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "private_subnet_ids" {
  type = list(string)
}

variable "alb_security_group_id" {
  type = string
}

variable "ecs_tasks_security_group_id" {
  type = string
}

variable "domain_name" {
  type = string
}

variable "customer_subdomains" {
  description = "Subdomínios (sem o domínio, ex: \"uplexis\") que apontam pro mesmo ALB — cobertos pelo SAN wildcard do certificado."
  type        = list(string)
  default     = []
}

variable "ecr_repository_url" {
  type = string
}

variable "image_tag" {
  type = string
}

variable "attachments_bucket_arn" {
  type = string
}

variable "db_password_secret_arn" {
  type = string
}

variable "app_key_secret_arn" {
  type = string
}

variable "web_cpu" {
  type    = number
  default = 512
}

variable "web_memory" {
  type    = number
  default = 1024
}

variable "web_desired_count" {
  type    = number
  default = 1
}

variable "web_autoscaling_min" {
  type    = number
  default = 1
}

variable "web_autoscaling_max" {
  type    = number
  default = 3
}

variable "queue_cpu" {
  type    = number
  default = 256
}

variable "queue_memory" {
  type    = number
  default = 512
}

variable "queue_desired_count" {
  type    = number
  default = 1
}

variable "env_vars" {
  description = "Env vars não sensíveis, compartilhadas pelas tasks web e queue"
  type        = map(string)
}
