# 📑 ESPECIFICAÇÃO TÉCNICA DA API - ITSM (MÓDULO DE AUTENTICAÇÃO)

Este documento serve como a única fonte de verdade para o desenvolvimento do ecossistema Backend do ITSM. Todas as camadas devem ser desenvolvidas de forma estrita, organizada por domínios e sem acoplamento.

---

## 🛠️ 1. REQUISITOS NÃO FUNCIONAIS (Infraestrutura com FrankenPHP)

### 🐳 1.1. Arquitetura de Servidor de Aplicação Único (FrankenPHP + Octane)
O ambiente local não utilizará o combo tradicional Nginx + PHP-FPM. Toda a orquestração HTTP e interpretador PHP será centralizada no **FrankenPHP**, gerenciado pelo **Laravel Octane**:
- [x] **Servidor de Aplicação (FrankenPHP):** Imagem base `dunglas/frankenphp:1.x-alpine` (versão fixada explicitamente — nunca `latest`, para garantir builds reprodutíveis entre ambientes/desenvolvedores). Escutando na porta `8000` da máquina local.
  > 📌 **Implementado com `1.12.4-php8.3-alpine`** (não `1.3-php8.3-alpine` como no rascunho original): o Laravel Octane 2.x exige FrankenPHP `>= 1.5.0` (checado em runtime via `frankenphp build-info`); a tag `1.3.x` causa erro fatal na subida do worker. Ver seção 3.6.
- [x] **Extensões PHP:** `pdo`, `pdo_pgsql`, `intl` instaladas via `docker-php-ext-install` (a `intl` requer `icu-dev` como dependência de build no Alpine).
  > 📌 Também foi necessário adicionar `pcntl` (não prevista neste rascunho) — o Octane usa as constantes `SIGINT`/`SIGTERM`/`SIGHUP` para tratar sinais do worker, e sem essa extensão o processo falha ao iniciar. Ver seção 3.6.
- [x] **Pacotes de sistema (não são extensões PHP):** `shadow` (ferramentas `usermod`/`groupmod`, usadas na seção 1.2) e `bash` (ver 1.2) instalados via `apk add`.
- [x] **Laravel Octane obrigatório:** o worker mode do FrankenPHP **não** deve ser configurado apontando diretamente para `public/index.php`. Laravel não reseta estado (bindings singleton, cache de config, containers de serviço) entre requisições sozinho — isso é responsabilidade do Octane. Instalar via `composer require laravel/octane` e `php artisan octane:install --server=frankenphp`.
- [x] **Comando de inicialização (entrypoint do container):**
  ```
  php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --max-requests=1000
  ```
  O parâmetro `--max-requests=1000` substitui a antiga variável de ambiente `MAX_REQUESTS` — é o próprio Octane quem recicla o worker de forma limpa a cada mil requisições, garantindo que o reset de estado aconteça corretamente junto com o reciclo do processo.

  > 📌 **Atenção ao editar rotas/config/providers:** o worker do Octane mantém o código carregado em memória entre requisições — alterações em `routes/*.php`, `config/*.php` ou providers só surtem efeito após `docker compose restart app` (ou `octane:reload`). Isso mordeu o desenvolvimento algumas vezes (rota 404 mesmo após `route:list` mostrar a rota registrada).
- [x] **Banco de Dados (PostgreSQL):** Versão `postgres:15-alpine`. Persistido em volume nomeado (`dados_postgres`). Porta `5432` exposta ao host **apenas em ambiente local/dev** (para acesso via DBeaver/TablePlus/etc.) — não deve ser exposta em staging/produção.
- [x] **Isolamento de Rede:** Os serviços se comunicarão exclusivamente através de uma rede interna bridge chamada `rede-itsm`.

### 🔑 1.2. Sincronização de Permissões (UID/GID) e Acesso Manual ao Container
- [x] O arquivo `docker/php/Dockerfile` do FrankenPHP deve aceitar os argumentos `USER_ID` e `GROUP_ID` (padrão `1000`) para remapear o usuário interno da aplicação com o usuário host da máquina Linux, via `usermod`/`groupmod` (pacote `shadow`). Isso garante escrita e leitura simultânea entre máquina local e container, sem bloqueios de permissão de arquivos (ex: `composer install`, `artisan make:*`, cache/log gerados pelo container continuam editáveis/deletáveis pelo usuário do host).
  > 📌 A imagem base não inclui `composer` — foi adicionado via `COPY --from=composer:2 /usr/bin/composer /usr/bin/composer` no Dockerfile para que `composer install`/`require` funcionem dentro do container, como este item pressupõe. Também foi preciso `chown` as pastas `/config` e `/data` (XDG dirs usados por `php artisan tinker`/psysh) para o usuário `www-data` remapeado — sem isso, `artisan tinker` falha com erro de permissão. Ver seção 3.6.
- [x] **Shell interativo (`bash`) disponível no container:** a imagem `alpine` não inclui `bash` por padrão (só `sh`/`ash`) — deve ser instalado explicitamente via `apk add bash` no Dockerfile, para permitir acesso manual ao container quando necessário (debug, execução avulsa de comandos, inspeção de arquivos):
  ```
  docker compose exec app bash
  ```
  Esse acesso deve respeitar o mesmo remapeamento de `USER_ID`/`GROUP_ID` acima — ou seja, comandos executados manualmente dentro do container (ex: `composer require`, `artisan make:model`) geram arquivos com o mesmo dono do usuário do host, mantendo a leitura/escrita bidirecional sem necessidade de `sudo`/`chown` posterior.

### 🛡️ 1.3. Segurança e Sessões (Sanctum Convencional)
> ℹ️ Todo o modelo de dados (colunas, tipos, constraints), configuração exata e payloads dos endpoints abaixo estão documentados em detalhe na **seção 3**.

- [x] **Autenticação Local:** O login será resolvido inteiramente pela aplicação, sem dependência de Identity Provider externo. Credenciais (e-mail + senha) são validadas contra a tabela `users` do Postgres.
- [x] **Hash de Senha:** Senhas armazenadas com `bcrypt` (padrão do Laravel, `Hash::make`), nunca em texto plano.
- [x] **Emissão de Token (Laravel Sanctum — modo Bearer, Vue e API em domínios distintos):**
  - **Tipo:** *personal access tokens* (`personal_access_tokens`), enviados pelo Vue via header `Authorization: Bearer {token}`. Nunca em query string.
  - **Hash no banco:** comportamento padrão do Sanctum — o token em texto plano é retornado uma única vez na criação; no banco fica só o hash (SHA-256). Não requer configuração adicional, mas deve ser validado em code review (nenhum log/observer deve persistir o token cru).
  - **Expiração obrigatória:** Sanctum **não expira tokens por padrão**.
    > 📌 **Ajuste de implementação:** `Sanctum::personalAccessTokenExpiration()`/`config('sanctum.expiration')` é um valor **global**, compartilhado por todos os guards — não é possível usá-lo para diferenciar 2h (staff) de 4h (customer). Em vez disso, o TTL é aplicado **por token, no momento da criação**, passando `$expiresAt` explícito para `createToken()`. `config('sanctum.expiration')` permanece `null`. Ver seção 3.3.
  - **Estratégia de renovação (sem refresh token nativo):** endpoint `POST /api/refresh` — autenticado com o token atual (ainda válido), emite um novo token e revoga o anterior de forma atômica (sliding window). O front deve chamar esse endpoint proativamente antes da expiração (ex: aos 80% do TTL).
  - **Abilities/scopes por token:** `createToken('spa', ['staff'])` ou `['customer']` conforme o guard, permitindo checagem adicional via `tokenCan()` em rotas sensíveis, além do guard em si.
  - **Revogação:**
    - `POST /api/logout` → revoga apenas o token da sessão atual (`currentAccessToken()->delete()`).
    - `POST /api/logout-all` → revoga todos os tokens do usuário (uso: "sair de todos os dispositivos").
    - Troca de senha deve revogar automaticamente todos os tokens existentes do usuário.
  - **Armazenamento no client (Vue):** preferencialmente em memória (store Pinia, não persistido em disco), minimizando exposição a XSS. Se for necessário persistir entre reloads de página, usar `sessionStorage` (nunca `localStorage`), sabendo que ainda há exposição residual a XSS — reforça a necessidade de CSP estrita no front.
  - **CORS:** whitelist estrita por domínio explícito do Vue (sem wildcard `*`), `Access-Control-Allow-Credentials` desabilitado (não há cookie de sessão nesse modo).
  - **Transporte:** HTTPS obrigatório em todos os ambientes, incluindo staging.
  - **Auditoria:** log de emissão e revogação de tokens (IP, user-agent, timestamp) para detecção de uso anômalo/roubo de token.
  - **Rate limiting:** throttle dedicado em `/api/login`, `/api/refresh` e demais endpoints sensíveis (ex: 5 tentativas/minuto por IP+e-mail).
- [x] **Endpoints de Autenticação:**
  - `POST /api/login` (+ `POST /api/customer/login`) — valida credenciais e retorna token Bearer (não há modo cookie/sessão neste projeto — ver seção 3.4).
  - `POST /api/logout` — revoga o token atual (`currentAccessToken()->delete()`).
  - `POST /api/logout-all` — revoga todos os tokens do usuário/cliente autenticado.
  - `POST /api/refresh` — rotação atômica de token.
  - `GET /api/me` — retorna o usuário/cliente autenticado (com roles/permissions carregadas, quando aplicável).
  - `POST /api/forgot-password` / `POST /api/reset-password` (+ variantes `/api/customer/forgot-password` e `/api/customer/reset-password`) — fluxo padrão do Laravel (`Password` facade + `notifiable`), com e-mail de recuperação.
- [x] **Rate Limiting:** rota de login protegida por throttle (`throttle:login`, ex. 5 tentativas/minuto por IP + e-mail) para mitigar brute-force.
- [x] **Guards Separados:** dois guards distintos para diferenciar perfis:
  - `web` → colaboradores internos (agentes, supervisores, administradores).
  - `customer` → clientes que abrem chamados no portal externo.
  Cada guard aponta para seu próprio model (`User` e `Customer`) e tabela, evitando mistura de regras de negócio e campos entre os dois perfis.
- [x] **Autorização (Roles/Permissions próprios + Gates/Policies):**
  - **Motivo da escolha:** `spatie/laravel-permission` usa um singleton (`PermissionRegistrar`) que mantém permissions em memória de instância. Em modo worker (FrankenPHP), esse singleton sobrevive entre requisições, causando inconsistência entre atualização de permissão e efeito real (a mudança só se reflete após reciclagem do worker). Para evitar essa classe de bug, o projeto implementa um mecanismo próprio, mais simples, sem estado persistente no container de serviços.
  - **Modelagem:**
    ```
    roles              (id, name, slug)
    permissions        (id, name, slug)
    permission_role    (role_id, permission_id)
    role_user          (user_id, role_id)
    ```
    > Colunas completas de cada tabela na seção 3.1.
  - **Model `User`:** método `hasPermission(string $slug): bool` resolvendo via `loadMissing('roles.permissions')` — escopado à instância do model (recriada a cada request), sem risco de staleness entre requisições do worker.
  - **`AuthServiceProvider`:** `Gate::before()` verificando `hasPermission()` antes de cair nas Policies, permitindo checagem simples de permissão nomeada (`Gate::allows('tickets.assign')`) em qualquer ponto da aplicação.
  - **Papéis previstos:** Admin, Agente, Supervisor — só esses três. **Não existe role "Cliente" no RBAC** (rascunho inicial desta spec listava uma, mas foi removida: ver nota abaixo).
    > 📌 `Customer` (guard `customer`) **não** participa do sistema de roles/permissions de forma alguma — não tem `hasPermission()`, não tem relação com `roles`, e a tabela `roles` não tem nenhuma entrada para "cliente". Quem abre chamado é sempre o model `Customer`, nunca um `User` — então uma role "Cliente" no RBAC do `User` não representaria nenhum ator real (ela existiu numa versão anterior desta implementação só como seed vestigial, e foi removida por gerar confusão: um usuário fictício "cliente interno" sem contrapartida no mundo real). A autorização real de clientes é feita por Policies checando propriedade do recurso (ex: `Ticket::customer_id === $customer->id`), não por permission slug.
  - **Autorização por recurso (Policies):** regras de acesso a nível de instância que dependem do dado, não só do papel — ex: cliente só visualiza/edita os próprios chamados; agente só vê chamados do seu time. Implementado via Laravel Policies (`TicketPolicy`, etc.), combinando checagem de permissão (`hasPermission`) com checagem de propriedade/vínculo do recurso.
    > 📌 Ainda não implementado neste módulo — não há model `Ticket` no escopo atual (só autenticação). Fica para o módulo de Tickets.
  - **Evolução futura (se necessário):** caso o volume de permissões cresça e justifique cache, usar chave versionada por usuário (`user:{id}:permissions:v{updated_at}`), auto-invalidada na atualização do usuário — evitando o problema de singleton do spatie.

---

## 📦 2. ARQUIVOS DE INFRAESTRUTURA (REFERÊNCIA DE IMPLEMENTAÇÃO)

Os arquivos abaixo são o ponto de partida real para construir o ambiente descrito na seção 1. Devem ser criados nos caminhos indicados assim que o skeleton do Laravel for instalado (`laravel new` ou `composer create-project`).

### 📄 `docker-compose.yml` (raiz do projeto)

```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      args:
        USER_ID: ${USER_ID:-1000}
        GROUP_ID: ${GROUP_ID:-1000}
    container_name: itsm-app
    restart: unless-stopped
    entrypoint: ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--max-requests=1000"]
    ports:
      - "8000:8000"
    volumes:
      - .:/app
    env_file:
      - .env
    depends_on:
      - postgres
    networks:
      - rede-itsm

  postgres:
    image: postgres:15-alpine
    container_name: itsm-postgres
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${DB_DATABASE:-itsm}
      POSTGRES_USER: ${DB_USERNAME:-itsm}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-secret}
    ports:
      - "5432:5432" # apenas dev local — remover/restringir em staging/produção
    volumes:
      - dados_postgres:/var/lib/postgresql/data
    networks:
      - rede-itsm

volumes:
  dados_postgres:
    name: dados_postgres

networks:
  rede-itsm:
    name: rede-itsm
    driver: bridge
```

### 📄 `docker/php/Dockerfile`

> ✅ **Versão efetivamente implementada** (divergências do rascunho original explicadas nos comentários e detalhadas na seção 3.6).

```dockerfile
FROM dunglas/frankenphp:1.12.4-php8.3-alpine
# Tag fixada explicitamente (nunca `latest`) para builds reprodutíveis.
# Laravel Octane 2.x exige FrankenPHP >= 1.5.0 (checado em runtime via
# `frankenphp build-info`); confirmar esse mínimo ao atualizar a tag.

ARG USER_ID=1000
ARG GROUP_ID=1000

# Composer (não incluído na imagem base) — necessário para `composer install`
# manual dentro do container, conforme seção 1.2 da especificação.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Pacotes de sistema (NÃO são extensões PHP)
RUN apk add --no-cache \
    bash \
    shadow \
    icu-dev \
    postgresql-dev \
    $PHPIZE_DEPS

# Extensões PHP
# pcntl não está listada na especificação original, mas é exigida pelo
# Laravel Octane (InteractsWithServers::getSubscribedSignals) para tratar
# SIGINT/SIGTERM/SIGHUP — sem ela o worker falha ao iniciar.
RUN docker-php-ext-install pdo pdo_pgsql intl pcntl

# Remapeamento de UID/GID do usuário da imagem para o usuário do host
# Confirmado: a imagem base usa `www-data` (uid/gid 82, Alpine).
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

WORKDIR /app

COPY . /app

RUN chown -R www-data:www-data /app /config /data

USER www-data

EXPOSE 8000
```

### ✅ Passos de setup local (ordem de execução)

1. `composer create-project laravel/laravel .` (gera o skeleton na raiz do projeto).
2. `composer require laravel/octane` seguido de `php artisan octane:install --server=frankenphp --no-interaction`.
3. Criar `docker/php/Dockerfile` e `docker-compose.yml` conforme acima.
4. Copiar `.env.example` para `.env`, ajustar `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (mesmos valores do `docker-compose.yml`), e `FRONTEND_URL` (usado por CORS e pelo link de reset de senha — ver seção 3.4/3.5).
5. `docker compose up -d --build`.
6. `docker compose exec app php artisan key:generate` e `php artisan migrate`.
7. `composer require laravel/sanctum` seguido de `php artisan install:api --no-interaction` (dentro do container, ou no host + `docker compose exec app php artisan migrate` — o comando tenta migrar sozinho e falha se rodado no host sem acesso ao Postgres do Docker).
8. `docker compose exec app php artisan db:seed` (cria papéis/permissions padrão e um usuário Admin — ver seção 3.7).
9. Validar acesso em `http://localhost:8000`.

---

## 🔐 3. MODELO DE DADOS E ENDPOINTS — AUTENTICAÇÃO/AUTORIZAÇÃO (IMPLEMENTADO)

Esta seção documenta, com precisão de coluna e payload, o que foi efetivamente implementado para o módulo de autenticação — é a referência a consultar antes de estender o schema (ex: ao adicionar `Ticket` e vinculá-lo a `User`/`Customer`).

### 3.1. Tabelas

#### `users` (guard `web` — colaboradores internos)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `name` | `string` | |
| `email` | `string` | `unique` |
| `email_verified_at` | `timestamp` nullable | |
| `password` | `string` | `bcrypt` via cast `'hashed'` no model |
| `remember_token` | `string` nullable | padrão do Laravel |
| `created_at`, `updated_at` | `timestamp` | |

#### `password_reset_tokens` (broker `users`)
| Coluna | Tipo | Notas |
|---|---|---|
| `email` | `string` PK | |
| `token` | `string` | |
| `created_at` | `timestamp` nullable | |

#### `clients` (empresa que assina o serviço — **não confundir com `Customer`**)
> 📌 **Terminologia:** `Client` é a empresa contratante (quem assina o serviço); `Customer` é a pessoa do portal que abre chamados, e pertence a um `Client`. O texto em português desta spec usa "cliente" de forma solta em alguns pontos (ex. seção 1.3) para se referir ao `Customer` — isso é anterior à existência deste model e não deve ser confundido com a entidade `Client` aqui descrita.

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `name` | `string` | |
| `created_at`, `updated_at` | `timestamp` | |

#### `customers` (guard `customer` — usuários do portal, vinculados a um `Client`)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `client_id` | `bigint` FK → `clients.id` | **obrigatório** (`NOT NULL`); `onDelete('restrict')` — não é possível deletar um `Client` com `customers` vinculados |
| `name` | `string` | |
| `email` | `string` | `unique` |
| `email_verified_at` | `timestamp` nullable | |
| `password` | `string` | `bcrypt` via cast `'hashed'` |
| `remember_token` | `string` nullable | |
| `created_at`, `updated_at` | `timestamp` | |

#### `customer_password_reset_tokens` (broker `customers`)
Mesma estrutura de `password_reset_tokens`, tabela separada para não misturar o namespace de e-mail/token dos dois guards.

#### `roles`
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `name` | `string` | nome de exibição, ex. `"Admin"` |
| `slug` | `string` | `unique`, ex. `"admin"` — usado em código/seed |
| `created_at`, `updated_at` | `timestamp` | |

#### `permissions`
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `name` | `string` | nome de exibição, ex. `"Atribuir chamados"` |
| `slug` | `string` | `unique`, ex. `"tickets.assign"` — usado em `Gate::allows()`/`hasPermission()` |
| `created_at`, `updated_at` | `timestamp` | |

#### `permission_role` (pivot)
| Coluna | Tipo | Notas |
|---|---|---|
| `role_id` | `bigint` FK → `roles.id` | `cascadeOnDelete` |
| `permission_id` | `bigint` FK → `permissions.id` | `cascadeOnDelete` |
| — | PK composta `(role_id, permission_id)` | sem `id` próprio, sem timestamps |

#### `role_user` (pivot)
| Coluna | Tipo | Notas |
|---|---|---|
| `user_id` | `bigint` FK → `users.id` | `cascadeOnDelete` |
| `role_id` | `bigint` FK → `roles.id` | `cascadeOnDelete` |
| — | PK composta `(user_id, role_id)` | sem `id` próprio, sem timestamps |

> `Customer` não tem pivot equivalente — clientes não têm papéis/permissões (ver nota na seção 1.3).

#### `personal_access_tokens` (tabela padrão do Sanctum, migration publicada por `install:api`)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `tokenable_type` / `tokenable_id` | morph | aponta para `App\Models\User` ou `App\Models\Customer` |
| `name` | `string` | sempre `"spa"` neste projeto (ver `AuthController::login`) |
| `token` | `string(64)` | hash SHA-256, `unique` |
| `abilities` | `text` nullable | json; `["staff"]` para guard `web`, `["customer"]` para guard `customer` |
| `last_used_at` | `timestamp` nullable | |
| `expires_at` | `timestamp` nullable | **TTL por guard aplicado aqui** — 2h (`web`) / 4h (`customer`), setado explicitamente em `createToken()` |
| `created_at`, `updated_at` | `timestamp` | |

#### `token_audit_logs` (auditoria de emissão/revogação — item da seção 1.3)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `tokenable_type` / `tokenable_id` | morph nullable | `User` ou `Customer` dono do token |
| `action` | `string` | `issued` \| `refreshed` \| `revoked` \| `revoked_all` |
| `ip_address` | `string(45)` nullable | suporta IPv6; de `$request->ip()` |
| `user_agent` | `text` nullable | de `$request->userAgent()` |
| `created_at` | `timestamp` (`useCurrent()`) | **sem** `updated_at` (`const UPDATED_AT = null` no model — é um log append-only) |

### 3.2. Models e relacionamentos

| Model | Traits/relations relevantes |
|---|---|
| `App\Models\User` | `HasApiTokens`, `Notifiable`; `roles(): BelongsToMany` → `Role`; `hasPermission(string $slug): bool` (via `loadMissing('roles.permissions')`) |
| `App\Models\Client` | `customers(): HasMany` → `Customer` |
| `App\Models\Customer` | `HasApiTokens`, `Notifiable`; `client(): BelongsTo` → `Client`; **sem** `roles()`/`hasPermission()` |
| `App\Models\Role` | `permissions(): BelongsToMany` → `Permission`; `users(): BelongsToMany` → `User` |
| `App\Models\Permission` | `roles(): BelongsToMany` → `Role` |
| `App\Models\TokenAuditLog` | `tokenable(): MorphTo`; `const UPDATED_AT = null` |

### 3.3. Configuração dos guards (`config/auth.php`)

```php
'guards' => [
    'web'      => ['driver' => 'sanctum', 'provider' => 'users'],
    'customer' => ['driver' => 'sanctum', 'provider' => 'customers'],
],
'providers' => [
    'users'     => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'customers' => ['driver' => 'eloquent', 'model' => App\Models\Customer::class],
],
'passwords' => [
    'users'     => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
    'customers' => ['provider' => 'customers', 'table' => 'customer_password_reset_tokens', 'expire' => 60, 'throttle' => 60],
],
```

E em `config/sanctum.php`:

```php
'guard' => [],       // ver nota abaixo — NÃO adicionar 'web'/'customer' aqui
'expiration' => null, // TTL é por-token (ver 3.1 personal_access_tokens), não global
```

> ⚠️ **Bug encontrado e corrigido durante a implementação:** `config('sanctum.guard')` é consultado dentro do próprio `Laravel\Sanctum\Guard::__invoke()` para checar guards de sessão "stateful" (cookie SPA) *antes* de tentar o Bearer token. Como os guards `web` e `customer` deste projeto usam o próprio driver `sanctum` (não `session`), colocar `['web', 'customer']` nessa lista causa **recursão infinita** (`Guard::__invoke` chama `Auth::guard('web')->user()`, que é o mesmo `Guard::__invoke`). Como o projeto é Bearer-only (sem `EnsureFrontendRequestsAreStateful`), a lista fica vazia.

### 3.4. Endpoints

Todos sob prefixo `/api`. Guard `web` é o default para as rotas sem prefixo; as rotas `/customer/*` usam `->defaults('guard', 'customer')` no mesmo `AuthController`.

| Método | Rota | Guard | Middleware | Body | Resposta (200) |
|---|---|---|---|---|---|
| `POST` | `/login` | `web` | `throttle:login` | `{email, password}` | `{token, expires_at, user}` |
| `POST` | `/customer/login` | `customer` | `throttle:login` | `{email, password}` | `{token, expires_at, user}` |
| `GET` | `/me` | `web,customer` | `auth:web,customer` | — | `User` (via `UserResource`, ver 3.4.1) ou `Customer` puro |
| `POST` | `/refresh` | `web,customer` | `auth:web,customer`, `throttle:login` | — | `{token, expires_at}` — revoga o token atual e emite outro, na mesma transação |
| `POST` | `/logout` | `web,customer` | `auth:web,customer` | — | `{message}` — revoga só `currentAccessToken()` |
| `POST` | `/logout-all` | `web,customer` | `auth:web,customer` | — | `{message}` — `tokens()->delete()` |
| `POST` | `/forgot-password` (+ `/customer/forgot-password`) | `web` / `customer` | — | `{email}` | `{message}` genérica (não revela se o e-mail existe) |
| `POST` | `/reset-password` (+ `/customer/reset-password`) | `web` / `customer` | — | `{token, email, password, password_confirmation}` | `{message}` — revoga todos os tokens do usuário/cliente ao concluir |

Validação de erro: credenciais inválidas ou payload malformado → `422` (`ValidationException`); acima do rate limit → `429`; sem token/expirado/revogado em rota protegida → `401`.

#### 3.4.1. Formato de `user` (`App\Http\Resources\UserResource`)

O campo `user` de `/login` e o corpo de `/me` (quando o guard é `web`) passam por `UserResource` em vez de serializar o model Eloquent cru — sem isso, `roles`/`permissions` viriam com `created_at`/`updated_at`/`pivot` de brinde (ruído inútil pro consumidor da API). `roles` e `permissions` são **arrays de slugs**, não objetos aninhados; `permissions` já vem achatada e deduplicada de todas as roles do usuário (o front não precisa saber qual role concedeu qual permission, só se ele pode ou não fazer algo — checagem vira `permissions.includes('tickets.assign')`, sem parsing de string):

```jsonc
{
  "id": 1,
  "name": "Admin",
  "email": "admin@example.com",
  "email_verified_at": "2026-07-09T21:52:23.000000Z",
  "created_at": "2026-07-09T21:52:23.000000Z",
  "updated_at": "2026-07-09T21:52:23.000000Z",
  "roles": ["admin"],
  "permissions": ["users.manage", "roles.manage", "clients.manage", "tickets.view", "tickets.assign"]
}
```

`Customer` não tem `UserResource` equivalente — é devolvido como model puro (sem roles/permissions, que não se aplicam a esse guard).

### 3.4.2. Endpoints — CRUD de `Client` (`App\Http\Controllers\Api\ClientController`)

`Route::apiResource('clients', ClientController::class)`, sob `auth:web` + `can:clients.manage` (só `User` com a permissão `clients.manage` — por padrão, só a role Admin a possui; `Customer` nunca autentica no guard `web`, então nunca acessa essas rotas).

| Método | Rota | Body | Resposta |
|---|---|---|---|
| `GET` | `/clients` | — | `200` — paginado, `{data: [{id, name, created_at, updated_at}, ...], links, meta}` |
| `POST` | `/clients` | `{name}` | `201` — `{data: {id, name, created_at, updated_at}}` |
| `GET` | `/clients/{client}` | — | `200` — `{data: {...}}` |
| `PUT`/`PATCH` | `/clients/{client}` | `{name}` | `200` — `{data: {...}}` |
| `DELETE` | `/clients/{client}` | — | `204` sem corpo; **`409`** se o `Client` ainda tiver `Customer`s vinculados (checado explicitamente no controller antes do `delete()`, não depende de capturar a exceção do banco) |

Todas as respostas passam por `App\Http\Resources\ClientResource` (mesmo formato do model, sem campos extras — existe como Resource, não serialização crua, só para manter o padrão de envelope `{data: ...}` do restante da API).

Validação: `name` ausente/vazio → `422`; sem token → `401`; `User` autenticado sem a permissão `clients.manage` → `403`.

### 3.5. Outras decisões de implementação

- **`AppServiceProvider::boot()`**:
  - `RateLimiter::for('login', ...)` — 5 tentativas/min, chave `lower(email)|ip`, cobre `/login`, `/customer/login` e `/refresh`.
  - `ResetPassword::createUrlUsing(...)` — o backend é API-only (não existe rota web nomeada `password.reset`), então a notificação de reset é redirecionada para `{FRONTEND_URL}/reset-password?token=...&email=...`, onde o Vue faz o `POST /api/reset-password`.
- **`config/app.php`**: nova chave `'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173')`, consumida pelo item acima.
- **`config/cors.php`**: `allowed_origins` lido de `FRONTEND_URL` (suporta lista separada por vírgula), nunca `*`; `supports_credentials` `false` (já é o padrão do Laravel, mantido).
- **`AuthServiceProvider::boot()`**: `Gate::before(fn($user, $ability) => $user instanceof User && $user->hasPermission($ability) ? true : null)` — `Customer` nunca passa nesse `before` (cai direto pra Policy, se houver).
- **`bootstrap/app.php` → `withMiddleware()`**: `$middleware->redirectGuestsTo(fn () => null)`. Backend é API-only, sem rota `login`; sem essa linha, o middleware `Authenticate` decide se redireciona um guest **antes** de checar se a resposta deveria ser JSON (`Illuminate\Auth\Middleware\Authenticate::unauthenticated()` chama `route('login')` sempre que `$request->expectsJson()` é `false` — o que acontece em qualquer chamada sem header `Accept: application/json`, ex. um `curl` cru). Isso derrubava `401`s legítimos com uma `RouteNotFoundException` de stack trace completo. Com o `redirectGuestsTo(fn () => null)`, toda rota `auth:...` sem sessão válida devolve sempre `401 {"message":"Unauthenticated."}`, independente de headers.

### 3.6. Divergências do rascunho original desta spec (seção 1/2)

| Item | Rascunho original | Implementado | Motivo |
|---|---|---|---|
| Tag FrankenPHP | `dunglas/frankenphp:1.3-php8.3-alpine` | `dunglas/frankenphp:1.12.4-php8.3-alpine` | Octane 2.x exige FrankenPHP `>= 1.5.0`; `1.3.x` não sobe o worker |
| Extensão `pcntl` | não mencionada | adicionada em `docker-php-ext-install` | Exigida pelo Octane para tratar `SIGINT`/`SIGTERM`/`SIGHUP` |
| Binário `composer` | não mencionado no Dockerfile | `COPY --from=composer:2 ...` | Seção 1.2 pressupõe `composer install` funcionando dentro do container |
| `chown` de `/config` `/data` | só `/app` | `/app /config /data` | XDG dirs usados por `artisan tinker`/psysh; sem isso, tinker falha por permissão como `www-data` remapeado |
| `Sanctum::personalAccessTokenExpiration` | citado como o mecanismo de TTL | TTL setado por-token em `createToken()` | O config é global a todos os guards, não dá pra diferenciar 2h/4h por guard |
| `config('sanctum.guard')` | não discutido | mantido `[]` | Evita recursão infinita (ver 3.3) |
| Redirect de guests (`bootstrap/app.php`) | não discutido | `$middleware->redirectGuestsTo(fn () => null)` | Ver 3.5 — sem isso, `401` em rota `auth:...` sem header `Accept: application/json` quebrava com `RouteNotFoundException` (`route('login')` inexistente) em vez de JSON limpo |

### 3.7. Seed padrão (`RolesAndPermissionsSeeder` + `DatabaseSeeder`)

Permissions: `users.manage`, `roles.manage`, `tickets.view`, `tickets.assign` (as duas últimas são placeholders para o futuro módulo de Tickets).

| Role (`slug`) | Permissions atribuídas |
|---|---|
| `admin` | todas |
| `supervisor` | `tickets.view`, `tickets.assign` |
| `agente` | `tickets.view` |

> 📌 **Sem role "cliente":** uma versão anterior desta implementação criava uma role `cliente` vazia (seguindo o texto literal do rascunho original da spec) e chegava a semear um usuário fictício `cliente.interno@example.com` só pra ocupá-la. Isso foi removido — não faz sentido nenhum `User` ter uma role "Cliente", já que quem abre chamado é sempre o model/guard `Customer` (sem RBAC nenhum, ver seção 1.3). Manter essa role só confundia qual guard estava sendo testado.

`DatabaseSeeder` também cria usuários de teste, um por role (guard `web`), todos com senha `password`:

| E-mail | Role |
|---|---|
| `admin@example.com` | `admin` |
| `supervisor@example.com` | `supervisor` |
| `agente@example.com` | `agente` |

E do lado `customer` (quem de fato abre chamado pelo portal):
- `cliente@example.com` (senha `password`) — e-mail fixo, para login previsível.
- +3 customers extras com dados aleatórios via `Customer::factory()->count(3)->create()` — só massa pra exercitar listagem/paginação, sem senha/e-mail previsíveis (usar o e-mail fixo acima pra testar login).

> 📌 **Idempotência:** os registros de e-mail fixo (`admin@example.com`, `supervisor@example.com`, `agente@example.com`, `cliente@example.com`) são criados via `updateOrCreate(['email' => ...], [...])`, não `factory()->create()` — `db:seed` (sem `--fresh`) precisa poder rodar quantas vezes forem necessárias sem quebrar. Uma versão anterior usava `create()` direto e uma segunda chamada a `db:seed` sem `migrate:fresh` derrubava com `UniqueConstraintViolationException` no e-mail do admin. Os 3 customers aleatórios **não** são idempotentes por design (rodar `db:seed` de novo não os duplica, mas também não os "atualiza" — a condição `if (Customer::count() <= 1)` só os cria na primeira vez).

Todos permitem login imediato (`POST /api/login` para os 3 users staff, `POST /api/customer/login` para o customer fixo) após `migrate:refresh --seed` ou `migrate:fresh --seed` em ambiente local.

> ⚠️ Credenciais de conveniência para dev local apenas — trocar/remover antes de qualquer ambiente staging/produção.

### 3.8. Suíte de testes automatizados (`tests/Feature/Auth`)

Feature tests com `RefreshDatabase`, dados montados via factories (`UserFactory`, `CustomerFactory`, `RoleFactory`, `PermissionFactory` — as duas últimas criadas junto com essa suíte), **sem depender dos seeders locais** (`RolesAndPermissionsSeeder`/`DatabaseSeeder` nunca são chamados nos testes).

| Arquivo | Cobre |
|---|---|
| `LoginTest.php` | Login staff (com roles/permissions no payload), login customer, e-mail/senha inválidos (staff e customer), e-mail de `User` não autentica no guard `customer` (tabelas separadas) |
| `TokenLifecycleTest.php` | `/refresh` (rotação atômica — token antigo é revogado, abilities preservadas), `/logout` (revoga só o token atual), `/logout-all` (revoga todos, não afeta tokens de outro usuário), `401` sem autenticação |
| `PasswordRecoveryTest.php` | Throttle em `/forgot-password` e `/reset-password` (5 tentativas + bloqueio na 6ª, bucket isolado por e-mail), revogação de todos os tokens após reset bem-sucedido (staff e customer), reset com token inválido não muda a senha |

Rodar com `docker compose exec app php artisan test` (ou `./vendor/bin/phpunit`).

> 🚨 **Incidente durante a implementação — banco de dev real foi zerado por engano.** A primeira versão do `phpunit.xml` (herdada do skeleton do Laravel) configurava `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` via `<env>`, mas isso **não tinha efeito**: como o `docker-compose.yml` usa `env_file: .env` no serviço `app`, as variáveis do `.env` (incluindo `DB_CONNECTION=pgsql`) já chegam setadas tanto em `$_ENV`/`getenv()` quanto em `$_SERVER` antes do PHPUnit subir. O `<env force="true">` do PHPUnit só sobrescreve `$_ENV`/`putenv()` — **não toca em `$_SERVER`** — e o resolvedor de env do Laravel (`Illuminate\Support\Env`, via `vlucas/phpdotenv`) prioriza `$_SERVER`. Resultado: o override para sqlite era ignorado silenciosamente, os testes rodavam contra o Postgres de desenvolvimento de verdade, e o primeiro `RefreshDatabase` da suíte executou o equivalente a um `migrate:fresh` no banco `itsm` real, apagando os dados de dev (foram restaurados depois com `php artisan db:seed`).
>
> **Fix:** todo `<env>` em `phpunit.xml` tem agora um `<server>` espelhado com o mesmo nome/valor/`force="true"` — o `PhpHandler` do PHPUnit escreve `<server>` direto em `$_SERVER` incondicionalmente, cobrindo a lacuna. Validado forçando um dump de `getenv()`/`$_ENV`/`$_SERVER`/`Env::getRepository()->get()`/`config('database.default')` dentro de um teste até os 5 baterem em `sqlite`.
>
> **Lição para qualquer novo ambiente Docker + PHPUnit deste tipo:** `env_file` no `docker-compose.yml` é exatamente o tipo de configuração que faz esse problema aparecer — não é geral a todo projeto Laravel, é específico de rodar os testes dentro de um container cujas variáveis já vêm setadas no processo antes do PHP iniciar. Se decidir buildar contra Postgres real em vez de sqlite no futuro, usar um banco `_testing` dedicado (nunca o mesmo `DB_DATABASE` do dev) reduziria o dano de uma futura recorrência desse tipo de bug de configuração.

---