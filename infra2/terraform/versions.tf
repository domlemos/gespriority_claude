terraform {
  required_version = ">= 1.10"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.70"
    }
    tls = {
      source  = "hashicorp/tls"
      version = "~> 4.0"
    }
  }

  # Mesmo bucket de state da infra antiga (infra/terraform) — não foi
  # apagado de propósito, key diferente evita colisão com o state antigo
  # (que continua existindo por enquanto, só sem recursos dentro).
  backend "s3" {
    bucket       = "gespriority-itsm-tfstate"
    key          = "producao-ec2/terraform.tfstate"
    region       = "us-east-1"
    use_lockfile = true
    encrypt      = true
  }
}
