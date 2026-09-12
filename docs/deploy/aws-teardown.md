# Teardown — remover a infra da AWS

Runbook pra desligar tudo que [`docs/deploy/aws-rollout.md`](aws-rollout.md)
subiu, na ordem certa. Não roda nada sozinho — cada bloco abaixo é pra
copiar/colar manualmente, conferindo o resultado antes de ir pro
próximo. Pressupõe que o destino final (ex.: o VPS de
[`docs/deploy/vps-rollout.md`](vps-rollout.md)) já está no ar e
validado — não derrube a AWS antes de ter pra onde migrar.

Assume que dados (banco, anexos) não precisam ser preservados — sem
passo de backup. Se isso mudar, baixe os anexos antes do passo 1 com
`aws s3 sync s3://gespriority-itsm ./backup-anexos` (o S3, diferente do
RDS, não tem nenhuma rede de segurança automática — uma vez esvaziado,
o conteúdo não volta).

## 0. Decidir a estratégia de DNS

Duas opções, escolha antes de começar:

- **Corte com downtime**: destrói tudo, depois reaponta o domínio pro
  novo destino. Mais simples, mas o site fica fora do ar entre a
  destruição da zona Route53 e a propagação da nova delegação.
- **Corte sem downtime**: antes de destruir, edita
  `infra/terraform/modules/ecs/main.tf` pra apontar `aws_route53_record.api`
  (e o `aws_route53_record.apex`/`frontend` do módulo `frontend`, se
  aplicável) pro IP do novo VPS, aplica só isso, espera propagar,
  **só então** roda o destroy — a zona Route53 continua existindo até
  o fim, servindo a resposta nova o tempo todo. Nesse caso, pule a
  reversão de NS no passo 4 (o Route53 continua sendo o DNS provider,
  só o que muda é onde os recursos apontam).

O resto deste runbook assume a primeira opção (mais comum quando o
objetivo é realmente zerar a conta).

## 1. Preparar o Terraform pra destruir sem travar nem deixar rastro

Sem essas flags, o `destroy` do passo 3 falha em três recursos (a API
da AWS recusa apagar bucket/repositório não-vazio, com ou sem
`force_destroy` — isso não é opcional) e deixa dois tipos de coisa pra
trás mesmo depois de terminar: um snapshot manual do RDS e os dois
secrets em estado "pending deletion" por 30 dias. Como dado não precisa
ser preservado aqui, dá pra cortar os três de uma vez. Editar
temporariamente:

```hcl
# infra/terraform/modules/data/main.tf
resource "aws_secretsmanager_secret" "db_password" {
  name                    = "${var.project_name}/db-password"
  recovery_window_in_days = 0 # TEMP — só pra teardown, ver docs/deploy/aws-teardown.md
}

resource "aws_secretsmanager_secret" "app_key" {
  name                    = "${var.project_name}/app-key"
  recovery_window_in_days = 0 # TEMP — só pra teardown, ver docs/deploy/aws-teardown.md
}

resource "aws_db_instance" "this" {
  # ...
  skip_final_snapshot = true # TEMP — só pra teardown, ver docs/deploy/aws-teardown.md
}

resource "aws_s3_bucket" "attachments" {
  bucket        = var.attachments_bucket_name
  force_destroy = true # TEMP — só pra teardown, ver docs/deploy/aws-teardown.md
  # ...
}
```

```hcl
# infra/terraform/modules/frontend/main.tf
resource "aws_s3_bucket" "this" {
  bucket        = "${var.project_name}-frontend"
  force_destroy = true # TEMP — só pra teardown, ver docs/deploy/aws-teardown.md
  # ...
}
```

```hcl
# infra/terraform/modules/ecr/main.tf
resource "aws_ecr_repository" "this" {
  name                 = var.repository_name
  force_delete         = true # TEMP — só pra teardown, ver docs/deploy/aws-teardown.md
  image_tag_mutability = "MUTABLE"
  # ...
}
```

Aplicar só essas mudanças (não precisa tocar no resto da infra — nenhuma
delas força recriação, é tudo alteração in-place):

```bash
cd infra/terraform
terraform apply \
  -target=module.data.aws_s3_bucket.attachments \
  -target=module.data.aws_db_instance.this \
  -target=module.data.aws_secretsmanager_secret.db_password \
  -target=module.data.aws_secretsmanager_secret.app_key \
  -target=module.frontend.aws_s3_bucket.this \
  -target=module.ecr.aws_ecr_repository.this
```

## 2. Parar o pipeline de CI/CD

Evita runs falhando no GitHub Actions depois que ECR/ECS não existirem
mais. Antes do destroy, desabilite os workflows (Settings → Actions →
Disable, em cada um dos dois repositórios — backend e frontend — ou
comente o gatilho `on: push` nos `.github/workflows/deploy.yml`).

## 3. `terraform destroy`

```bash
cd infra/terraform
terraform destroy
```

O que esperar durante essa execução:

- **RDS**: com `skip_final_snapshot = true` do passo 1, apaga direto —
  sem snapshot manual sobrando pra limpar depois.
- **Secrets Manager** (`db-password`, `app-key`): com
  `recovery_window_in_days = 0` do passo 1, purga na hora — não fica
  em "pending deletion".
- **CloudFront**: precisa ser desabilitada antes de ser apagada — o
  provider da AWS faz isso sozinho, mas só essa etapa pode levar
  15–20 min. Isso não tem flag pra pular, é o jeito que a API da
  CloudFront funciona independente de qualquer decisão sua sobre dados.
- Tudo o mais (ECS, ALB, ACM, Route53 records, VPC/subnets/NAT/SGs, SES
  identity+DKIM, IAM roles, a role de OIDC do GitHub) não tem nenhuma
  proteção especial e destrói normalmente.

Se travar em algum recurso específico por mais de alguns minutos (fora
o CloudFront, que é esperado), rode `terraform destroy` de novo — é
idempotente, continua de onde parou.

## 4. Reverter a delegação de DNS

Só se você escolheu a estratégia com downtime do passo 0. No painel do
registro.br: Meus Domínios → `gespriority.com.br` → Alterar servidores
DNS → voltar pros *name servers* padrão do registro.br, ou apontar
direto pro provedor de DNS que for usar dali pra frente (o próprio
Hostinger, se for hospedar lá também o DNS). Confirme a propagação:

```bash
dig NS gespriority.com.br +short
```

## 5. Confirmar que não sobrou nada de custo recorrente

```bash
aws resourcegroupstaggingapi get-resources --region us-east-1 \
  --tag-filters Key=Name,Values="gespriority-itsm*"
```

Deve vir vazio — o único recurso da conta que fica de fora dessa
tag e do destroy é o bucket de state (passo 6 a seguir).

## 6. Bucket de state do Terraform

`gespriority-itsm-tfstate` nunca foi um `resource` do Terraform (é o
próprio backend, criado manualmente no passo 1 do rollout) — o destroy
do passo 3 não toca nele. Diferente dos outros itens deste runbook,
isso não é sobre custo (é centavos por mês) — é que o **state guarda em
texto puro** os valores que o código marca como `sensitive`, incluindo
a senha do banco (`random_password.db`) e a `APP_KEY` de produção
(`sensitive = true` só esconde do output do CLI, não do arquivo em si).
Depois de apagar os secrets no Secrets Manager (passo 3), esse bucket
passa a ser a **única cópia restante** dessas credenciais — sem motivo
pra manter:

1. Console S3 → bucket `gespriority-itsm-tfstate` → **Empty** (lida
   com todas as versões automaticamente — mais seguro que um script
   manual pra esse último passo).
2. Depois de confirmado vazio:
   ```bash
   aws s3api delete-bucket --bucket gespriority-itsm-tfstate --region us-east-1
   ```

Depois disso não existe mais nenhum jeito de rodar `terraform apply`
nessa mesma configuração sem recriar o backend do zero (voltando ao
passo 1 do `aws-rollout.md`) — o que é o esperado se a intenção é
mesmo sair da AWS de vez.
