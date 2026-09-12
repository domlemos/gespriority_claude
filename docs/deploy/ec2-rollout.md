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
isolada, seeders de referência, validar `/up`) — com uma diferença
importante na seção 2: **clone direto em `/opt/app`**, não em qualquer
diretório:

```bash
git clone https://github.com/domlemos/gespriority_claude.git /opt/app
cd /opt/app
```

Esse caminho é fixo — é o que `APP_DIR` em
[`deploy-ec2.yml`](../../.github/workflows/deploy-ec2.yml) espera pro
deploy automático (seção 7) conseguir achar o clone depois. Clonar em
outro lugar (ou, pior, copiar os arquivos sem `git clone` — `rsync`/`scp`
de um checkout local, por exemplo) faz o pipeline falhar com `fatal: not
a git repository`, porque não sobra histórico git nenhum pra dar
`fetch`/`checkout`.

E, já que a sessão SSM roda como `root` mas o clone normalmente fica com
dono `ec2-user`: exporte `HOME=/root` e rode `git config --global --add
safe.directory /opt/app` antes de qualquer comando git nessa sessão —
sem isso o git recusa operar ali por "dubious ownership".

Além disso, só duas diferenças no `.env`, porque aqui continua tudo
dentro da AWS:

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

## 7. Deploy automático (GitHub Actions)

A partir do primeiro deploy manual (seção 4) — que precisa acontecer
uma vez pra existir algo em `/opt/app` —, todo push em `main` do backend
dispara [`deploy-ec2.yml`](../../.github/workflows/deploy-ec2.yml) e
atualiza a instância sozinho, sem sessão SSM manual:

```bash
git fetch origin main
git checkout <sha do push>
docker compose -f docker-compose.vps.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.vps.yml up -d --build app queue
```

Tudo isso rodado via `aws ssm send-command` (mesmo mecanismo do acesso
administrativo da seção 3 — a instância nunca expõe porta 22, o pipeline
chega nela do mesmo jeito que uma pessoa chegaria). O workflow autentica
via OIDC assumindo a role `module.cicd.github_actions_role_arn`
(output `backend_github_actions_role_arn` do Terraform), escopada só a
`ssm:SendCommand` nessa instância específica — sem acesso a nada além
disso. Configurar essa ARN no secret `AWS_GITHUB_ACTIONS_ROLE_ARN_EC2`
do repositório do backend (`domlemos/gespriority_claude`):

```bash
gh secret set AWS_GITHUB_ACTIONS_ROLE_ARN_EC2 \
  --repo domlemos/gespriority_claude \
  --body "$(terraform -chdir=infra2/terraform output -raw backend_github_actions_role_arn)"
```

Se a instância for recriada (novo `instance_id`), atualizar também o
`INSTANCE_ID` fixo no topo de `deploy-ec2.yml` — ele não lê do Terraform
em tempo de execução, é hardcoded de propósito pra não depender de
`terraform output` rodando dentro do CI.

O pipeline ECS antigo (`deploy-ecs.yml`) continua no repo só como
referência/rollback — mudou de `on: push` pra `on: workflow_dispatch`,
então não dispara mais sozinho (a infra que ele mira foi destruída, ver
[`aws-teardown.md`](aws-teardown.md)).

## 8. Acessar o Postgres com um SGBD (DBeaver, TablePlus, pgAdmin...)

O Postgres só escuta em `127.0.0.1:5432` **na própria instância**
(`docker-compose.vps.yml`) — nada exposto na rede, o security group nem
libera 5432 pra internet. Acesso externo é via túnel do SSM Session
Manager (precisa do [plugin da Session
Manager](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html)
instalado localmente):

```bash
aws ssm start-session \
  --target "$(terraform -chdir=infra2/terraform output -raw instance_id)" \
  --document-name AWS-StartPortForwardingSession \
  --parameters '{"portNumber":["5432"],"localPortNumber":["5432"]}'
```

Com o túnel aberto, conectar o SGBD em `localhost:5432`, banco `itsm`
(ou o valor de `DB_DATABASE`), usuário/senha do `DB_USERNAME`/
`DB_PASSWORD` do `.env` da instância (ver seção 3 pra abrir uma sessão e
conferir, sem colar a senha em lugar nenhum fora do `.env`).

## Diferenças permanentes em relação à infra antiga

- **Sem autoscaling** — uma instância só, fixa. Sob carga real (não é
  o caso agora, só testes) precisaria voltar pro desenho com ECS ou
  trocar de instance type.
- **Sem separação de processo** — `web` e `queue` dividem a mesma
  instância/memória, não são isolados como dois services ECS.
- **Backup do Postgres não é gerenciado** — mesma ressalva do
  `vps-rollout.md`: sem snapshot automático tipo RDS, precisa de
  `pg_dump` agendado se algum dia importar não perder os dados.
- **Sem ECR** — `deploy-ec2.yml` (seção 7) não builda/empurra imagem
  pra lugar nenhum; a própria instância builda a imagem localmente a
  cada deploy (`docker compose up -d --build`), diferente do
  `deploy-ecs.yml` antigo que buildava na Action e publicava no ECR.
