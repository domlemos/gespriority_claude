data "aws_availability_zones" "available" {
  state = "available"
}

# Rede mínima pra uma instância só — sem subnet privada, sem NAT Gateway
# (o maior item de custo da infra antiga). O Postgres roda dentro da
# própria instância (ver docker-compose.vps.yml), então não precisa de
# isolamento de rede tipo RDS-em-subnet-privada.
resource "aws_vpc" "this" {
  cidr_block           = "10.30.0.0/16"
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = {
    Name = "${var.project_name}-ec2-vpc"
  }
}

resource "aws_internet_gateway" "this" {
  vpc_id = aws_vpc.this.id

  tags = {
    Name = "${var.project_name}-ec2-igw"
  }
}

resource "aws_subnet" "public" {
  vpc_id                  = aws_vpc.this.id
  cidr_block              = "10.30.0.0/24"
  availability_zone       = data.aws_availability_zones.available.names[0]
  map_public_ip_on_launch = true

  tags = {
    Name = "${var.project_name}-ec2-public"
  }
}

resource "aws_route_table" "public" {
  vpc_id = aws_vpc.this.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.this.id
  }

  tags = {
    Name = "${var.project_name}-ec2-public-rt"
  }
}

resource "aws_route_table_association" "public" {
  subnet_id      = aws_subnet.public.id
  route_table_id = aws_route_table.public.id
}

# Só 80/443 pra internet — sem porta 22. Acesso administrativo é via SSM
# Session Manager (aws_iam_role_policy_attachment.ssm abaixo), não SSH:
# evita gerenciar par de chaves e fecha a porta mais visada por scanner
# automatizado, sem abrir mão de conseguir um shell na instância.
resource "aws_security_group" "instance" {
  name        = "${var.project_name}-ec2"
  description = "HTTP/HTTPS from internet; no SSH - admin via SSM Session Manager"
  vpc_id      = aws_vpc.this.id

  ingress {
    description = "HTTP (redirecionado pra HTTPS pelo nginx)"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTPS"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.project_name}-ec2-sg"
  }
}

data "aws_ssm_parameter" "al2023_arm64" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-arm64"
}

data "aws_iam_policy_document" "assume_ec2" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "instance" {
  name               = "${var.project_name}-ec2"
  assume_role_policy = data.aws_iam_policy_document.assume_ec2.json
}

resource "aws_iam_role_policy_attachment" "ssm" {
  role       = aws_iam_role.instance.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

data "aws_iam_policy_document" "s3_attachments" {
  statement {
    actions   = ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"]
    resources = ["${var.attachments_bucket_arn}/*"]
  }
}

resource "aws_iam_role_policy" "s3_attachments" {
  name   = "${var.project_name}-ec2-s3"
  role   = aws_iam_role.instance.id
  policy = data.aws_iam_policy_document.s3_attachments.json
}

data "aws_iam_policy_document" "ses" {
  statement {
    # Mesmo racional documentado em docs/infra/README.md (achado do
    # primeiro rollout): SES avalia autorização de IAM pra SendRawEmail
    # contra CADA endereço da mensagem, não só o remetente — escopar pro
    # domínio verificado bloquearia envio pra hotmail/gmail/etc.
    actions   = ["ses:SendEmail", "ses:SendRawEmail"]
    resources = ["*"]
  }
}

resource "aws_iam_role_policy" "ses" {
  name   = "${var.project_name}-ec2-ses"
  role   = aws_iam_role.instance.id
  policy = data.aws_iam_policy_document.ses.json
}

resource "aws_iam_instance_profile" "this" {
  name = "${var.project_name}-ec2"
  role = aws_iam_role.instance.name
}

resource "aws_instance" "this" {
  ami                         = data.aws_ssm_parameter.al2023_arm64.value
  instance_type               = var.instance_type
  subnet_id                   = aws_subnet.public.id
  vpc_security_group_ids      = [aws_security_group.instance.id]
  iam_instance_profile        = aws_iam_instance_profile.this.name
  associate_public_ip_address = true
  user_data                   = file("${path.module}/user_data.sh.tftpl")

  root_block_device {
    volume_type = "gp3"
    volume_size = var.root_volume_size
    encrypted   = true
  }

  tags = {
    Name = "${var.project_name}-ec2"
  }

  lifecycle {
    # `ami` resolve pra AMI mais recente a cada apply (SSM parameter
    # `.../al2023-ami-kernel-default-arm64`, sem versão fixa) e `user_data`
    # só importa no primeiro boot — sem isso, qualquer apply de rotina
    # substitui a instância (Postgres roda dentro dela, num volume Docker
    # local, não em algo externo tipo RDS: substituir a instância apaga o
    # banco). Trocar de propósito (nova AMI, novo bootstrap) é remover essa
    # linha por um apply, depois recolocar.
    ignore_changes = [ami, user_data]
  }
}

resource "aws_eip" "this" {
  domain   = "vpc"
  instance = aws_instance.this.id

  tags = {
    Name = "${var.project_name}-ec2-eip"
  }
}

resource "aws_route53_record" "api" {
  zone_id = var.zone_id
  name    = "api.${var.domain_name}"
  type    = "A"
  ttl     = 300
  records = [aws_eip.this.public_ip]
}
