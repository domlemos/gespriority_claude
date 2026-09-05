variable "project_name" {
  type = string
}

variable "domain_name" {
  type = string
}

variable "frontend_subdomain" {
  description = "Subdomínio (sem o domínio, ex: \"uplexis\") que serve o frontend via CloudFront."
  type        = string
}

variable "zone_id" {
  type = string
}

variable "acm_certificate_arn" {
  description = "ARN de um certificado ACM já validado em us-east-1, cobrindo var.domain_name e *.var.domain_name."
  type        = string
}
