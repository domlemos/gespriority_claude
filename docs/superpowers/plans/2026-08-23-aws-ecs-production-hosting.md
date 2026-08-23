# Hospedagem de Produção em AWS (ECS Fargate) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Colocar a aplicação Laravel/Octane/FrankenPHP em produção na AWS via ECS Fargate, com RDS Postgres, anexos em S3, infraestrutura em Terraform e deploy automatizado via GitHub Actions.

**Architecture:** Um único ECR repository serve uma imagem Docker multi-stage (assets Vite + vendor PHP + runtime FrankenPHP) para dois ECS services Fargate — `web` (atrás de um ALB HTTPS) e `queue` (`artisan queue:work`, sem load balancer). RDS Postgres e o bucket S3 de anexos ficam em subnets privadas. O GitHub Actions builda a imagem, roda a migration como uma ECS run-task isolada e só então atualiza os dois services — sem usar Terraform para deploys contínuos (o Terraform cria a infra e a primeira revisão de task definition; deploys seguintes acontecem via AWS CLI direto, e os services ignoram mudança de `task_definition` para não haver conflito).

**Tech Stack:** Laravel 13 (PHP 8.3) + Octane 2.17 + FrankenPHP, Terraform >= 1.10 + AWS provider ~> 5.70, GitHub Actions com OIDC (sem chaves de longa duração), PHPUnit (não usar sintaxe Pest — o projeto não tem `pestphp/pest` instalado).

**Spec:** `docs/superpowers/specs/2026-08-23-aws-ecs-production-hosting-design.md`

## Global Constraints

- PHP `^8.3`, Laravel `^13.8`, Octane `^2.17` — não mudar versões.
- Testes em PHPUnit class-based (`extends Tests\TestCase`, `public function test_..._(): void`) — o projeto **não** tem Pest instalado, nunca usar `it()`/`test()`.
- Terraform `>= 1.10` (necessário para `use_lockfile` no backend S3, sem DynamoDB).
- Provider `hashicorp/aws ~> 5.70`.
- Região AWS default: `us-east-1`.
- Domínio: `gespriority.com.br` — **não está na Route53**, validação do certificado ACM é manual (documentado na Task 13).
- Nome do bucket S3 de anexos e do repositório ECR: `gespriority-itsm` (namespaces diferentes, sem conflito).
- Sizing Fargate: `web` = 512 CPU units / 1024 MiB (0.5 vCPU / 1 GB) com auto scaling (min 1, max 3, alvo 70% CPU); `queue` = 256 CPU units / 512 MiB (0.25 vCPU / 0.5 GB) fixo.
- Sem chaves AWS de longa duração em lugar nenhum (nem env vars da app, nem GitHub Secrets) — tudo via IAM roles (task role para S3, OIDC role para o GitHub Actions).
- `aws_ecs_service.web`/`queue` usam `lifecycle { ignore_changes = [task_definition] }` — o Terraform não deve reverter a imagem publicada pelo CI/CD num `apply` seguinte.
- Repositório GitHub: `domlemos/gespriority_claude`.

---

## Task 1: Disco de armazenamento configurável no `AnexoController`

**Files:**
- Modify: `app/Http/Controllers/Api/AnexoController.php`
- Test: `tests/Feature/Incidentes/IncidenteAnexoCrudTest.php`

**Interfaces:**
- Consumes: nada de tasks anteriores (primeira task do plano).
- Produces: `AnexoController` sem disco hardcoded — todas as tasks futuras que configuram `FILESYSTEM_DISK=s3` em produção (Task 9, `env_vars` do módulo `ecs`) dependem deste comportamento para os anexos realmente irem pro S3.

Hoje `private const DISK = 'local';` (linha 15) trava o controller no disco local, e `removerMetadadosSeImagem()` (linhas 147–169) usa `Storage::disk(self::DISK)->path($caminho)` — `path()` não existe no driver `s3`. Em produção no Fargate isso faria os anexos sumirem a cada deploy/restart de task (sem disco persistente compartilhado).

- [ ] **Step 1: Escrever o teste que falha com o código atual**

Adicionar ao final da classe `IncidenteAnexoCrudTest` (antes do `}` de fechamento, depois de `test_uploading_a_non_image_file_is_not_altered_by_exif_stripping`):

```php
    public function test_exif_stripping_and_storage_work_on_a_non_local_default_disk(): void
    {
        config(['filesystems.default' => 's3']);
        Storage::fake('s3');

        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $marcador = 'SEGREDO_GPS_LAT_-23.5505_LON_-46.6333';
        $arquivo = UploadedFile::fake()->createWithContent('foto.jpg', $this->jpegComMarcadorFalso($marcador));

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertCreated();
        $anexo = Anexo::query()->first();
        $conteudoSalvo = Storage::disk('s3')->get($anexo->caminho);
        $this->assertNotNull($conteudoSalvo);
        $this->assertStringNotContainsString($marcador, $conteudoSalvo);
    }
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=test_exif_stripping_and_storage_work_on_a_non_local_default_disk`
Expected: FAIL — `$conteudoSalvo` é `null` (o arquivo foi salvo no disco `local` fake, não no `s3` fake, porque `self::DISK` está hardcoded).

- [ ] **Step 3: Remover o disco hardcoded e usar o disco default configurado**

Em `app/Http/Controllers/Api/AnexoController.php`, remover a linha 15 (`private const DISK = 'local';`).

Substituir o corpo de `store()` (a linha com `$arquivo->store(...)`):

```php
        $anexo->caminho = $arquivo->store("anexos/incidentes/{$incidente->id}");
```

Substituir `download()`:

```php
    public function download(Incidente $incidente, Anexo $anexo)
    {
        $this->ensureBelongsTo($incidente, $anexo);

        return Storage::download($anexo->caminho, $anexo->nome_original);
    }
```

Substituir `destroy()`:

```php
    public function destroy(Incidente $incidente, Anexo $anexo)
    {
        $this->ensureBelongsTo($incidente, $anexo);

        Storage::delete($anexo->caminho);
        $anexo->delete();

        return response()->noContent();
    }
```

Substituir `removerMetadadosSeImagem()` inteira (linhas 147–169) por uma versão que lê/escreve bytes em vez de usar `path()`:

```php
    private function removerMetadadosSeImagem(string $caminho, string $mimeType): void
    {
        $processadores = [
            'image/jpeg' => ['imagecreatefromjpeg', 'imagejpeg'],
            'image/png' => ['imagecreatefrompng', 'imagepng'],
        ];

        if (! isset($processadores[$mimeType])) {
            return;
        }

        [$criar, $salvar] = $processadores[$mimeType];

        $arquivoTemporario = tempnam(sys_get_temp_dir(), 'anexo_exif_');
        file_put_contents($arquivoTemporario, Storage::get($caminho));

        $imagem = @$criar($arquivoTemporario);

        if ($imagem === false) {
            unlink($arquivoTemporario);

            return;
        }

        $salvar($imagem, $arquivoTemporario);
        imagedestroy($imagem);

        Storage::put($caminho, file_get_contents($arquivoTemporario));
        unlink($arquivoTemporario);
    }
```

- [ ] **Step 4: Rodar o teste novamente e confirmar que passa**

Run: `php artisan test --filter=test_exif_stripping_and_storage_work_on_a_non_local_default_disk`
Expected: PASS

- [ ] **Step 5: Rodar a suíte completa do arquivo pra garantir que nada quebrou**

Run: `php artisan test --filter=IncidenteAnexoCrudTest`
Expected: todos os 20 testes (19 existentes + o novo) PASS. `Storage::fake('local')` do `setUp()` continua funcionando porque `config('filesystems.default')` resolve pra `'local'` no ambiente de teste (não é sobrescrito em `phpunit.xml`).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/AnexoController.php tests/Feature/Incidentes/IncidenteAnexoCrudTest.php
git commit -m "fix: make attachment disk configurable via FILESYSTEM_DISK

Storage::disk(self::DISK)->path() only works on the local driver;
Fargate tasks have no shared persistent disk, so attachments must
be able to live on S3 without app code assuming a local path."
```

---

## Task 2: Instalar o driver S3 do Flysystem

**Files:**
- Modify: `composer.json`, `composer.lock`

**Interfaces:**
- Consumes: nenhuma.
- Produces: disco `s3` funcional em `config/filesystems.php` (já configurado, só faltava o pacote) — necessário pra Task 9 (env vars `FILESYSTEM_DISK=s3` em produção) funcionar de verdade fora dos testes fake.

`league/flysystem-aws-s3-v3` e `aws/aws-sdk-php` hoje só aparecem no `suggest` do `laravel/framework` dentro do `composer.lock` — não estão instalados. Sem isso, `Storage::disk('s3')` fora de um `Storage::fake()` lança `Driver [s3] is not supported` (ou erro de classe não encontrada) em produção.

- [ ] **Step 1: Instalar o pacote**

Run: `composer require league/flysystem-aws-s3-v3`
Expected: exit 0, `composer.json`/`composer.lock` atualizados com `league/flysystem-aws-s3-v3` e `aws/aws-sdk-php` em `require`.

- [ ] **Step 2: Confirmar a instalação**

Run: `composer show league/flysystem-aws-s3-v3`
Expected: mostra a versão instalada, sem erro.

- [ ] **Step 3: Rodar a suíte completa pra garantir que nada quebrou**

Run: `php artisan test`
Expected: todos os testes PASS (o pacote novo não é exercitado pelos testes fake, mas confirma que nada no autoload quebrou).

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: add league/flysystem-aws-s3-v3 for the production s3 disk"
```

---

## Task 3: Gerar lockfile do npm

**Files:**
- Create: `package-lock.json`

**Interfaces:**
- Consumes: nenhuma.
- Produces: `package-lock.json` versionado — necessário pra Task 4 poder usar `npm ci` (reprodutível) em vez de `npm install` no build da imagem de produção.

Hoje não existe `package-lock.json`/`yarn.lock`/`pnpm-lock.yaml` no repo — `npm ci` (que exige lockfile) falharia no build da imagem.

- [ ] **Step 1: Gerar o lockfile**

Run: `npm install`
Expected: cria `node_modules/` (local, já ignorado — confirmar com `git status` que só `package-lock.json` aparece como novo) e `package-lock.json`.

- [ ] **Step 2: Confirmar que o build de assets ainda funciona com o lockfile gerado**

Run: `npm run build`
Expected: exit 0, gera `public/build/manifest.json` e os assets.

- [ ] **Step 3: Commit**

```bash
git add package-lock.json
git commit -m "build: commit package-lock.json for reproducible npm ci builds"
```

---

## Task 4: Dockerfile de produção multi-stage

**Files:**
- Create: `docker/php/Dockerfile.prod`
- Create: `docker/php/entrypoint.prod.sh`
- Create: `.dockerignore`

**Interfaces:**
- Consumes: `package-lock.json` (Task 3), `league/flysystem-aws-s3-v3` em `composer.lock` (Task 2).
- Produces: imagem Docker `gespriority-itsm:<tag>` usada por todas as tasks de Terraform/CI-CD seguintes (Tasks 9, 12, 13) — dois containers (`web`, `queue`) rodam a mesma imagem, diferindo só no `command`.

Importante: **não** rodar `php artisan config:cache` no build da imagem — nesse ponto não existem as env vars reais (elas só chegam via ECS task definition em runtime), e cachear config no build congelaria valores vazios, ignorando qualquer env var injetada depois. Por isso o cache de config roda no entrypoint, a cada start do container (cada task Fargate tem filesystem isolado, então não há corrida entre tasks).

- [ ] **Step 1: Criar `.dockerignore`**

```
.git
node_modules
vendor
.env
.env.*
!.env.example
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
```

- [ ] **Step 2: Criar o entrypoint de produção**

`docker/php/entrypoint.prod.sh`:

```sh
#!/bin/sh
set -e

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
```

- [ ] **Step 3: Criar o Dockerfile de produção**

`docker/php/Dockerfile.prod`:

```dockerfile
# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1.12.4-php8.3-alpine AS base

RUN apk add --no-cache \
        bash \
        shadow \
        icu-dev \
        postgresql-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        freetype-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql intl pcntl gd zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app


FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader


FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build


FROM base AS runtime

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN if getent passwd www-data > /dev/null; then \
        usermod -u ${USER_ID} www-data; \
    else \
        useradd -u ${USER_ID} -m www-data; \
    fi && \
    if getent group www-data > /dev/null; then \
        groupmod -g ${GROUP_ID} www-data; \
    else \
        groupadd -g ${GROUP_ID} www-data; \
    fi

COPY . /app
COPY --from=vendor /app/vendor /app/vendor
COPY --from=assets /app/public/build /app/public/build
COPY docker/php/entrypoint.prod.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data /app /config /data

USER www-data

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--max-requests=1000"]
```

- [ ] **Step 4: Buildar a imagem localmente**

Run: `docker build -f docker/php/Dockerfile.prod -t gespriority-itsm:test .`
Expected: build termina com exit 0.

- [ ] **Step 5: Smoke test — o entrypoint executa e o artisan responde**

Run: `docker run --rm gespriority-itsm:test php artisan --version`
Expected: imprime a versão do Laravel (o entrypoint roda `config:cache`/`route:cache`/`view:cache` antes, sem erro, mesmo sem env vars reais — só cacheia valores vazios, o que é esperado nesse smoke test isolado).

- [ ] **Step 6: Commit**

```bash
git add docker/php/Dockerfile.prod docker/php/entrypoint.prod.sh .dockerignore
git commit -m "build: add multi-stage production Dockerfile for ECS Fargate"
```

---

## Task 5: Bootstrap do Terraform

**Files:**
- Create: `infra/terraform/versions.tf`
- Create: `infra/terraform/providers.tf`
- Create: `infra/terraform/variables.tf`
- Create: `infra/terraform/main.tf`
- Create: `infra/terraform/outputs.tf`

**Interfaces:**
- Consumes: nenhuma.
- Produces: `var.project_name`, `var.aws_region`, `var.domain_name`, `var.attachments_bucket_name`, `var.ecr_repository_name`, `var.image_tag`, `var.app_key`, `var.db_multi_az`, `var.github_repository` — usadas por todas as tasks de módulo seguintes (6–10).

Terraform não vem instalado neste ambiente — instalar antes de prosseguir.

- [ ] **Step 1: Instalar o Terraform CLI**

Run:
```bash
wget -O- https://apt.releases.hashicorp.com/gpg | sudo gpg --dearmor -o /usr/share/keyrings/hashicorp-archive-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/hashicorp-archive-keyring.gpg] https://apt.releases.hashicorp.com $(lsb_release -cs) main" | sudo tee /etc/apt/sources.list.d/hashicorp.list
sudo apt-get update && sudo apt-get install -y terraform
```
Expected: `terraform version` mostra `Terraform v1.10` ou mais recente.

- [ ] **Step 2: Criar o bucket de state (uma vez, via AWS CLI — não faz parte do código Terraform, é o que resolve o problema de "ovo e galinha" do backend remoto)**

Run:
```bash
aws s3api create-bucket --bucket gespriority-itsm-tfstate --region us-east-1
aws s3api put-bucket-versioning --bucket gespriority-itsm-tfstate --versioning-configuration Status=Enabled
aws s3api put-public-access-block --bucket gespriority-itsm-tfstate \
  --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
```
Expected: os três comandos retornam sem erro. Requer credenciais AWS reais configuradas (`aws configure` ou variáveis `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_SESSION_TOKEN`) — isso é responsabilidade de quem está executando o plano, não do ambiente de desenvolvimento local.

- [ ] **Step 3: Criar `infra/terraform/versions.tf`**

```hcl
terraform {
  required_version = ">= 1.10"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.70"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
    tls = {
      source  = "hashicorp/tls"
      version = "~> 4.0"
    }
  }

  backend "s3" {
    bucket       = "gespriority-itsm-tfstate"
    key          = "producao/terraform.tfstate"
    region       = "us-east-1"
    use_lockfile = true
    encrypt      = true
  }
}
```

- [ ] **Step 4: Criar `infra/terraform/providers.tf`**

```hcl
provider "aws" {
  region = var.aws_region
}
```

- [ ] **Step 5: Criar `infra/terraform/variables.tf`**

```hcl
variable "project_name" {
  type    = string
  default = "gespriority-itsm"
}

variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "domain_name" {
  type    = string
  default = "gespriority.com.br"
}

variable "attachments_bucket_name" {
  type    = string
  default = "gespriority-itsm"
}

variable "ecr_repository_name" {
  type    = string
  default = "gespriority-itsm"
}

variable "image_tag" {
  description = "Tag publicada manualmente antes do primeiro apply; o CI/CD atualiza os services diretamente depois, sem passar pelo Terraform."
  type        = string
  default     = "bootstrap"
}

variable "app_key" {
  description = "Saída de `php artisan key:generate --show`. Passar via -var ou TF_VAR_app_key, nunca commitar."
  type        = string
  sensitive   = true
}

variable "db_multi_az" {
  type    = bool
  default = false
}

variable "github_repository" {
  description = "Formato \"owner/repo\", usado na trust policy do OIDC do GitHub Actions."
  type        = string
  default     = "domlemos/gespriority_claude"
}
```

- [ ] **Step 6: Criar `infra/terraform/main.tf` (esqueleto vazio por enquanto)**

```hcl
data "aws_caller_identity" "current" {}
```

- [ ] **Step 7: Criar `infra/terraform/outputs.tf` (vazio por enquanto)**

```hcl
```

- [ ] **Step 8: `terraform init` e `terraform validate`**

Run (dentro de `infra/terraform/`):
```bash
terraform init
terraform validate
```
Expected: `init` baixa os providers com sucesso (precisa de rede, não de credenciais AWS); `validate` responde `Success! The configuration is valid.`

- [ ] **Step 9: Commit**

```bash
git add infra/terraform/versions.tf infra/terraform/providers.tf infra/terraform/variables.tf infra/terraform/main.tf infra/terraform/outputs.tf
git commit -m "infra: bootstrap Terraform root module for AWS hosting"
```

---

## Task 6: Módulo Terraform `network`

**Files:**
- Create: `infra/terraform/modules/network/main.tf`
- Create: `infra/terraform/modules/network/variables.tf`
- Create: `infra/terraform/modules/network/outputs.tf`
- Modify: `infra/terraform/main.tf`

**Interfaces:**
- Consumes: `var.project_name` (root).
- Produces: `module.network.vpc_id`, `module.network.public_subnet_ids`, `module.network.private_subnet_ids`, `module.network.alb_security_group_id`, `module.network.ecs_tasks_security_group_id`, `module.network.rds_security_group_id` — usados pelas Tasks 7 (`data`) e 9 (`ecs`).

- [ ] **Step 1: Criar `infra/terraform/modules/network/variables.tf`**

```hcl
variable "project_name" {
  type = string
}

variable "vpc_cidr" {
  type    = string
  default = "10.20.0.0/16"
}

variable "public_subnet_cidrs" {
  type    = list(string)
  default = ["10.20.0.0/24", "10.20.1.0/24"]
}

variable "private_subnet_cidrs" {
  type    = list(string)
  default = ["10.20.10.0/24", "10.20.11.0/24"]
}
```

- [ ] **Step 2: Criar `infra/terraform/modules/network/main.tf`**

```hcl
data "aws_availability_zones" "available" {
  state = "available"
}

locals {
  azs = slice(data.aws_availability_zones.available.names, 0, 2)
}

resource "aws_vpc" "this" {
  cidr_block           = var.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = {
    Name = "${var.project_name}-vpc"
  }
}

resource "aws_internet_gateway" "this" {
  vpc_id = aws_vpc.this.id

  tags = {
    Name = "${var.project_name}-igw"
  }
}

resource "aws_subnet" "public" {
  count                   = length(var.public_subnet_cidrs)
  vpc_id                  = aws_vpc.this.id
  cidr_block              = var.public_subnet_cidrs[count.index]
  availability_zone       = local.azs[count.index]
  map_public_ip_on_launch = true

  tags = {
    Name = "${var.project_name}-public-${local.azs[count.index]}"
  }
}

resource "aws_subnet" "private" {
  count             = length(var.private_subnet_cidrs)
  vpc_id            = aws_vpc.this.id
  cidr_block        = var.private_subnet_cidrs[count.index]
  availability_zone = local.azs[count.index]

  tags = {
    Name = "${var.project_name}-private-${local.azs[count.index]}"
  }
}

resource "aws_eip" "nat" {
  domain = "vpc"

  tags = {
    Name = "${var.project_name}-nat-eip"
  }
}

resource "aws_nat_gateway" "this" {
  allocation_id = aws_eip.nat.id
  subnet_id     = aws_subnet.public[0].id

  tags = {
    Name = "${var.project_name}-nat"
  }

  depends_on = [aws_internet_gateway.this]
}

resource "aws_route_table" "public" {
  vpc_id = aws_vpc.this.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.this.id
  }

  tags = {
    Name = "${var.project_name}-public-rt"
  }
}

resource "aws_route_table_association" "public" {
  count          = length(aws_subnet.public)
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}

resource "aws_route_table" "private" {
  vpc_id = aws_vpc.this.id

  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.this.id
  }

  tags = {
    Name = "${var.project_name}-private-rt"
  }
}

resource "aws_route_table_association" "private" {
  count          = length(aws_subnet.private)
  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private.id
}

resource "aws_security_group" "alb" {
  name        = "${var.project_name}-alb"
  description = "Allow inbound HTTP/HTTPS from the internet to the ALB"
  vpc_id      = aws_vpc.this.id

  ingress {
    description = "HTTPS"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTP (redirected to HTTPS at the listener)"
    from_port   = 80
    to_port     = 80
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
    Name = "${var.project_name}-alb-sg"
  }
}

resource "aws_security_group" "ecs_tasks" {
  name        = "${var.project_name}-ecs-tasks"
  description = "Allow inbound app traffic from the ALB only"
  vpc_id      = aws_vpc.this.id

  ingress {
    description     = "App port from ALB"
    from_port       = 8000
    to_port         = 8000
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.project_name}-ecs-tasks-sg"
  }
}

resource "aws_security_group" "rds" {
  name        = "${var.project_name}-rds"
  description = "Allow inbound Postgres from ECS tasks only"
  vpc_id      = aws_vpc.this.id

  ingress {
    description     = "Postgres from ECS tasks"
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs_tasks.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "${var.project_name}-rds-sg"
  }
}
```

- [ ] **Step 3: Criar `infra/terraform/modules/network/outputs.tf`**

```hcl
output "vpc_id" {
  value = aws_vpc.this.id
}

output "public_subnet_ids" {
  value = aws_subnet.public[*].id
}

output "private_subnet_ids" {
  value = aws_subnet.private[*].id
}

output "alb_security_group_id" {
  value = aws_security_group.alb.id
}

output "ecs_tasks_security_group_id" {
  value = aws_security_group.ecs_tasks.id
}

output "rds_security_group_id" {
  value = aws_security_group.rds.id
}
```

- [ ] **Step 4: Referenciar o módulo em `infra/terraform/main.tf`**

Adicionar acima de `data "aws_caller_identity" "current" {}`:

```hcl
module "network" {
  source       = "./modules/network"
  project_name = var.project_name
}
```

- [ ] **Step 5: `terraform init` e `terraform validate`**

Run (em `infra/terraform/`):
```bash
terraform init
terraform validate
```
Expected: `Success! The configuration is valid.`

- [ ] **Step 6: Commit**

```bash
git add infra/terraform/modules/network infra/terraform/main.tf
git commit -m "infra: add Terraform network module (VPC, subnets, NAT, security groups)"
```

---

## Task 7: Módulo Terraform `data` (RDS + S3 + Secrets Manager)

**Files:**
- Create: `infra/terraform/modules/data/main.tf`
- Create: `infra/terraform/modules/data/variables.tf`
- Create: `infra/terraform/modules/data/outputs.tf`
- Modify: `infra/terraform/main.tf`

**Interfaces:**
- Consumes: `module.network.vpc_id`, `module.network.private_subnet_ids`, `module.network.rds_security_group_id` (Task 6); `var.attachments_bucket_name`, `var.db_multi_az`, `var.app_key` (root).
- Produces: `module.data.db_endpoint`, `module.data.db_port`, `module.data.db_name`, `module.data.db_username`, `module.data.db_password_secret_arn`, `module.data.app_key_secret_arn`, `module.data.attachments_bucket_name`, `module.data.attachments_bucket_arn` — usados pela Task 9 (`ecs`).

- [ ] **Step 1: Criar `infra/terraform/modules/data/variables.tf`**

```hcl
variable "project_name" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "private_subnet_ids" {
  type = list(string)
}

variable "rds_security_group_id" {
  type = string
}

variable "db_name" {
  type    = string
  default = "itsm"
}

variable "db_username" {
  type    = string
  default = "itsm"
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.micro"
}

variable "db_allocated_storage" {
  type    = number
  default = 20
}

variable "db_multi_az" {
  type    = bool
  default = false
}

variable "attachments_bucket_name" {
  type = string
}

variable "app_key" {
  type      = string
  sensitive = true
}
```

- [ ] **Step 2: Criar `infra/terraform/modules/data/main.tf`**

```hcl
resource "random_password" "db" {
  length  = 32
  special = false
}

resource "aws_secretsmanager_secret" "db_password" {
  name = "${var.project_name}/db-password"
}

resource "aws_secretsmanager_secret_version" "db_password" {
  secret_id     = aws_secretsmanager_secret.db_password.id
  secret_string = random_password.db.result
}

resource "aws_secretsmanager_secret" "app_key" {
  name = "${var.project_name}/app-key"
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id     = aws_secretsmanager_secret.app_key.id
  secret_string = var.app_key
}

resource "aws_db_subnet_group" "this" {
  name       = "${var.project_name}-db"
  subnet_ids = var.private_subnet_ids

  tags = {
    Name = "${var.project_name}-db-subnet-group"
  }
}

resource "aws_db_instance" "this" {
  identifier                = "${var.project_name}-db"
  engine                    = "postgres"
  engine_version            = "16"
  instance_class            = var.db_instance_class
  allocated_storage         = var.db_allocated_storage
  db_name                   = var.db_name
  username                  = var.db_username
  password                  = random_password.db.result
  db_subnet_group_name      = aws_db_subnet_group.this.name
  vpc_security_group_ids    = [var.rds_security_group_id]
  multi_az                  = var.db_multi_az
  publicly_accessible       = false
  skip_final_snapshot       = false
  final_snapshot_identifier = "${var.project_name}-db-final"
  backup_retention_period   = 7
  storage_encrypted         = true

  tags = {
    Name = "${var.project_name}-db"
  }
}

resource "aws_s3_bucket" "attachments" {
  bucket = var.attachments_bucket_name

  tags = {
    Name = "${var.project_name}-attachments"
  }
}

resource "aws_s3_bucket_public_access_block" "attachments" {
  bucket = aws_s3_bucket.attachments.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "attachments" {
  bucket = aws_s3_bucket.attachments.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "attachments" {
  bucket = aws_s3_bucket.attachments.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}
```

- [ ] **Step 3: Criar `infra/terraform/modules/data/outputs.tf`**

```hcl
output "db_endpoint" {
  value = aws_db_instance.this.address
}

output "db_port" {
  value = aws_db_instance.this.port
}

output "db_name" {
  value = aws_db_instance.this.db_name
}

output "db_username" {
  value = aws_db_instance.this.username
}

output "db_password_secret_arn" {
  value = aws_secretsmanager_secret.db_password.arn
}

output "app_key_secret_arn" {
  value = aws_secretsmanager_secret.app_key.arn
}

output "attachments_bucket_name" {
  value = aws_s3_bucket.attachments.bucket
}

output "attachments_bucket_arn" {
  value = aws_s3_bucket.attachments.arn
}
```

- [ ] **Step 4: Referenciar o módulo em `infra/terraform/main.tf`**

Adicionar depois do `module "network"`:

```hcl
module "data" {
  source                  = "./modules/data"
  project_name            = var.project_name
  vpc_id                  = module.network.vpc_id
  private_subnet_ids      = module.network.private_subnet_ids
  rds_security_group_id   = module.network.rds_security_group_id
  attachments_bucket_name = var.attachments_bucket_name
  db_multi_az             = var.db_multi_az
  app_key                 = var.app_key
}
```

- [ ] **Step 5: `terraform init` e `terraform validate`**

Run (em `infra/terraform/`):
```bash
terraform init
terraform validate
```
Expected: `Success! The configuration is valid.`

- [ ] **Step 6: Commit**

```bash
git add infra/terraform/modules/data infra/terraform/main.tf
git commit -m "infra: add Terraform data module (RDS Postgres, S3 attachments bucket, secrets)"
```

---

## Task 8: Módulo Terraform `ecr`

**Files:**
- Create: `infra/terraform/modules/ecr/main.tf`
- Create: `infra/terraform/modules/ecr/variables.tf`
- Create: `infra/terraform/modules/ecr/outputs.tf`
- Modify: `infra/terraform/main.tf`

**Interfaces:**
- Consumes: `var.ecr_repository_name` (root).
- Produces: `module.ecr.repository_url`, `module.ecr.repository_name`, `module.ecr.repository_arn` — usados pelas Tasks 9 (`ecs`) e 10 (`cicd`).

- [ ] **Step 1: Criar `infra/terraform/modules/ecr/variables.tf`**

```hcl
variable "repository_name" {
  type = string
}
```

- [ ] **Step 2: Criar `infra/terraform/modules/ecr/main.tf`**

```hcl
resource "aws_ecr_repository" "this" {
  name                 = var.repository_name
  image_tag_mutability = "IMMUTABLE"

  image_scanning_configuration {
    scan_on_push = true
  }
}

resource "aws_ecr_lifecycle_policy" "this" {
  repository = aws_ecr_repository.this.name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Keep only the last 15 images"
        selection = {
          tagStatus   = "any"
          countType   = "imageCountMoreThan"
          countNumber = 15
        }
        action = {
          type = "expire"
        }
      }
    ]
  })
}
```

- [ ] **Step 3: Criar `infra/terraform/modules/ecr/outputs.tf`**

```hcl
output "repository_url" {
  value = aws_ecr_repository.this.repository_url
}

output "repository_name" {
  value = aws_ecr_repository.this.name
}

output "repository_arn" {
  value = aws_ecr_repository.this.arn
}
```

- [ ] **Step 4: Referenciar o módulo em `infra/terraform/main.tf`**

Adicionar depois do `module "data"`:

```hcl
module "ecr" {
  source          = "./modules/ecr"
  repository_name = var.ecr_repository_name
}
```

- [ ] **Step 5: `terraform init` e `terraform validate`**

Run (em `infra/terraform/`):
```bash
terraform init
terraform validate
```
Expected: `Success! The configuration is valid.`

- [ ] **Step 6: Commit**

```bash
git add infra/terraform/modules/ecr infra/terraform/main.tf
git commit -m "infra: add Terraform ECR module"
```

---

## Task 9: Módulo Terraform `ecs`

**Files:**
- Create: `infra/terraform/modules/ecs/main.tf`
- Create: `infra/terraform/modules/ecs/variables.tf`
- Create: `infra/terraform/modules/ecs/outputs.tf`
- Modify: `infra/terraform/main.tf`

**Interfaces:**
- Consumes: `module.network.{vpc_id,public_subnet_ids,private_subnet_ids,alb_security_group_id,ecs_tasks_security_group_id}` (Task 6); `module.data.{attachments_bucket_arn,db_password_secret_arn,app_key_secret_arn,db_endpoint,db_port,db_name,db_username,attachments_bucket_name}` (Task 7); `module.ecr.repository_url` (Task 8); `var.project_name`, `var.aws_region`, `var.domain_name`, `var.image_tag` (root).
- Produces: `module.ecs.{cluster_name,alb_dns_name,acm_certificate_arn,acm_validation_records,web_service_name,queue_service_name,web_task_family,queue_task_family,execution_role_arn,task_role_arn}` — usados pela Task 10 (`cicd`) e pelos outputs finais (Task 11).

- [ ] **Step 1: Criar `infra/terraform/modules/ecs/variables.tf`**

```hcl
variable "project_name" {
  type = string
}

variable "aws_region" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "private_subnet_ids" {
  type = list(string)
}

variable "alb_security_group_id" {
  type = string
}

variable "ecs_tasks_security_group_id" {
  type = string
}

variable "domain_name" {
  type = string
}

variable "ecr_repository_url" {
  type = string
}

variable "image_tag" {
  type = string
}

variable "attachments_bucket_arn" {
  type = string
}

variable "db_password_secret_arn" {
  type = string
}

variable "app_key_secret_arn" {
  type = string
}

variable "web_cpu" {
  type    = number
  default = 512
}

variable "web_memory" {
  type    = number
  default = 1024
}

variable "web_desired_count" {
  type    = number
  default = 1
}

variable "web_autoscaling_min" {
  type    = number
  default = 1
}

variable "web_autoscaling_max" {
  type    = number
  default = 3
}

variable "queue_cpu" {
  type    = number
  default = 256
}

variable "queue_memory" {
  type    = number
  default = 512
}

variable "queue_desired_count" {
  type    = number
  default = 1
}

variable "env_vars" {
  description = "Env vars não sensíveis, compartilhadas pelas tasks web e queue"
  type        = map(string)
}
```

- [ ] **Step 2: Criar `infra/terraform/modules/ecs/main.tf`**

```hcl
resource "aws_acm_certificate" "this" {
  domain_name       = var.domain_name
  validation_method = "DNS"

  lifecycle {
    create_before_destroy = true
  }

  tags = {
    Name = "${var.project_name}-cert"
  }
}

resource "aws_acm_certificate_validation" "this" {
  certificate_arn = aws_acm_certificate.this.arn

  timeouts {
    create = "45m"
  }
}

resource "aws_lb" "this" {
  name               = "${var.project_name}-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [var.alb_security_group_id]
  subnets            = var.public_subnet_ids
}

resource "aws_lb_target_group" "web" {
  name        = "${var.project_name}-web"
  port        = 8000
  protocol    = "HTTP"
  vpc_id      = var.vpc_id
  target_type = "ip"

  health_check {
    path                = "/up"
    healthy_threshold   = 2
    unhealthy_threshold = 3
    interval            = 30
    timeout             = 5
    matcher             = "200"
  }
}

resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.this.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = "redirect"

    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.this.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = aws_acm_certificate_validation.this.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.web.arn
  }
}

resource "aws_ecs_cluster" "this" {
  name = "${var.project_name}-cluster"
}

resource "aws_cloudwatch_log_group" "web" {
  name              = "/ecs/${var.project_name}/web"
  retention_in_days = 30
}

resource "aws_cloudwatch_log_group" "queue" {
  name              = "/ecs/${var.project_name}/queue"
  retention_in_days = 30
}

data "aws_iam_policy_document" "ecs_assume_role" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "execution" {
  name               = "${var.project_name}-ecs-execution"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume_role.json
}

resource "aws_iam_role_policy_attachment" "execution_managed" {
  role       = aws_iam_role.execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

data "aws_iam_policy_document" "execution_secrets" {
  statement {
    actions   = ["secretsmanager:GetSecretValue"]
    resources = [var.db_password_secret_arn, var.app_key_secret_arn]
  }
}

resource "aws_iam_role_policy" "execution_secrets" {
  name   = "${var.project_name}-ecs-execution-secrets"
  role   = aws_iam_role.execution.id
  policy = data.aws_iam_policy_document.execution_secrets.json
}

resource "aws_iam_role" "task" {
  name               = "${var.project_name}-ecs-task"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume_role.json
}

data "aws_iam_policy_document" "task_s3" {
  statement {
    actions   = ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"]
    resources = ["${var.attachments_bucket_arn}/*"]
  }
}

resource "aws_iam_role_policy" "task_s3" {
  name   = "${var.project_name}-ecs-task-s3"
  role   = aws_iam_role.task.id
  policy = data.aws_iam_policy_document.task_s3.json
}

locals {
  common_secrets = [
    {
      name      = "DB_PASSWORD"
      valueFrom = var.db_password_secret_arn
    },
    {
      name      = "APP_KEY"
      valueFrom = var.app_key_secret_arn
    },
  ]

  common_environment = [for k, v in var.env_vars : { name = k, value = v }]
}

resource "aws_ecs_task_definition" "web" {
  family                   = "${var.project_name}-web"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.web_cpu
  memory                   = var.web_memory
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name      = "web"
      image     = "${var.ecr_repository_url}:${var.image_tag}"
      essential = true
      portMappings = [
        { containerPort = 8000, protocol = "tcp" }
      ]
      environment = local.common_environment
      secrets     = local.common_secrets
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.web.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "web"
        }
      }
    }
  ])
}

resource "aws_ecs_task_definition" "queue" {
  family                   = "${var.project_name}-queue"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.queue_cpu
  memory                   = var.queue_memory
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name      = "queue"
      image     = "${var.ecr_repository_url}:${var.image_tag}"
      essential = true
      command   = ["php", "artisan", "queue:work", "--tries=3", "--max-time=3600"]
      environment = local.common_environment
      secrets     = local.common_secrets
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.queue.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "queue"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "web" {
  name            = "${var.project_name}-web"
  cluster         = aws_ecs_cluster.this.id
  task_definition = aws_ecs_task_definition.web.arn
  desired_count   = var.web_desired_count
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = var.private_subnet_ids
    security_groups  = [var.ecs_tasks_security_group_id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.web.arn
    container_name   = "web"
    container_port   = 8000
  }

  depends_on = [aws_lb_listener.https]

  lifecycle {
    ignore_changes = [task_definition]
  }
}

resource "aws_ecs_service" "queue" {
  name            = "${var.project_name}-queue"
  cluster         = aws_ecs_cluster.this.id
  task_definition = aws_ecs_task_definition.queue.arn
  desired_count   = var.queue_desired_count
  launch_type     = "FARGATE"

  network_configuration {
    subnets          = var.private_subnet_ids
    security_groups  = [var.ecs_tasks_security_group_id]
    assign_public_ip = false
  }

  lifecycle {
    ignore_changes = [task_definition]
  }
}

resource "aws_appautoscaling_target" "web" {
  max_capacity       = var.web_autoscaling_max
  min_capacity       = var.web_autoscaling_min
  resource_id        = "service/${aws_ecs_cluster.this.name}/${aws_ecs_service.web.name}"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

resource "aws_appautoscaling_policy" "web_cpu" {
  name               = "${var.project_name}-web-cpu"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.web.resource_id
  scalable_dimension = aws_appautoscaling_target.web.scalable_dimension
  service_namespace  = aws_appautoscaling_target.web.service_namespace

  target_tracking_scaling_policy_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    target_value       = 70
    scale_in_cooldown  = 120
    scale_out_cooldown = 60
  }
}
```

- [ ] **Step 3: Criar `infra/terraform/modules/ecs/outputs.tf`**

```hcl
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
```

- [ ] **Step 4: Referenciar o módulo em `infra/terraform/main.tf`**

Adicionar depois do `module "ecr"` (e antes de `data "aws_caller_identity" "current" {}`, que já existe do bootstrap):

```hcl
locals {
  ecs_env_vars = {
    APP_NAME                    = "ITSM"
    APP_ENV                     = "production"
    APP_DEBUG                   = "false"
    APP_URL                     = "https://${var.domain_name}"
    APP_LOCALE                  = "pt_BR"
    APP_FALLBACK_LOCALE         = "en"
    LOG_CHANNEL                 = "stack"
    LOG_LEVEL                   = "error"
    BCRYPT_ROUNDS               = "12"
    DB_CONNECTION               = "pgsql"
    DB_HOST                     = module.data.db_endpoint
    DB_PORT                     = tostring(module.data.db_port)
    DB_DATABASE                 = module.data.db_name
    DB_USERNAME                 = module.data.db_username
    SESSION_DRIVER               = "database"
    SESSION_LIFETIME             = "120"
    QUEUE_CONNECTION             = "database"
    CACHE_STORE                  = "database"
    FILESYSTEM_DISK               = "s3"
    AWS_DEFAULT_REGION            = var.aws_region
    AWS_BUCKET                    = module.data.attachments_bucket_name
    AWS_USE_PATH_STYLE_ENDPOINT   = "false"
    MAIL_MAILER                   = "log"
    OCTANE_SERVER                 = "frankenphp"
  }
}

module "ecs" {
  source                       = "./modules/ecs"
  project_name                 = var.project_name
  aws_region                   = var.aws_region
  vpc_id                       = module.network.vpc_id
  public_subnet_ids            = module.network.public_subnet_ids
  private_subnet_ids           = module.network.private_subnet_ids
  alb_security_group_id        = module.network.alb_security_group_id
  ecs_tasks_security_group_id  = module.network.ecs_tasks_security_group_id
  domain_name                  = var.domain_name
  ecr_repository_url           = module.ecr.repository_url
  image_tag                    = var.image_tag
  attachments_bucket_arn       = module.data.attachments_bucket_arn
  db_password_secret_arn       = module.data.db_password_secret_arn
  app_key_secret_arn           = module.data.app_key_secret_arn
  env_vars                     = local.ecs_env_vars
}
```

- [ ] **Step 5: `terraform init` e `terraform validate`**

Run (em `infra/terraform/`):
```bash
terraform init
terraform validate
```
Expected: `Success! The configuration is valid.`

- [ ] **Step 6: Commit**

```bash
git add infra/terraform/modules/ecs infra/terraform/main.tf
git commit -m "infra: add Terraform ECS module (cluster, ALB, task defs, services, autoscaling)"
```

---

## Task 10: Módulo Terraform `cicd` (GitHub OIDC)

**Files:**
- Create: `infra/terraform/modules/cicd/main.tf`
- Create: `infra/terraform/modules/cicd/variables.tf`
- Create: `infra/terraform/modules/cicd/outputs.tf`
- Modify: `infra/terraform/main.tf`

**Interfaces:**
- Consumes: `var.project_name`, `var.github_repository` (root); `module.ecr.repository_arn` (Task 8); `module.ecs.{cluster_name,web_service_name,queue_service_name,execution_role_arn,task_role_arn}` (Task 9); `data.aws_caller_identity.current.account_id` (bootstrap, Task 5).
- Produces: `module.cicd.github_actions_role_arn` — vira o secret `AWS_GITHUB_ACTIONS_ROLE_ARN` do GitHub Actions (Task 12).

- [ ] **Step 1: Criar `infra/terraform/modules/cicd/variables.tf`**

```hcl
variable "project_name" {
  type = string
}

variable "github_repository" {
  description = "Formato \"owner/repo\""
  type        = string
}

variable "ecr_repository_arn" {
  type = string
}

variable "ecs_cluster_arn" {
  type = string
}

variable "web_service_arn" {
  type = string
}

variable "queue_service_arn" {
  type = string
}

variable "execution_role_arn" {
  type = string
}

variable "task_role_arn" {
  type = string
}
```

- [ ] **Step 2: Criar `infra/terraform/modules/cicd/main.tf`**

```hcl
data "tls_certificate" "github" {
  url = "https://token.actions.githubusercontent.com/.well-known/openid-configuration"
}

resource "aws_iam_openid_connect_provider" "github" {
  url             = "https://token.actions.githubusercontent.com"
  client_id_list  = ["sts.amazonaws.com"]
  thumbprint_list = [data.tls_certificate.github.certificates[0].sha1_fingerprint]
}

data "aws_iam_policy_document" "github_assume" {
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github.arn]
    }

    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }

    condition {
      test     = "StringLike"
      variable = "token.actions.githubusercontent.com:sub"
      values   = ["repo:${var.github_repository}:ref:refs/heads/main"]
    }
  }
}

resource "aws_iam_role" "github_actions" {
  name               = "${var.project_name}-github-actions"
  assume_role_policy = data.aws_iam_policy_document.github_assume.json
}

data "aws_iam_policy_document" "github_actions" {
  statement {
    sid       = "EcrAuth"
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"]
  }

  statement {
    sid = "EcrPush"
    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:PutImage",
      "ecr:InitiateLayerUpload",
      "ecr:UploadLayerPart",
      "ecr:CompleteLayerUpload",
      "ecr:BatchGetImage",
      "ecr:GetDownloadUrlForLayer",
    ]
    resources = [var.ecr_repository_arn]
  }

  statement {
    sid       = "EcsRegisterTaskDefinition"
    actions   = ["ecs:RegisterTaskDefinition", "ecs:DescribeTaskDefinition"]
    resources = ["*"]
  }

  statement {
    sid       = "EcsRunTask"
    actions   = ["ecs:RunTask"]
    resources = ["arn:aws:ecs:*:*:task-definition/${var.project_name}-web:*"]

    condition {
      test     = "ArnEquals"
      variable = "ecs:cluster"
      values   = [var.ecs_cluster_arn]
    }
  }

  statement {
    sid       = "EcsUpdateService"
    actions   = ["ecs:UpdateService", "ecs:DescribeServices", "ecs:DescribeTasks"]
    resources = [var.web_service_arn, var.queue_service_arn]
  }

  statement {
    sid       = "PassRoles"
    actions   = ["iam:PassRole"]
    resources = [var.execution_role_arn, var.task_role_arn]
  }
}

resource "aws_iam_role_policy" "github_actions" {
  name   = "${var.project_name}-github-actions"
  role   = aws_iam_role.github_actions.id
  policy = data.aws_iam_policy_document.github_actions.json
}
```

- [ ] **Step 3: Criar `infra/terraform/modules/cicd/outputs.tf`**

```hcl
output "github_actions_role_arn" {
  value = aws_iam_role.github_actions.arn
}
```

- [ ] **Step 4: Referenciar o módulo em `infra/terraform/main.tf`**

Adicionar depois do `module "ecs"`:

```hcl
module "cicd" {
  source             = "./modules/cicd"
  project_name       = var.project_name
  github_repository  = var.github_repository
  ecr_repository_arn = module.ecr.repository_arn
  ecs_cluster_arn    = "arn:aws:ecs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:cluster/${module.ecs.cluster_name}"
  web_service_arn    = "arn:aws:ecs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:service/${module.ecs.cluster_name}/${module.ecs.web_service_name}"
  queue_service_arn  = "arn:aws:ecs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:service/${module.ecs.cluster_name}/${module.ecs.queue_service_name}"
  execution_role_arn = module.ecs.execution_role_arn
  task_role_arn      = module.ecs.task_role_arn
}
```

- [ ] **Step 5: `terraform init` e `terraform validate`**

Run (em `infra/terraform/`):
```bash
terraform init
terraform validate
```
Expected: `Success! The configuration is valid.`

- [ ] **Step 6: Commit**

```bash
git add infra/terraform/modules/cicd infra/terraform/main.tf
git commit -m "infra: add Terraform cicd module (GitHub Actions OIDC role)"
```

---

## Task 11: Outputs finais do root

**Files:**
- Modify: `infra/terraform/outputs.tf`

**Interfaces:**
- Consumes: todos os outputs dos módulos `network`, `data`, `ecr`, `ecs`, `cicd` (Tasks 6–10).
- Produces: outputs de linha de comando (`terraform output`) usados na Task 13 pra configurar DNS, GitHub Secrets e confirmar a infra.

- [ ] **Step 1: Escrever `infra/terraform/outputs.tf`**

```hcl
output "alb_dns_name" {
  value = module.ecs.alb_dns_name
}

output "acm_validation_records" {
  value = module.ecs.acm_validation_records
}

output "ecr_repository_url" {
  value = module.ecr.repository_url
}

output "attachments_bucket_name" {
  value = module.data.attachments_bucket_name
}

output "db_endpoint" {
  value = module.data.db_endpoint
}

output "private_subnet_ids" {
  value = module.network.private_subnet_ids
}

output "ecs_tasks_security_group_id" {
  value = module.network.ecs_tasks_security_group_id
}

output "github_actions_role_arn" {
  value = module.cicd.github_actions_role_arn
}
```

- [ ] **Step 2: `terraform init`, `terraform validate` e `terraform fmt -check` em toda a configuração**

Run (em `infra/terraform/`):
```bash
terraform init
terraform fmt -recursive
terraform validate
```
Expected: `fmt` não deve precisar reescrever nada relevante (ou reescreve espaçamento — nesse caso revisar o diff antes de commitar); `validate` responde `Success! The configuration is valid.`

- [ ] **Step 3: Commit**

```bash
git add infra/terraform/outputs.tf
git commit -m "infra: expose root Terraform outputs for rollout and GitHub Secrets setup"
```

---

## Task 12: Workflow do GitHub Actions

**Files:**
- Create: `.github/workflows/deploy.yml`

**Interfaces:**
- Consumes: imagem buildável via `docker/php/Dockerfile.prod` (Task 4); nomes de cluster/services/task families definidos no módulo `ecs` (Task 9) — `gespriority-itsm-cluster`, `gespriority-itsm-web`, `gespriority-itsm-queue`; secrets do repositório GitHub `AWS_GITHUB_ACTIONS_ROLE_ARN`, `PRIVATE_SUBNET_IDS`, `ECS_TASKS_SECURITY_GROUP_ID` (configurados manualmente na Task 13, a partir dos outputs da Task 11).
- Produces: pipeline de deploy contínuo — não é consumido por nenhuma task seguinte, é o fim da cadeia de automação.

- [ ] **Step 1: Criar `.github/workflows/deploy.yml`**

```yaml
name: Deploy

on:
  push:
    branches: [main]

permissions:
  id-token: write
  contents: read

env:
  AWS_REGION: us-east-1
  ECR_REPOSITORY: gespriority-itsm
  ECS_CLUSTER: gespriority-itsm-cluster
  WEB_SERVICE: gespriority-itsm-web
  QUEUE_SERVICE: gespriority-itsm-queue
  WEB_TASK_FAMILY: gespriority-itsm-web
  QUEUE_TASK_FAMILY: gespriority-itsm-queue

jobs:
  build-and-deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Configure AWS credentials
        uses: aws-actions/configure-aws-credentials@v4
        with:
          role-to-assume: ${{ secrets.AWS_GITHUB_ACTIONS_ROLE_ARN }}
          aws-region: ${{ env.AWS_REGION }}

      - name: Login to Amazon ECR
        id: ecr-login
        uses: aws-actions/amazon-ecr-login@v2

      - name: Build and push image
        id: build-image
        env:
          ECR_REGISTRY: ${{ steps.ecr-login.outputs.registry }}
          IMAGE_TAG: ${{ github.sha }}
        run: |
          docker build -f docker/php/Dockerfile.prod -t "$ECR_REGISTRY/$ECR_REPOSITORY:$IMAGE_TAG" .
          docker push "$ECR_REGISTRY/$ECR_REPOSITORY:$IMAGE_TAG"
          echo "image=$ECR_REGISTRY/$ECR_REPOSITORY:$IMAGE_TAG" >> "$GITHUB_OUTPUT"

      - name: Download current web task definition
        run: |
          aws ecs describe-task-definition --task-definition "$WEB_TASK_FAMILY" \
            --query 'taskDefinition' > web-task-def.json

      - name: Download current queue task definition
        run: |
          aws ecs describe-task-definition --task-definition "$QUEUE_TASK_FAMILY" \
            --query 'taskDefinition' > queue-task-def.json

      - name: Render new web task definition
        id: render-web
        uses: aws-actions/amazon-ecs-render-task-definition@v1
        with:
          task-definition: web-task-def.json
          container-name: web
          image: ${{ steps.build-image.outputs.image }}

      - name: Render new queue task definition
        id: render-queue
        uses: aws-actions/amazon-ecs-render-task-definition@v1
        with:
          task-definition: queue-task-def.json
          container-name: queue
          image: ${{ steps.build-image.outputs.image }}

      - name: Register web task definition
        id: register-web
        run: |
          ARN=$(aws ecs register-task-definition \
            --cli-input-json "file://${{ steps.render-web.outputs.task-definition }}" \
            --query 'taskDefinition.taskDefinitionArn' --output text)
          echo "arn=$ARN" >> "$GITHUB_OUTPUT"

      - name: Run database migration
        run: |
          TASK_ARN=$(aws ecs run-task \
            --cluster "$ECS_CLUSTER" \
            --launch-type FARGATE \
            --task-definition "${{ steps.register-web.outputs.arn }}" \
            --overrides '{"containerOverrides":[{"name":"web","command":["php","artisan","migrate","--force"]}]}' \
            --network-configuration "awsvpcConfiguration={subnets=[${{ secrets.PRIVATE_SUBNET_IDS }}],securityGroups=[${{ secrets.ECS_TASKS_SECURITY_GROUP_ID }}],assignPublicIp=DISABLED}" \
            --query 'tasks[0].taskArn' --output text)

          aws ecs wait tasks-stopped --cluster "$ECS_CLUSTER" --tasks "$TASK_ARN"

          EXIT_CODE=$(aws ecs describe-tasks --cluster "$ECS_CLUSTER" --tasks "$TASK_ARN" \
            --query 'tasks[0].containers[0].exitCode' --output text)

          if [ "$EXIT_CODE" != "0" ]; then
            echo "Migration failed with exit code $EXIT_CODE"
            exit 1
          fi

      - name: Register queue task definition
        id: register-queue
        run: |
          ARN=$(aws ecs register-task-definition \
            --cli-input-json "file://${{ steps.render-queue.outputs.task-definition }}" \
            --query 'taskDefinition.taskDefinitionArn' --output text)
          echo "arn=$ARN" >> "$GITHUB_OUTPUT"

      - name: Deploy web service
        run: |
          aws ecs update-service --cluster "$ECS_CLUSTER" --service "$WEB_SERVICE" \
            --task-definition "${{ steps.register-web.outputs.arn }}" --force-new-deployment

      - name: Deploy queue service
        run: |
          aws ecs update-service --cluster "$ECS_CLUSTER" --service "$QUEUE_SERVICE" \
            --task-definition "${{ steps.register-queue.outputs.arn }}" --force-new-deployment

      - name: Wait for services to stabilize
        run: |
          aws ecs wait services-stable --cluster "$ECS_CLUSTER" --services "$WEB_SERVICE" "$QUEUE_SERVICE"
```

- [ ] **Step 2: Validar a sintaxe YAML**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml')); print('YAML válido')"`
Expected: imprime `YAML válido`, sem exceção.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "ci: add GitHub Actions workflow to build, migrate, and deploy to ECS"
```

---

## Task 13: Rollout manual (primeira aplicação)

**Files:**
- Create: `docs/deploy/aws-rollout.md`

**Interfaces:**
- Consumes: todas as tasks anteriores (1–12).
- Produces: nada consumido por código — é o checklist que a pessoa executando o plano segue manualmente com credenciais AWS reais e acesso ao DNS de `gespriority.com.br`. Nenhum executor automatizado (subagent) consegue completar esta task sozinho, porque exige segredos e acesso externo que não existem neste ambiente.

- [ ] **Step 1: Escrever o checklist de rollout**

`docs/deploy/aws-rollout.md`:

```markdown
# Rollout de produção — AWS ECS Fargate

Checklist pra primeira subida da infra. Exige credenciais AWS reais
configuradas (`aws configure` ou variáveis de ambiente) e acesso ao
painel de DNS de `gespriority.com.br`.

1. **Aplicar rede, banco e ECR primeiro** (a task definition do ECS
   referencia uma imagem que ainda não existe — precisa existir antes
   do apply completo):

   ```bash
   cd infra/terraform
   terraform apply -target=module.network -target=module.data -target=module.ecr \
     -var="app_key=$(cd ../.. && php artisan key:generate --show)"
   ```

2. **Buildar e publicar a primeira imagem** (tag `bootstrap`, a mesma
   do default de `var.image_tag`):

   ```bash
   cd ../..
   aws ecr get-login-password --region us-east-1 | \
     docker login --username AWS --password-stdin "$(terraform -chdir=infra/terraform output -raw ecr_repository_url | cut -d/ -f1)"
   docker build -f docker/php/Dockerfile.prod -t "$(terraform -chdir=infra/terraform output -raw ecr_repository_url):bootstrap" .
   docker push "$(terraform -chdir=infra/terraform output -raw ecr_repository_url):bootstrap"
   ```

3. **Aplicar o resto da infra** (cria ECS, ALB, certificado ACM, role
   do GitHub Actions):

   ```bash
   cd infra/terraform
   terraform apply -var="app_key=<mesmo APP_KEY do passo 1>"
   ```

   O apply vai pausar em `aws_acm_certificate_validation` esperando o
   certificado validar. Nesse momento, rodar `terraform output
   acm_validation_records` em outro terminal e cadastrar o(s) registro(s)
   CNAME retornado(s) no DNS atual de `gespriority.com.br` (fora da
   Route53). Depois que o DNS propagar (minutos a poucas horas,
   dependendo do TTL), o apply retoma sozinho.

4. **Configurar os GitHub Secrets** do repositório
   `domlemos/gespriority_claude` (Settings → Secrets and variables →
   Actions), usando os outputs do Terraform:

   ```bash
   terraform -chdir=infra/terraform output -raw github_actions_role_arn
   terraform -chdir=infra/terraform output -json private_subnet_ids
   terraform -chdir=infra/terraform output -raw ecs_tasks_security_group_id
   ```

   - `AWS_GITHUB_ACTIONS_ROLE_ARN` = o ARN da role.
   - `PRIVATE_SUBNET_IDS` = as subnet ids separadas por vírgula, sem
     colchetes nem aspas (ex.: `subnet-abc123,subnet-def456`).
   - `ECS_TASKS_SECURITY_GROUP_ID` = o id do security group.

5. **Apontar o DNS de produção**: criar um registro `CNAME` (ou `ALIAS`,
   se o provedor de DNS suportar) de `gespriority.com.br` pro valor de
   `terraform -chdir=infra/terraform output -raw alb_dns_name`.

6. **Validar a aplicação antes de depender do pipeline**: rodar
   `curl -I https://gespriority.com.br/up` e confirmar `200 OK`. Testar
   upload/download/delete de um anexo real (rota
   `/api/incidentes/{id}/anexos`) pra confirmar que o S3 está
   funcionando fim a fim.

7. **Disparar o pipeline de verdade**: dar `git push` na `main` (mesmo
   que um commit trivial) e acompanhar a run do workflow `deploy.yml`
   no GitHub Actions até `Wait for services to stabilize` passar.

8. **Rollback, se precisar**: reverter é apontar o service de volta pra
   revisão anterior da task definition:

   ```bash
   aws ecs update-service --cluster gespriority-itsm-cluster --service gespriority-itsm-web \
     --task-definition gespriority-itsm-web:<revisão anterior>
   ```
```

- [ ] **Step 2: Commit**

```bash
git add docs/deploy/aws-rollout.md
git commit -m "docs: add manual AWS ECS Fargate rollout checklist"
```
