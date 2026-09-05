output "alb_dns_name" {
  value = module.ecs.alb_dns_name
}

output "route53_name_servers" {
  value = module.ecs.route53_name_servers
}

output "ecr_repository_url" {
  value = module.ecr.repository_url
}

output "attachments_bucket_name" {
  value = module.data.attachments_bucket_name
}

output "db_endpoint" {
  value = module.data.db_endpoint
}

output "private_subnet_ids" {
  value = module.network.private_subnet_ids
}

output "ecs_tasks_security_group_id" {
  value = module.network.ecs_tasks_security_group_id
}

output "github_actions_role_arn" {
  value = module.cicd.github_actions_role_arn
}

output "frontend_bucket_name" {
  value = module.frontend.bucket_name
}

output "frontend_distribution_id" {
  value = module.frontend.distribution_id
}

output "frontend_distribution_domain_name" {
  value = module.frontend.distribution_domain_name
}
