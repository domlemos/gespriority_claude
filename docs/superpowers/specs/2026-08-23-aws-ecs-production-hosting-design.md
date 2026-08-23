# Hospedagem de produção em AWS (ECS Fargate) — Design

## Contexto

O projeto roda hoje via Laravel Octane + FrankenPHP, com um
`docker-compose.yml` voltado a desenvolvimento local (bind-mount do
código, Postgres em container exposto na porta do host, sem worker de
fila separado). Precisamos de uma estratégia de hospedagem de produção
na AWS, com deploy automatizado.

Decisões já tomadas com o usuário:
- Plataforma: AWS, compute em **ECS Fargate** (sem servidor para
  patchear).
- Banco de dados: **RDS Postgres** gerenciado.
- Armazenamento de anexos: migrar de disco local para **S3**.
- IaC: **Terraform**, partindo do zero (a conta AWS existe, mas não há
  VPC dedicada ao projeto ainda).
- CI/CD: incluído no escopo, via **GitHub Actions**.

## Escopo

Inclui:
1. Ajuste de código no `AnexoController` para tornar o disco de
   armazenamento configurável via `FILESYSTEM_DISK` (pré-requisito
   para o item 3).
2. `Dockerfile` de produção multi-stage (build de assets Vite +
   `composer install --no-dev`).
3. Infraestrutura Terraform: rede, RDS, S3, ECR, ECS (cluster, task
   definitions, services, ALB, IAM, Secrets Manager).
4. Execução de `artisan migrate --force` como etapa do deploy (ECS run
   task), não como serviço permanente.
5. Pipeline GitHub Actions: build → push ECR → migrate → deploy.

Não inclui (fora de escopo / YAGNI por enquanto):
- Serviço de scheduler (`schedule:run`) — não existe nenhum
  `withSchedule()` definido no projeto hoje. Fica documentado como
  extensão futura (EventBridge Scheduled Task), sem criar o recurso
  agora.
- CloudFront / URLs assinadas para anexos — os endpoints de
  download/delete já funcionam de forma idêntica em disco local e S3
  via `Storage::download()`/`Storage::delete()`, sem necessidade de
  CDN neste momento.
- Multi-AZ no RDS, auto-scaling avançado de ECS — variáveis
  Terraform preparadas para isso, mas com defaults simples
  (single-AZ, contagem fixa de tasks) para o primeiro corte.
- Ambiente de staging separado — o design cobre um único ambiente de
  produção; duplicar via workspace/módulo Terraform fica para depois.

## 1. Ajuste de código: `AnexoController`

Arquivo: `app/Http/Controllers/Api/AnexoController.php`.

Problema: `private const DISK = 'local';` é fixo, e
`removerMetadadosSeImagem()` usa `Storage::disk(self::DISK)->path($caminho)`
— `path()` só existe no driver `local`; no driver `s3` lança exceção.
Em produção no Fargate não há disco persistente compartilhado entre
tasks nem entre deploys, então manter anexos em disco local significa
perdê-los a cada novo deploy ou restart de task.

Mudanças:
- Remover a constante `DISK` fixa; usar o disco padrão da aplicação
  (`Storage::disk(config('filesystems.default'))`, ou simplesmente
  `Storage::` sem `->disk()` explícito, que já resolve para o disco
  default). Isso mantém `FILESYSTEM_DISK=local` funcionando em
  desenvolvimento (sem exigir credenciais AWS) e `FILESYSTEM_DISK=s3`
  em produção, controlado só por env var.
- Reescrever `removerMetadadosSeImagem()` para não depender de
  `->path()`: ler o conteúdo via `Storage::get($caminho)`, escrever em
  um arquivo temporário local (`tempnam`), processar com as funções GD
  atuais (`imagecreatefromjpeg`/`imagecreatefrompng` etc.), e regravar
  o resultado de volta via `Storage::put($caminho, ...)`. Funciona de
  forma idêntica em `local` e `s3`.
- `store()`, `download()` e `destroy()` não precisam mudar — já usam
  APIs (`$arquivo->store()`, `Storage::download()`, `Storage::delete()`)
  suportadas igualmente pelos dois drivers.

Testes: os testes de feature de anexos existentes devem continuar
passando com `FILESYSTEM_DISK=local` (usar `Storage::fake()`, como já
deve ser o padrão). Vale adicionar um teste que force o disco fake e
confirme que a remoção de metadados EXIF ainda funciona depois da
refatoração (ler/escrever bytes em vez de path).

## 2. Dockerfile de produção (multi-stage)

Estender o `docker/php/Dockerfile` atual (base
`dunglas/frankenphp:1.12.4-php8.3-alpine`) para multi-stage:

- **Stage `assets`** (`node:22-alpine` ou similar): `npm ci`,
  `npm run build`, gera `public/build/*`.
- **Stage `vendor`**: usa a imagem base atual, roda
  `composer install --no-dev --optimize-autoloader --no-interaction`
  sem os arquivos de app ainda (aproveitando cache de camada por
  `composer.json`/`composer.lock`).
- **Stage final**: copia código da aplicação, `vendor/` do stage
  anterior e `public/build/` do stage de assets; roda
  `php artisan config:cache && php artisan route:cache && php artisan view:cache`
  no build (falha o build se algo não cachear); mantém o
  `USER www-data` e `EXPOSE 8000` já existentes.

Diferença chave em relação ao Dockerfile atual: nenhuma dependência de
bind-mount (`.:/app`) em produção — a imagem é auto-contida. O
`docker-compose.yml` de desenvolvimento continua como está, sem
mudanças (ele não faz parte deste escopo).

## 3. Infraestrutura Terraform

Diretório novo `infra/terraform/` (ou `deploy/terraform/`, a
confirmar no plano), organizado em módulos:

### `network`
- VPC nova, CIDR dedicado.
- 2 subnets públicas (uma por AZ) para o ALB.
- 2 subnets privadas (uma por AZ) para ECS tasks e RDS.
- Internet Gateway, NAT Gateway (1, para reduzir custo — pode virar 2
  depois para HA), route tables.
- Security groups: `alb` (80/443 do mundo), `ecs_tasks` (8000 só do
  `alb` SG), `rds` (5432 só do `ecs_tasks` SG).

### `data`
- RDS Postgres (versão compatível com o driver `pgsql` do projeto),
  `db.t4g.micro` como default, `multi_az = false` (variável exposta
  para ligar depois), subnet group nas subnets privadas, credenciais
  geradas e guardadas no Secrets Manager (não hardcoded no
  `.tf`/state).
- Bucket S3 privado `gespriority-itsm` para anexos
  (`block_public_access` total, versionamento ligado, sem lifecycle
  rule agressiva por padrão). Ver "Decisões confirmadas" sobre o risco
  de colisão de nome globalmente único.

### `ecr`
- Repositório `gespriority-itsm` para a imagem da aplicação, com
  `image_scanning_configuration` ligado e política de lifecycle para
  não acumular imagens antigas indefinidamente.

### `ecs`
- Cluster Fargate.
- Task definition `web`: container da imagem ECR, comando
  `php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --max-requests=1000`,
  porta 8000, log driver `awslogs` → CloudWatch, env vars não sensíveis
  direto na task definition, segredos (`APP_KEY`, `DB_PASSWORD`, etc.)
  via bloco `secrets` apontando pro Secrets Manager.
- Task definition `queue`: mesma imagem, comando
  `php artisan queue:work --tries=3 --max-time=3600`, sem porta
  exposta, mesmas env vars/segredos.
- Service `web`: atrás de um Application Load Balancer (listener HTTPS
  443 com certificado ACM para `gespriority.com.br`, criado pelo
  Terraform via `aws_acm_certificate` + `aws_acm_certificate_validation`
  com `validation_method = "DNS"`; como o domínio não está na Route53,
  o registro CNAME de validação é exposto como `output` do módulo para
  o usuário cadastrar manualmente no DNS atual — ver "Decisões
  confirmadas"), target group com health check em `/up`,
  `desired_count` configurável (default 1, pronto para subir).
- Service `queue`: sem load balancer, `desired_count` configurável
  (default 1).
- IAM: task execution role (padrão, pull da imagem + logs) e task role
  dedicada por serviço com permissão restrita
  (`s3:PutObject`/`GetObject`/`DeleteObject`) só no bucket de anexos —
  sem `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` fixos nas env vars;
  o SDK da AWS (usado pelo driver `s3` do Flysystem) resolve
  credenciais automaticamente via a role da task.

## 4. Migração de banco no deploy

`php artisan migrate --force` não roda como parte do comando de
nenhum service permanente (evita condição de corrida entre múltiplas
tasks/replicas rodando migration ao mesmo tempo). Em vez disso, o
pipeline dispara um `aws ecs run-task` usando a task definition `web`
com override de comando, espera com
`aws ecs wait tasks-stopped` e confere o exit code do container antes
de prosseguir. Se a migration falhar, o deploy para ali — os services
`web`/`queue` continuam rodando a versão anterior.

## 5. Pipeline CI/CD (GitHub Actions)

Workflow disparado em push para `main`:
1. Build da imagem Docker (multi-stage do item 2).
2. Login no ECR e push da imagem com tag = SHA do commit.
3. Registrar nova revisão das task definitions `web` e `queue` com a
   nova tag de imagem.
4. `run-task` da migration usando a nova task definition `web`
   (comando sobrescrito); esperar conclusão; abortar o workflow se
   exit code ≠ 0.
5. `aws ecs update-service --force-new-deployment` para `web` e
   `queue`, apontando para as novas revisões de task definition.
6. Esperar estabilização dos services (`aws ecs wait
   services-stable`) antes de marcar o job como sucesso.

Credenciais: usar OIDC do GitHub Actions com uma IAM role assumida
via `aws-actions/configure-aws-credentials` (sem access key/secret de
longa duração armazenados como GitHub Secret).

## Testes e rollout

- Antes de apontar produção: aplicar o Terraform num plano revisado
  manualmente (`terraform plan` no PR, `apply` manual na primeira
  vez), confirmar RDS e ECS de pé, rodar a migration manualmente uma
  vez para validar conectividade antes de habilitar o pipeline
  automático.
- Validar o fluxo de anexos fim a fim (upload, download, delete,
  remoção de EXIF em imagem) apontando para o bucket S3 real antes de
  migrar tráfego real.
- Rollback: manter pelo menos as 2 últimas task definitions
  registradas no ECS (default do serviço); reverter é apontar o
  service de volta pra revisão anterior via
  `aws ecs update-service --task-definition <revisão anterior>`.

## Decisões confirmadas

- Domínio: `gespriority.com.br`, **não** hospedado na Route53. O
  certificado ACM ainda pode ser criado via Terraform
  (`aws_acm_certificate` com `validation_method = "DNS"`), mas como a
  zona não está na Route53, o Terraform não pode criar o registro de
  validação automaticamente. O `apply` vai expor o(s) registro(s)
  CNAME de validação como `output`; o usuário precisa cadastrá-los
  manualmente no DNS atual do domínio antes que
  `aws_acm_certificate_validation` (que fica aguardando) complete. Isso
  vira um passo manual documentado no plano/README de deploy, e só
  acontece uma vez (a menos que o certificado seja recriado).
- Bucket S3 de anexos: `gespriority-itsm`. Nomes de bucket S3 são
  únicos globalmente (entre todas as contas AWS) — se esse nome já
  estiver em uso por outra conta, o `apply` falha nesse recurso
  especificamente; o plano de implementação deve tratar isso como
  possível ponto de retry com um nome alternativo (ex.:
  `gespriority-itsm-anexos`), sem impacto no resto da infra.
- Repositório ECR: `gespriority-itsm` (namespace separado do S3,
  então não há conflito de nome entre os dois mesmo usando o mesmo
  valor).

## Questões em aberto (para confirmar no plano de implementação)

- Sizing inicial de CPU/memória das tasks Fargate (pode começar
  conservador e ajustar depois de medir).
