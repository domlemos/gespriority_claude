# Rollout — EC2 único (infra2/terraform)

Checklist pra subir a infra alternativa em [`infra2/terraform/`](../../infra2/terraform):
um único EC2 rodando nginx + PHP-FPM + Postgres + queue worker via
[`docker-compose.vps.yml`](../../docker-compose.vps.yml), no lugar do
ECS Fargate + ALB + NAT Gateway + RDS de [`infra/terraform/`](../../infra/terraform)
(descomissionado, ver [`aws-teardown.md`](aws-teardown.md)).

**URLs continuam as mesmas**: `api.gespriority.com.br` (agora servido
pelo EC2) e `uplexis.gespriority.com.br` (S3+CloudFront, inalterado —
o módulo `frontend/` é literalmente o mesmo código da infra antiga,
reaproveitado por caminho relativo).

## 1. Aplicar o Terraform

Usa o mesmo bucket de state da infra antiga (`gespriority-itsm-tfstate`),
só que numa key diferente — não precisa criar bucket de state de novo:

```bash
cd infra2/terraform
terraform init
terraform apply
```

Isso cria: zona Route53 nova (**os *name servers* são diferentes dos
antigos** — a zona anterior foi destruída, uma zona nova sempre ganha
NS novos), certificado ACM (validado via essa zona), identidade SES +
DKIM, o par de buckets S3 (`gespriority-itsm` anexos,
`gespriority-itsm-frontend`), a instância EC2 com Elastic IP, e a
distribution CloudFront do frontend.

## 2. Delegar o domínio pros novos *name servers*

Mesmo passo do rollout original, de novo — a delegação atual no
registro.br ainda aponta pros NS da zona antiga (destruída, portanto
inútil agora):

```bash
terraform output -json route53_name_servers
```

Painel do registro.br → Meus Domínios → `gespriority.com.br` → Alterar
servidores DNS → colar os 4 NS do output acima. Confirme propagação
antes de seguir:

```bash
dig NS gespriority.com.br +short
```

## 3. Acessar a instância (sem SSH)

A security group só abre 80/443 — administração é via **SSM Session
Manager**, não SSH (sem par de chaves pra gerenciar, sem porta 22
exposta):

```bash
aws ssm start-session --target "$(terraform -chdir=infra2/terraform output -raw instance_id)"
```

Dentro da sessão, vira `root` (`sudo su -` se precisar) — o Docker e o
plugin `docker compose` já vêm instalados pelo `user_data` da
instância.

## 4. Deploy da aplicação na instância

Dali em diante é **exatamente o mesmo fluxo do
[`vps-rollout.md`](vps-rollout.md)**, seções 2 a 6 (clonar o repo,
`.env`, emitir TLS via Certbot com a config de bootstrap, migration
isolada, seeders de referência, validar `/up`) — só duas diferenças no
`.env`, porque aqui continua tudo dentro da AWS:

| Variável | VPS (Hostinger) | EC2 (aqui) |
|---|---|---|
| `FILESYSTEM_DISK` | `local` | `s3` (bucket `gespriority-itsm`, credenciais resolvidas pela IAM role da instância — não precisa de `AWS_ACCESS_KEY_ID`) |
| `MAIL_MAILER` | `smtp` | `ses` (mesma IAM role, `ses:SendEmail`/`SendRawEmail` já liberado) |

`DOMAIN`/`CERTBOT_EMAIL` continuam do mesmo jeito (usados só pelo
`docker-compose.vps.yml`/nginx/Certbot, não pelo Laravel).

## 5. SES ainda em sandbox

A identidade SES é nova (a antiga foi destruída junto com a infra
anterior) — precisa verificar de novo pelo menos um endereço de teste e
pedir "production access" de novo (é por identidade/conta, não algo que
sobrevive à destruição do domain identity anterior). Mesmo passo 8 do
[`aws-rollout.md`](aws-rollout.md).

## 6. Atualizar o secret do GitHub Actions (deploy do frontend)

A role de deploy do frontend também é nova (ARN diferente da antiga):

```bash
terraform -chdir=infra2/terraform output -raw frontend_github_actions_role_arn
```

Atualizar esse valor no secret do repositório do frontend
(`domlemos/gespriority_claude_front`) que o
`.github/workflows/deploy.yml` dele usa pra assumir a role via OIDC.

## Diferenças permanentes em relação à infra antiga

- **Sem autoscaling** — uma instância só, fixa. Sob carga real (não é
  o caso agora, só testes) precisaria voltar pro desenho com ECS ou
  trocar de instance type.
- **Sem separação de processo** — `web` e `queue` dividem a mesma
  instância/memória, não são isolados como dois services ECS.
- **Backup do Postgres não é gerenciado** — mesma ressalva do
  `vps-rollout.md`: sem snapshot automático tipo RDS, precisa de
  `pg_dump` agendado se algum dia importar não perder os dados.
- **Deploy de código novo é manual** — sem pipeline tipo o antigo
  `deploy.yml` do backend (esse fazia sentido pra ECR/ECS; aqui o
  equivalente seria `git pull && docker compose up -d --build` dentro
  de uma sessão SSM, como no `vps-rollout.md`).
