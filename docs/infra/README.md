# Infraestrutura — visão geral

Referência de arquitetura da hospedagem em produção. Para o passo a
passo de como subir tudo do zero, ver [`docs/deploy/aws-rollout.md`](../deploy/aws-rollout.md).
O código-fonte de tudo isso é [`infra/terraform/`](../../infra/terraform).

Conta AWS: `602895525690`. Região: `us-east-1` (única região usada —
CloudFront exige que o certificado ACM esteja em `us-east-1`
independente de onde o CloudFront "roda", e por coincidência já é a
mesma região do resto da infra).

## Domínios

O domínio `gespriority.com.br` foi registrado no registro.br e
delegado (NS) pro Route53, que o Terraform gerencia (`aws_route53_zone`
em `infra/terraform/modules/ecs/main.tf`).

| Domínio | Aponta pra | Serve |
|---|---|---|
| `api.gespriority.com.br` | ALB | Backend Laravel (a API) |
| `uplexis.gespriority.com.br` | CloudFront | Frontend (SPA Vue) |
| `gespriority.com.br` (raiz) | CloudFront (mesma distribution do frontend) | Redirect 301 pra `uplexis.gespriority.com.br` |

O redirect da raiz é uma CloudFront Function (`viewer-request`), não uma
distribution separada — evita pagar/manter uma segunda distribution só
pra isso. A raiz e o subdomínio `uplexis` são a mesma
`aws_cloudfront_distribution`, com dois "aliases" (nomes alternativos).

Certificado: um único certificado ACM (`gespriority.com.br` +
`*.gespriority.com.br` como SAN) cobre a raiz, `api.`, `uplexis.` e
qualquer subdomínio de cliente futuro — nenhum certificado adicional é
necessário ao adicionar um novo subdomínio.

## Módulos Terraform (`infra/terraform/modules/`)

- **`network`** — VPC, subnets públicas (ALB) e privadas (ECS/RDS), NAT
  gateway, security groups (`alb` → `ecs_tasks:8000` → `rds:5432`, sem
  atalhos).
- **`data`** — RDS Postgres (`db.t4g.micro`, não Aurora — escolha
  deliberada pra manter custo mínimo), bucket S3 privado pros anexos
  (`gespriority-itsm`), segredos no Secrets Manager (senha do banco,
  `APP_KEY`).
- **`ecr`** — repositório da imagem Docker da aplicação
  (`gespriority-itsm`, tags mutáveis pra permitir re-push após falha).
- **`ecs`** — cluster ECS Fargate, ALB, certificado ACM + zona Route53,
  os dois services (`web` atrás do ALB, `queue` sem ALB), autoscaling
  (só no `web`, por CPU), identidade de domínio SES + DKIM, roles IAM.
- **`cicd`** — provider OIDC do GitHub + role que o GitHub Actions
  assume pra fazer deploy (sem chave de acesso fixa em lugar nenhum).
- **`frontend`** — bucket S3 privado + CloudFront (via Origin Access
  Control, não o padrão antigo de bucket público/OAI) que serve o
  frontend estático.

## Sizing (o mais barato possível sem comprometer desempenho)

- `web`: 0.5 vCPU / 1 GB, autoscaling por CPU (mín. 1, máx. 3, alvo
  70%) — trata latência visível ao usuário como prioridade.
- `queue`: 0.25 vCPU / 0.5 GB, fixo, sem autoscaling — atraso de fila é
  invisível ao usuário, é o lugar certo pra cortar custo primeiro.
- RDS: `db.t4g.micro`, single-AZ.
- CloudFront: `PriceClass_100` (América do Norte + Europa) — trade-off
  aceito: usuários no Brasil não pegam o edge mais próximo, latência um
  pouco maior. Dá pra subir de tier se incomodar.

## Deploy contínuo (backend)

`.github/workflows/deploy.yml`: todo push na `main` builda a imagem
Docker, publica no ECR, roda a migration do banco como uma ECS task
isolada (falha o deploy inteiro se a migration falhar, antes de tocar
nos services), e só então atualiza `web` e `queue`. Autenticação via
OIDC (role do módulo `cicd`) — nenhuma chave AWS de longa duração em
lugar nenhum, nem como GitHub Secret.

**O Terraform não redeploya a aplicação sozinho.** Os services `web` e
`queue` têm `lifecycle { ignore_changes = [task_definition, ...] }` de
propósito — depois de um `terraform apply` que muda env vars (ex:
`APP_URL`, `FRONTEND_URL`), uma nova revisão da task definition é
registrada, mas os services continuam na revisão antiga até um deploy
de verdade (push que dispara o pipeline, ou um
`aws ecs update-service --force-new-deployment --task-definition
<nova-revisão>` manual).

**Deploy do frontend também é automático**, num repositório separado:
o código (Vue 3 + Vuetify) mora em `domlemos/gespriority_claude_front`,
com seu próprio `.github/workflows/deploy.yml` — todo push na `main`
builda (`VITE_API_URL=https://api.gespriority.com.br/api`, fixado em
`.env.production` já que o Vite embute essa variável em tempo de
build, não de runtime), sincroniza `dist/` pro bucket
`gespriority-itsm-frontend` via `aws s3 sync --delete`, e invalida o
CloudFront (`aws cloudfront create-invalidation --paths "/*"`).

Autenticação via uma role OIDC própria e separada da do backend
(`module.frontend.github_actions_role_arn`), reaproveitando o mesmo
provider OIDC (é um singleton por conta AWS — a role nova só faz um
`data` lookup nele, não cria um segundo). Escopo restrito a
`s3:PutObject`/`DeleteObject`/`ListBucket` só nesse bucket e
`cloudfront:CreateInvalidation` só nessa distribution — sem nenhum
acesso a recursos do backend (ECS, RDS, o bucket de anexos, etc.).

A primeira publicação (antes desse pipeline existir) foi manual, com os
mesmos três comandos que o workflow agora automatiza.

## Autenticação/segredos

- **App → RDS/S3/SES/Secrets Manager**: via IAM task role (nenhuma
  chave de acesso fixa nas env vars da aplicação) — o SDK da AWS
  resolve credenciais automaticamente a partir da role da task ECS.
- **GitHub Actions → AWS**: via OIDC (role do módulo `cicd`), restrita
  à branch `main` do repositório `domlemos/gespriority_claude`.
  - Detalhe encontrado em produção: esse GitHub por padrão inclui IDs
    numéricos estáveis no `sub` claim do token OIDC
    (`repo:owner@ownerId/repo@repoId:ref:...`), não só o formato
    clássico `repo:owner/repo:ref:...` — a trust policy do módulo
    `cicd` aceita os dois formatos por segurança (ver comentário em
    `infra/terraform/modules/cicd/main.tf`).
- **`APP_KEY` de produção**: gerada uma vez
  (`php artisan key:generate --show`), passada como
  `TF_VAR_app_key`/`-var=app_key=...`, armazenada no Secrets Manager
  com `lifecycle { ignore_changes = [secret_string] }` — um `apply`
  futuro sem essa variável não sobrescreve/rotaciona a chave por
  acidente.
- **E-mail (SES)**: identidade de domínio + DKIM verificados
  automaticamente via Route53. A conta nasce em modo sandbox (só envia
  pra endereços verificados manualmente) — sair do sandbox é um pedido
  manual no console AWS, não automatizável via Terraform. Ver passo 8
  do `docs/deploy/aws-rollout.md`.

## Dados de acesso (protótipo)

O seeder completo (`php artisan db:seed`) **não roda em produção** —
usa `fakerphp/faker`, que é dependência de desenvolvimento e não existe
na imagem de produção (`composer install --no-dev`), e mistura dados de
referência essenciais (roles, permissões, categorias, políticas de SLA)
com contas/dados fake que não deveriam existir numa instância real.

Enquanto o produto ainda é protótipo, os seeders de referência foram
rodados manualmente uma vez via `aws ecs run-task` (comando documentado
em `docs/deploy/aws-rollout.md`), o que também criou incidentalmente 3
usuários de teste com senha fixa `"password"`:

- `admin@example.com` — senha já trocada por uma gerada aleatoriamente
  (guardada fora deste repositório).
- `supervisor@example.com` / `agente@example.com` — **ainda com a senha
  de teste padrão**, pendente de decisão (trocar ou desativar antes de
  qualquer uso real).

## Subdomínios de cliente

`var.customer_subdomains` (`infra/terraform/variables.tf`) é uma lista
de subdomínios extras que só criam um CNAME pro mesmo ALB, sem nenhuma
lógica de multi-tenant na aplicação — todos servem exatamente a mesma
instância. Adicionar um cliente novo é só adicionar o nome à lista e
rodar `terraform apply`; nenhum certificado novo é necessário (coberto
pelo SAN wildcard). Não usar `uplexis` nessa lista — esse nome já tem
tratamento próprio (é o frontend, via CloudFront, não uma cópia do
backend).

## Achados/decisões durante o primeiro rollout real

Registrados aqui porque só apareceram ao aplicar contra a AWS de
verdade — nenhuma revisão de código pré-apply os pegaria:

1. **Bug no workflow original**: `register-task-definition` recebia o
   JSON bruto do `describe-task-definition` (com campos read-only que a
   API rejeita) — corrigido filtrando esses campos via `jq` antes de
   registrar. Ver commit `9694c46`.
2. **Trust policy do OIDC**: ver seção "Autenticação/segredos" acima —
   o `sub` claim real do GitHub não batia com o formato assumido
   originalmente.
3. **Troca de dono de registros DNS já em produção**: mover o registro
   da raiz e do `uplexis` de um módulo Terraform pra outro (de "aponta
   pro ALB" pra "aponta pro CloudFront") precisou de blocos `moved` —
   sem isso, o Terraform trataria como destroy+create sem ordem
   garantida, com risco real de um intervalo sem resolução de DNS num
   domínio já monitorado. Ver commit `b7dec64`.
4. **Permissão do SES escopada errado**: a policy IAM da task role
   restringia `ses:SendEmail`/`ses:SendRawEmail` à nossa identidade de
   domínio verificada (`gespriority.com.br`). Isso quebrou o envio de
   convite pra qualquer destinatário de fora desse domínio (hotmail,
   gmail) com "not authorized ... on resource identity/<destinatário>"
   — o SES avalia autorização de IAM pra `SendRawEmail` contra **cada
   endereço da mensagem**, não só o remetente. Corrigido pra
   `Resource: "*"` (padrão que a própria AWS usa nos exemplos oficiais
   de IAM pro SES, por esse motivo exato) — a segurança real continua
   sendo o SES recusar remetentes de domínio não verificado, não o
   escopo do recurso no IAM. Ver commit `77ba4c4`.
