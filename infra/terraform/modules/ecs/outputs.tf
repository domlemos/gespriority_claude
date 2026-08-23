output "cluster_name" {
  value = aws_ecs_cluster.this.name
}

output "alb_dns_name" {
  value = aws_lb.this.dns_name
}

output "acm_certificate_arn" {
  value = aws_acm_certificate.this.arn
}

output "acm_validation_records" {
  value = [
    for dvo in aws_acm_certificate.this.domain_validation_options : {
      name  = dvo.resource_record_name
      type  = dvo.resource_record_type
      value = dvo.resource_record_value
    }
  ]
}

output "web_service_name" {
  value = aws_ecs_service.web.name
}

output "queue_service_name" {
  value = aws_ecs_service.queue.name
}

output "web_task_family" {
  value = aws_ecs_task_definition.web.family
}

output "queue_task_family" {
  value = aws_ecs_task_definition.queue.family
}

output "execution_role_arn" {
  value = aws_iam_role.execution.arn
}

output "task_role_arn" {
  value = aws_iam_role.task.arn
}
