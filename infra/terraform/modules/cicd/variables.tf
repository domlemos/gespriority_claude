variable "project_name" {
  type = string
}

variable "github_repository" {
  description = "Formato \"owner/repo\""
  type        = string
}

variable "ecr_repository_arn" {
  type = string
}

variable "ecs_cluster_arn" {
  type = string
}

variable "web_service_arn" {
  type = string
}

variable "queue_service_arn" {
  type = string
}

variable "execution_role_arn" {
  type = string
}

variable "task_role_arn" {
  type = string
}
