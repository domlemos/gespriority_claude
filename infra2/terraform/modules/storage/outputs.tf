output "attachments_bucket_name" {
  value = aws_s3_bucket.attachments.id
}

output "attachments_bucket_arn" {
  value = aws_s3_bucket.attachments.arn
}
