output "route53_name_servers" {
  value = module.dns.name_servers
}

output "instance_id" {
  value = module.compute.instance_id
}

output "instance_public_ip" {
  value = module.compute.public_ip
}

output "attachments_bucket_name" {
  value = module.storage.attachments_bucket_name
}

output "frontend_bucket_name" {
  value = module.frontend.bucket_name
}

output "frontend_distribution_domain_name" {
  value = module.frontend.distribution_domain_name
}

output "frontend_distribution_id" {
  value = module.frontend.distribution_id
}

output "frontend_github_actions_role_arn" {
  value = module.frontend.github_actions_role_arn
}

output "backend_github_actions_role_arn" {
  value = module.cicd.github_actions_role_arn
}
