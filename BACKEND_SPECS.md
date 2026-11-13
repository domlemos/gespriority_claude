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
  > 📌 **Editar `.env` é um problema diferente — nem `octane:reload` nem `docker compose restart app`
  > resolvem.** `docker-compose.yml` carrega `.env` pro ambiente do processo do container via
  > `env_file:`, mas isso só acontece **na criação do container**, não a cada `restart` (que só para/
  > reinicia o mesmo processo, preservando o ambiente que já tinha sido fixado). O PHP-dotenv do
  > Laravel, por padrão, **não sobrescreve variáveis que já existem no ambiente** — então editar
  > `.env` com o container já rodando é um no-op silencioso: `env()`/`config()` continuam devolvendo
  > os valores antigos indefinidamente, sem erro nenhum, até o container ser recriado. Sintoma
  > confirmado na prática: `MAIL_MAILER=smtp` com credenciais Mailtrap no `.env`, mas
  > `config('mail.default')` continuando `log` e nenhum e-mail saindo, mesmo depois de `octane:reload`.
  > Fix: `docker compose up -d --force-recreate app` (recria o container, relê o `.env` do zero —
  > não é destrutivo, não mexe no volume do Postgres). Checagem rápida pra confirmar qual dos dois
  > problemas é: `docker compose exec app printenv | grep MAIL_` — se bater com o `.env` em disco, o
  > problema é código em memória (`octane:reload` resolve); se não bater, é ambiente do container
  > (precisa recriar).
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
| `grupo_solucao_id` | `bigint` FK → `grupos_solucao.id` | **obrigatório** (`NOT NULL`); `onDelete('restrict')` — não é possível deletar um `GrupoSolucao` com `User`s vinculados. Adicionado via migration separada (`add_grupo_solucao_id_to_users_table`) porque `users` já existia desde o boilerplate inicial do Laravel |
| `name` | `string` | |
| `email` | `string` | `unique` |
| `email_verified_at` | `timestamp` nullable | |
| `password` | `string` | `bcrypt` via cast `'hashed'` no model |
| `remember_token` | `string` nullable | padrão do Laravel |
| `deleted_at` | `timestamp` nullable | soft delete (`SoftDeletes`) — "excluir" um `User` é desativação, não remoção; ver §3.4.3 |
| `created_at`, `updated_at` | `timestamp` | |

> 📌 **Todo `User` pertence a um grupo, sem exceção — `Customer` é sempre isento.** A obrigatoriedade
> é por model, não por role: mesmo `admin` precisa de um `grupo_solucao_id` (seedado como
> "Administração", ver §3.7). `Customer` (guard/tabela separados) nunca tem essa coluna — a isenção
> mencionada no requisito original ("exceto os usuários da aplicação") se refere à distinção
> `User`×`Customer`, não a um subconjunto de `User`.

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

#### `audit_logs` (log de ações de usuários — substitui `token_audit_logs`, ver §3.4.11)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `ator_type` | `string(20)` nullable | alias curto `user` \| `customer` (não o nome da classe — ver `AuditLog::ENTIDADES`); preenchido também em `login_falhou` (guard tentado) |
| `ator_id` | `bigint` nullable | sem FK de propósito (log sobrevive a qualquer exclusão); `null` em `login_falhou` contra e-mail inexistente |
| `ator_nome` | `string` nullable | **snapshot** do nome no momento da ação; em `login_falhou`, o e-mail tentado |
| `acao` | `string(40)` | slug de `AuditLog::ACOES`: `criado`, `atualizado`, `excluido`, `login`, `login_falhou`, `token_renovado`, `logout`, `logout_todos`, `senha_redefinida`, `convite_enviado`, `download`, `exportado` |
| `entidade_type` / `entidade_id` | `string(40)` / `bigint`, nullable | alias de `AuditLog::ENTIDADES` (`user`, `customer`, `client`, `politica_sla`, `categoria`, `subcategoria`, `item`, `grupo_solucao`, `relatorio_salvo`, `incidente`, `incidente_descricao`, `anexo`, `relatorio`); `null` em login/logout |
| `entidade_label` | `string` nullable | **snapshot** legível (nome, `#id título` do chamado, nome do arquivo, título do relatório exportado) |
| `alteracoes` | `jsonb` nullable | `{campo: {de, para}}` (CRUD e relações N:N); `{campos: [...]}` (chamado — só nomes, ver §3.4.11); `{parametros: {...}}` (exportação) |
| `ip_address` | `string(45)` nullable | `$request->ip()` |
| `user_agent` | `text` nullable | truncado em 1000 caracteres |
| `created_at` | `timestamp` (`useCurrent()`) | **sem** `updated_at` (`const UPDATED_AT = null` — append-only) |

Índices: `created_at`; `(ator_type, ator_id, created_at)`; `(entidade_type, entidade_id)`;
`(acao, created_at)`. Retenção de **6 meses** (`AuditLog::RETENCAO_MESES`, `MassPrunable`).

> 📌 **Migração de `token_audit_logs`:** a migration `2026_11_12_000000_create_audit_logs_table`
> copia os registros antigos (`issued`→`login`, `refreshed`→`token_renovado`, `revoked`→`logout`,
> `revoked_all`→`logout_todos`, nome do autor resolvido por join) e remove a tabela antiga e o model
> `TokenAuditLog`. O `down()` faz o caminho inverso. Registros com mais de 6 meses serão apagados
> na primeira execução do `model:prune` agendado.

#### `politicas_sla` (`App\Models\PoliticaSla` — política de SLA, em horas expressas via campos `_minutos`)
> 📌 **"SLA em horas" vs. colunas `_minutos`:** o requisito de negócio expressa a SLA em horas
> (ex. "4 horas de resposta"), mas as colunas guardam minutos (`tempo_resposta_minutos`,
> `tempo_resolucao_minutos`) — granularidade mais fina que "hora inteira" sem precisar de coluna
> decimal (ex. SLA de 15 minutos pra prioridade `urgente` não é representável em horas inteiras). A
> conversão pra exibição em horas, se necessário, fica a cargo do consumidor da API.

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `nome` | `string` | |
| `prioridade` | `string` | um de `PoliticaSla::PRIORIDADES` = `baixa`\|`media`\|`alta`\|`urgente` — validado na aplicação (`Rule::in`), sem enum/check nativo do banco (portabilidade Postgres/SQLite) |
| `tempo_resposta_minutos` | `unsignedInteger` | |
| `tempo_resolucao_minutos` | `unsignedInteger` | validado (`gte:tempo_resposta_minutos`) — resolução não pode ser mais curta que a resposta |
| `apenas_horas_uteis` | `boolean` | default `false` — se `true`, a contagem do SLA (quando existir motor de contagem, ainda não implementado) considera só horário comercial |
| `ativo` | `boolean` | default `true` — permite desativar uma política sem excluí-la |
| `client_id` | `bigint` FK → `clients.id` nullable | `cascadeOnDelete` — política é config/metadado do cliente, não um registro primário como `Customer`; some junto se o `Client` for excluído. **`NULL` = "padrão global"** dessa prioridade (ver nota de resolução abaixo) |
| `created_at`, `updated_at` | `timestamp` | |
| índice | `(client_id, prioridade)` | não é `unique` — `NULL` não colide de forma confiável entre Postgres/SQLite em unique composta; unicidade é garantida via `Rule::unique(...)->where(...)` condicional no controller, não no banco |

> 📌 **Resolução do "SLA padrão" (`Client::resolvedSlaFor(string $prioridade)`):** para uma
> prioridade, o cliente usa sua própria política (`client_id` = do cliente) se existir uma
> `ativo = true`; caso contrário, cai automaticamente na política global da mesma prioridade
> (`client_id IS NULL`). Não há coluna `sla_padrao_id` em `clients` — a "SLA padrão" é resolvida
> implicitamente por prioridade, não por um vínculo explícito único por cliente.

#### `categorias` (taxonomia de incidentes — nível 1)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `nome` | `string` | `unique` |
| `ativo` | `boolean` | default `true` |
| `created_at`, `updated_at` | `timestamp` | |

#### `subcategorias` (taxonomia de incidentes — nível 2, sempre vinculada a uma `categoria`)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `categoria_id` | `bigint` FK → `categorias.id` | **obrigatório** (`NOT NULL`); `onDelete('restrict')` — não é possível deletar uma `Categoria` com `subcategorias` vinculadas |
| `nome` | `string` | |
| `ativo` | `boolean` | default `true` |
| `created_at`, `updated_at` | `timestamp` | |
| índice | `unique(categoria_id, nome)` | **é `unique` de verdade no banco** (diferente do índice de `politicas_sla`) — `categoria_id` nunca é nulo aqui, então não tem a pegadinha de `NULL` não colidir consigo mesmo entre Postgres/SQLite |

#### `itens` (taxonomia de incidentes — nível 3, sempre vinculado a uma `subcategoria`)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `subcategoria_id` | `bigint` FK → `subcategorias.id` | **obrigatório** (`NOT NULL`); `onDelete('restrict')` — não é possível deletar uma `Subcategoria` com `itens` vinculados |
| `nome` | `string` | |
| `prioridade_padrao` | `string` nullable | um de `PoliticaSla::PRIORIDADES`, validado (`Rule::in`) e **obrigatório** no create/update da API — define a prioridade de todo incidente classificado neste item (ver nota de "SLA por Categorização" na tabela `incidentes`); a coluna segue nullable só por causa de itens legados, que não podem ser usados em incidentes até ganharem um padrão |
| `ativo` | `boolean` | default `true` |
| `created_at`, `updated_at` | `timestamp` | |
| índice | `unique(subcategoria_id, nome)` | mesmo raciocínio de `subcategorias` — `subcategoria_id` nunca é nulo, unique real no banco |

> 📌 **3 níveis: Categoria → Subcategoria → Item.** Prática recomendada de ITSM (ITIL, e o que
> ferramentas como ServiceNow/Jira Service Management usam) — 2 níveis costuma ser raso demais pra
> relatório/roteamento (ex. "Hardware > Impressora" não diferencia "sem toner" de "atolamento de
> papel", causas raiz bem diferentes). Continua sendo hierarquia fixa (não auto-referenciada) porque
> o domínio pedido tem profundidade fixa em 3, mesmo padrão de forma usado em `Client`→`Customer`.

#### `grupos_solucao` (grupo de solução — todo `User` pertence a um, ver nota em `users` acima)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `nome` | `string` | `unique` |
| `ativo` | `boolean` | default `true` |
| `created_at`, `updated_at` | `timestamp` | |

#### `incidentes` (chamado)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `customer_id` | `bigint` FK → `customers.id` | **obrigatório**; `onDelete('restrict')` — quem abriu/é o afetado é sempre um `Customer` (nunca um `User`, ver seção 1.3); registro histórico, não pode sumir com o cliente |
| `criado_por_id` | `bigint` FK → `users.id` nullable | **staff** que registrou o chamado (não confundir com `customer_id`, o afetado) — `onDelete('restrict')`; setado uma única vez no `store()` (`$request->user()`), nunca muda depois — não é um evento repetível como resolvido/fechado, não usa `incidente_eventos`; `null` só pra incidentes criados antes desta coluna existir (informação nunca capturada, sem como retroagir) e pra chamados abertos pelo portal (ver `criado_por_customer_id`) |
| `criado_por_customer_id` | `bigint` FK → `customers.id` nullable | **cliente** que abriu pelo portal (`POST /customer/incidentes`); `onDelete('restrict')`; nunca preenchido junto com `criado_por_id` |
| `item_id` | `bigint` FK → `itens.id` nullable | `onDelete('restrict')` — classificação (Categoria/Subcategoria deriváveis via `item->subcategoria->categoria`, sem FKs redundantes); nullable na coluna só por incidentes legados — a API exige `item_id` na abertura (a prioridade depende dele) e não aceita removê-lo num `update()` |
| `grupo_solucao_id` | `bigint` FK → `grupos_solucao.id` nullable | `onDelete('restrict')` — equipe responsável; nullable pelo mesmo motivo de `item_id` (roteamento pode ser posterior) |
| `responsavel_id` | `bigint` FK → `users.id` nullable | `onDelete('restrict')` — agente atribuído; nullable, atribuição individual normalmente vem depois do roteamento pro grupo |
| `titulo` | `string` | |
| `prioridade` | `string` | reaproveita `PoliticaSla::PRIORIDADES` (`baixa`\|`media`\|`alta`\|`urgente`) — sem duplicar a lista de constantes. **Nunca vem do cliente** — sempre derivada de `Item::prioridade_padrao`; `prioridade` no payload é ignorada, inclusive para Admin (ver nota "SLA por Categorização" abaixo). **Nullable** só para chamados abertos pelo portal ainda não classificados — o staff classifica na triagem e a prioridade é derivada nesse momento |
| `origem` | `string` | um de `Incidente::ORIGENS` — constante própria do incidente (ver nota abaixo) |
| `status` | `string` | um de `Incidente::STATUSES`; default `'aberto'` — **forçado no `store()`, ignora qualquer `status` enviado pelo cliente na criação** |
| `tipo` | `string` indexado | um de `Incidente::TIPOS` (`incidente` \| `solicitacao`); default `'incidente'` (backfill dos existentes). Opcional no `store()` — ausente vira `incidente`. Só categoriza: fluxo, status, SLA e taxonomia são os mesmos pros dois tipos. Restrito após a abertura (ver nota de campos restritos); mudança gera entrada `alteracao` no feed com os rótulos `Incidente::TIPO_ROTULOS` |
| `prazo_resposta` | `timestamp` nullable | calculado **uma única vez** no `store()` (`created_at + tempo_resposta_minutos` da `PoliticaSla` aplicável via `Client::resolvedSlaFor()`) e **congelado** — nunca recalculado, nem que a política mude depois (**exceto** quando `item_id` muda de valor num `update()`, ver nota "SLA por Categorização" abaixo). `null` se não houver política aplicável pra essa prioridade (sem override do cliente nem padrão global) |
| `prazo_resolucao` | `timestamp` nullable | mesmo raciocínio de `prazo_resposta`, com `tempo_resolucao_minutos` |
| `respondido_em` | `timestamp` nullable | setado automaticamente na 1ª vez que `status` sai de `'aberto'` (qualquer que seja o novo status, mesmo direto pra um concluído) — nunca sobrescrito depois |
| `concluido_em` | `timestamp` nullable | setado automaticamente na 1ª vez que `status` entra em `Incidente::STATUS_CONCLUIDOS`; **limpo de volta pra `null`** se o incidente for reaberto (status volta pra um não-concluído) — ver nota de reabertura abaixo |
| `created_at`, `updated_at` | `timestamp` | |
| índices | `status`, `grupo_solucao_id`, `responsavel_id` | não únicos, só performance de filtro (ainda não expostos como query params nesta entrega) |

> 📌 **Cálculo de SLA — prazos congelados na abertura, status computado sob demanda.** Requisito
> original: "o sistema deve calcular as datas limites de atendimento e resolução no momento da
> abertura"; "se o incidente estiver aberto, o status do SLA compara agora com o prazo"; "se já
> concluído, compara a data da conclusão com o prazo histórico". Implementado assim:
> - `prazo_resposta`/`prazo_resolucao`: colunas reais, calculadas e persistidas no `store()` (ver
>   `IncidenteController::calcularPrazosSla()`) — **congeladas**, para não mudar retroativamente se
>   alguém editar a `PoliticaSla` depois (exceto no recálculo por mudança de `item_id`/`prioridade`
>   num `update()`, ver nota "SLA por Categorização" abaixo).
> - `status_sla_resposta`/`status_sla_resolucao` (`dentro_prazo`\|`estourado`\|`sem_sla`) e
>   `tempoRestanteRespostaMinutos()`/`tempoRestanteResolucaoMinutos()`: **não são colunas**, são
>   métodos calculados em `Incidente` (`statusSlaResposta()`, etc.) toda vez que são lidos —
>   comparam contra `now()` se `respondido_em`/`concluido_em` ainda for `null` (incidente em
>   andamento), ou contra o timestamp congelado se já setado (resultado fica histórico e não muda
>   mais, mesmo consultado dias depois).
> - **Pausas ("considerando eventuais pausas") ficam de fora desta entrega** — pausar/retomar a
>   contagem quando `status = 'pendente'` exige rastrear quando cada período de pausa começou/acabou
>   (nova estrutura, ainda não implementada). Decisão explícita de fazer a base funcionar primeiro
>   e tratar pausas como uma entrega separada — mesma disciplina já usada entre "cadastral" e
>   "relação com SLA" do `Incidente` em si.
>
> **Bug real corrigido — reabertura deixava o status de SLA congelado.** Como nada impede um
> `Incidente` de voltar de um status concluído pra um ativo (`status → status` é livre, ver nota
> abaixo), `concluido_em` ficava preso no valor da conclusão original mesmo com o incidente
> reaberto — `statusSlaResolucao()` continuava comparando contra essa data congelada em vez de
> voltar a comparar com `now()`, dando `dentro_prazo` pra um incidente na prática já estourado.
> `IncidenteController::registrarTransicaoDeStatus()` agora **limpa `concluido_em`** quando
> `$anterior` está em `STATUS_CONCLUIDOS` e `$novo` não está — `respondido_em` **não** é limpo
> (é fato histórico: "quando foi respondido pela primeira vez" não é desfeito por reabertura).
> Se o incidente for resolvido de novo depois, `concluido_em` é setado com o novo timestamp
> normalmente (a regra "não sobrescreve" só vale enquanto o valor não foi limpo).

> 📌 **SLA por Categorização (`Item::prioridade_padrao`).** A prioridade de um incidente é
> **sempre calculada pelo sistema** a partir da classificação: todo `Item` tem uma
> `prioridade_padrao` obrigatória (uma de `PoliticaSla::PRIORIDADES`), `item_id` é obrigatório na
> abertura, e `IncidenteController::prioridadeDoItem()` copia o padrão do item pro incidente.
> Nenhum usuário, nem Admin, escolhe ou personaliza a prioridade: `prioridade` no payload de
> `store()`/`update()` é ignorada. Item legado sem `prioridade_padrao` → 422 em `item_id` até
> alguém com `categorias.manage` configurar um padrão. Diferente do resto do cálculo de SLA
> (congelado na abertura, nunca recalculado), trocar o `item_id` de um incidente já aberto
> recalcula `prioridade` **e** `prazo_resposta`/`prazo_resolucao` — só quando o valor realmente
> muda, nunca num reenvio do mesmo item — decisão explícita do PO. A mudança de prioridade
> resultante é registrada no feed como qualquer outra alteração de campo. Quem mantém o catálogo
> (`categorias.manage`) é quem, na prática, define as prioridades, via `prioridade_padrao` dos itens.

> 📌 **Campos restritos após a abertura (`tickets.edit_all`).** Depois que um incidente é aberto,
> quem **não** tem a permission `tickets.edit_all` ("Editar todos os campos do chamado") só altera
> classificação (`item_id`), encaminhamento (`grupo_solucao_id`/`responsavel_id`) e `status` —
> `customer_id`, `titulo`, `origem` e `tipo` (`Incidente::CAMPOS_RESTRITOS_APOS_ABERTURA`) dão 422 no campo
> se vierem com valor **diferente** do atual (reenviar o mesmo valor é aceito, porque o formulário
> do front sempre manda todos os campos). Um campo recusado bloqueia o `update()` inteiro. A
> abertura (`store()`) não é afetada. Comentários/anexos continuam liberados com `tickets.manage`.
> A permission nasce via migration de dados (`2026_09_26_120000_add_tickets_edit_all_permission`)
> já concedida a Admin e Supervisor; Agente fica restrito. Ajustável pela tela de Papéis.

> 📌 **`status` e `origem` são constantes do `Incidente`, não cadastros.** Diferente de
> Categoria/Subcategoria/Item (taxonomia de negócio, muda com frequência, sem acoplamento a lógica),
> `status` dirige workflow real (pausa/retoma SLA quando existir, controla transições válidas) e
> `origem` é uma lista fechada atrelada a integrações de sistema (adicionar uma origem nova exige
> código, não só um cadastro) — mesmo raciocínio já usado pra `prioridade` em `PoliticaSla`.
>
> `Incidente::STATUSES` = `aberto`, `em_andamento`, `pendente`, `resolvido`, `fechado`, `cancelado`.
> `Incidente::ORIGENS` = `portal`, `email`, `telefone`, `chat`, `presencial`, `monitoramento`.
> Nenhuma regra de transição entre status é validada nesta entrega (qualquer status → qualquer
> status) — fica pra quando o motor de workflow for implementado.

> 📌 **`customers`, `itens`, `grupos_solucao` e `users` ganharam checagem de dependentes no
> `destroy()`** por causa do `restrict` de `incidentes` (mesmo padrão 409 já usado em
> `Client`/`Categoria`/etc.) — bug real pego durante esta implementação: sem a checagem
> explícita, excluir um desses registros com `incidentes` vinculados quebrava com
> `QueryException` bruta (500) em vez de `409` limpo, igual ao que já tinha acontecido com
> `Subcategoria`→`Item` (ver §3.4.5).

#### `incidente_descricoes` (feed do incidente — substitui o campo `descricao` único)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `incidente_id` | `bigint` FK → `incidentes.id` | obrigatório; `onDelete('cascade')` — diferente do resto do schema (que usa `restrict`): esta linha não tem valor/sentido independente do `Incidente` que descreve, então some junto se o incidente sumir (nunca acontece via API, já que não há `DELETE` de `Incidente`, mas a FK cobre o caso de exclusão direta no banco) |
| `user_id` | `bigint` FK → `users.id` nullable | `onDelete('restrict')`; autor staff — quem escreveu o comentário, ou quem disparou a ação que gerou o `escalonamento` (nunca um valor "sistema") |
| `customer_id` | `bigint` FK → `customers.id` nullable | `onDelete('restrict')`; autor cliente — só a descrição de abertura feita pelo portal. **Exatamente um** de `user_id`/`customer_id` é preenchido: `CHECK incidente_descricoes_um_autor` no Postgres + guarda `saving` em `IncidenteDescricao` (vale também no SQLite). Entradas de cliente não são editáveis/excluíveis por ninguém (`ensureCanModify` compara com `user_id`) |
| `tipo` | `string` | um de `IncidenteDescricao::TIPOS` = `comentario` \| `escalonamento` \| `alteracao` \| `encerramento` (ver nota abaixo; `encerramento` = mensagem obrigatória de quem fecha o chamado, a única entrada do staff visível no portal — §3.4.10) |
| `descricao` | `text` | conteúdo — texto livre do agente (`comentario`) ou gerado automaticamente (`escalonamento`/`alteracao`) |
| `deleted_at` | `timestamp` nullable | soft delete (`SoftDeletes`) — excluir um `comentario` não apaga, só marca (auditoria); ver nota abaixo |
| `created_at`, `updated_at` | `timestamp` | |
| índice | `incidente_id` | não único, só performance de listagem do feed |

> 📌 **Por que virou tabela e não JSON:** cresce sem limite (chamado escalado várias vezes ao longo
> de semanas), precisa de FK de verdade com `users` (integridade referencial que JSON não garante),
> e precisa de `UPDATE`/`DELETE` de uma entrada específica sem reescrever um blob inteiro — mesmo
> raciocínio de `token_audit_logs` (hoje `audit_logs`, ver §3.4.11), que é o mesmo tipo de problema (quem fez o quê, quando, relacionado a quê).
>
> **`tipo = 'comentario'`**: criado explicitamente por um agente via `POST
> /incidentes/{incidente}/descricoes`. Editável/excluível **só pelo próprio autor**
> (`user_id === $request->user()->id`), sem limite de tempo — nem depois do incidente ser
> escalonado pra outro agente (decisão explícita: simplicidade sobre trava de auditoria mais
> restritiva, ver histórico da conversa que originou esta feature).
>
> **`tipo = 'escalonamento'`**: gerado **automaticamente** por `IncidenteController::update()`
> sempre que `grupo_solucao_id` e/ou `responsavel_id` mudam pra um valor não-nulo diferente do
> anterior (não loga quando o campo é apenas limpo/setado pra `null`) — texto no formato "Encaminhado
> para o grupo '{nome}' às {hora} do dia {data}." ou "Atribuído para {nome} às {hora} do dia {data}.".
> Se os dois campos mudam no mesmo `PUT`, geram **duas** entradas separadas, não uma combinada.
> **Nunca** editável/excluível por ninguém via API, nem pelo autor — é log de sistema, não anotação
> de agente (`IncidenteDescricaoController::ensureCanModify()` bloqueia com `403` incondicionalmente
> quando `tipo !== 'comentario'`).
>
> **`{hora}`/`{data}` no texto são horário de Brasília, não UTC.** `config('app.timezone')` do app é
> `UTC` (mantido de propósito — `created_at`/`updated_at`/cálculo de prazo de SLA continuam em UTC,
> não é pra mexer nisso). Só o texto exibido nas mensagens de `escalonamento`/`alteracao` é gerado via
> `IncidenteController::agoraLocal()` (`now('America/Sao_Paulo')`), porque é conteúdo lido por um
> agente no Brasil — sem isso, o log mostrava a hora UTC (3h à frente do horário local). Bug real
> encontrado depois da entrega inicial dessa feature.
>
> **`tipo = 'alteracao'`**: gerado **automaticamente** por `IncidenteController::update()` (helper
> `logAlteracaoSeMudou()`) pra qualquer campo "simples" que mudar num `PUT`/`PATCH` — `titulo`,
> `prioridade`, `origem`, `status`, `customer_id` e `item_id` — **fora** `grupo_solucao_id`/
> `responsavel_id`, que continuam gerando a entrada `escalonamento` mais específica ("Encaminhado
> para.../Atribuído para..."), não uma `alteracao` duplicada. Motivação: auditoria de ITSM completa —
> precisa dar pra reconstruir o histórico inteiro de mudanças de um chamado (quem mudou o quê,
> quando), não só reatribuições. Texto no formato "Campo '{nome}' alterado de '{antigo}' para
> '{novo}' às {hora} do dia {data}." — o prefixo "Campo '...'" é de propósito (evita ter que
> concordar género em português por campo: "Prioridade"/"Origem" são femininos, "Status"/"Título" não;
> "Campo" é sempre masculino, a frase concorda certo não importa qual campo mudou). Para
> `customer_id`/`item_id`, `{antigo}`/`{novo}` são os **nomes** resolvidos (`Customer.name`/
> `Item.nome`), não os ids crus; `item_id` nulo aparece como `'(nenhum)'`. Sem mudança de valor
> (reenviar o mesmo valor), **não gera entrada** — evita spam no feed. Múltiplos campos alterados no
> mesmo `PUT` geram uma entrada `alteracao` **por campo**, não uma combinada. Mesma trava de
> `403`/imutabilidade de `escalonamento` acima — `alteracao` também nunca é editável/excluível por
> ninguém.
>
> **Excluir um `comentario` é soft delete, de propósito — fica no feed, marcado.** Diferente de
> `Anexo`/`Client`/etc. (onde "excluir" remove de verdade ou bloqueia com `409`), aqui a exclusão
> preserva a linha (auditoria: o histórico de comunicação de um chamado não deve poder ser apagado
> de verdade por um agente). Efeitos concretos:
> - `Incidente::descricoes()` usa `->withTrashed()` — o comentário excluído **continua aparecendo**
>   na listagem do feed (`GET /incidentes/{incidente}/descricoes`), não só no banco.
> - `IncidenteDescricaoResource` ganhou `excluido_em` (= `deleted_at`, `null` se não excluído) — é
>   assim que o cliente da API sabe que aquela entrada foi excluída.
> - **Uma vez excluído, fica congelado**: `IncidenteDescricaoController::ensureCanModify()` bloqueia
>   com `403` uma segunda tentativa de `PUT` ou `DELETE` sobre o mesmo comentário (mesmo pelo
>   próprio autor) — não dá pra "reeditar" nem "re-excluir".
> - `IncidenteDescricao::resolveRouteBinding()` foi sobrescrito pra também resolver via
>   `withTrashed()` — sem isso, o binding de rota trataria um comentário já excluído como
>   inexistente (`404`) em vez de aplicar as regras acima.
> - O texto do comentário **não é redigido/ocultado** ao ser excluído — continua visível como
>   estava, só com `excluido_em` preenchido. Não foi pedido um "conteúdo removido" genérico no lugar.

#### `anexos` (arquivos anexados a um incidente)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `incidente_id` | `bigint` FK → `incidentes.id` | obrigatório; `onDelete('cascade')` — mesmo raciocínio de `incidente_descricoes`: registro sem vida própria fora do incidente |
| `user_id` | `bigint` FK → `users.id` | obrigatório; `onDelete('restrict')`; quem enviou o arquivo |
| `nome_original` | `string` | nome do arquivo como enviado pelo cliente (exibição/download) |
| `caminho` | `string` | nome/caminho gerado no disco ao salvar (`Storage::store()`) — **não** é o `nome_original`, evita colisão e path traversal; não é fillable, nunca vem do request |
| `mime_type` | `string` | detectado via magic number (`UploadedFile::getMimeType()`, `finfo` nos bytes reais), nunca o `Content-Type` declarado pelo cliente — é esse mesmo valor que `AnexoController::validarConteudoRealDoArquivo()` valida contra a extensão no `store()`, ver §3.4.7.2 |
| `tamanho` | `bigint unsigned` | bytes, de `UploadedFile::getSize()` |
| `created_at`, `updated_at` | `timestamp` | |
| índice | `incidente_id` | não único, só performance de listagem |

> 📌 **Armazenamento em disco local por ora** (`Storage::disk('local')`, driver `local` do
> `config/filesystems.php`, raiz `storage/app/private`) — arquivo físico vive em
> `anexos/incidentes/{incidente_id}/{nome-gerado}`, fora da pasta pública (`storage/app/public`), só
> acessível via `GET /incidentes/{incidente}/anexos/{anexo}/download` autenticado. Trocar pra S3/cloud
> depois é uma troca de disk no `AnexoController` (`private const DISK`), sem mudança de schema. Ver
> §3.4.7.2.

#### `relatorios_salvos` (configuração de relatório salva pra reexecutar)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `user_id` | `bigint` FK → `users.id` | obrigatório; `onDelete('restrict')`; autor — sem guard extra em `UserController::destroy()`, `User` é soft delete (ver §3.4.3), a FK nunca é violada |
| `nome` | `string` | dado pelo usuário, ex. "SLA mensal por agente" |
| `filtros` | `json` | espelha o payload de `GET /relatorios/incidentes` (no formato novo também `periodo` = atalho `este_mes`\|`mes_passado`\|`ultimos_7_dias`, guardado como atalho e re-resolvido a cada execução, exclusivo com `data_inicio`/`data_fim`; status, tipo, data_inicio, data_fim, categoria_id, subcategoria_id, item_id, grupo_solucao_id, grupo_analista_id, responsavel_id, client_id, customer_id) — schema-less de propósito, evita coluna nova por filtro novo |
| `agrupar_por` | `string` | legado: um de `RelatorioSalvo::AGRUPAMENTOS`; com `contar`: agrupamento do caminho novo para aquele `contar` (§3.4.9 "Caminho novo") |
| `contar` | `string` nullable | `abertos`\|`concluidos`\|`resolvidos`\|`fechados`\|`cancelados`\|`encaminhamentos`. Nulo = relatório salvo antes da Fase 2 (sem backfill) |
| `quebra` | `string` nullable | `nenhuma`\|`dia`\|`semana`\|`mes`; nulo nos legados |
| `created_at`, `updated_at` | `timestamp` | |
| índice | `user_id` | não único, só performance |

> 📌 **Guarda a configuração, não um snapshot do resultado.** Decisão explícita: rodar de novo
> (`GET /relatorios-salvos/{id}/executar`) sempre reflete os dados atuais, nunca um resultado
> congelado do momento em que foi salvo — diferente, por exemplo, de `prazo_resposta`/
> `prazo_resolucao` em `Incidente`, que são deliberadamente congelados. Ver §3.4.9.

#### `incidente_eventos` (um registro por evento estruturado do Incidente)
| Coluna | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `incidente_id` | `bigint` FK → `incidentes.id` | obrigatório; `onDelete('cascade')` — mesmo raciocínio de `incidente_descricoes` |
| `user_id` | `bigint` FK → `users.id` nullable | `onDelete('restrict')`; quem fez a ação (`$request->user()` no momento do `PUT`). Nulo no evento `aberto` de chamado aberto pelo portal (o autor é `autor_customer_id`) e nos eventos retroativos de autor desconhecido (ver `retroativo`) |
| `autor_customer_id` | `bigint` FK → `customers.id` nullable | cliente que abriu o chamado pelo portal (evento `aberto`); `nullOnDelete()`, indexada. Migration `add_autoria_completa_to_incidente_eventos_table` |
| `tipo` | `string` | um de `IncidenteEvento::TIPOS` = `aberto`\|`resolvido`\|`fechado`\|`cancelado`\|`reaberto`\|`encaminhado_grupo`\|`encaminhado_responsavel`\|`removido_grupo`; `default('resolvido')` no schema só pra backfill da migration de generalização (ver nota), aplicação sempre seta explícito |
| `alvo_type`/`alvo_id` | `string` nullable / `bigint unsigned` nullable | alvo polimórfico do encaminhamento (`GrupoSolucao` ou `User`) — só preenchido pra `tipo` = `encaminhado_*`; `null` pra `resolvido`/`fechado`/`removido_grupo`, que não têm "destino" |
| `origem_grupo_id` | `bigint` FK → `grupos_solucao.id` nullable | grupo do chamado **antes** do `encaminhado_grupo` (o `grupo_solucao_id` desse evento já é o destino) ou, no `removido_grupo`, o grupo que foi limpo; `nullOnDelete()`, indexada; nulo nos demais tipos. Ao vivo: `IncidenteController::update` grava o grupo anterior do chamado (nulo = chamado sem grupo). Migration `add_origem_grupo_to_incidente_eventos_table` (só query builder, chunks de 500, idempotente só antes da entrada em produção — depois, rodar de novo sobrescreveria nulos reais de "Sem grupo": só toca `encaminhado_grupo` com origem nula, `retroativo` só é ligado), por chamado, em ordem de `created_at` e depois `id`: (1) há `encaminhado_grupo` anterior → origem = o `alvo_id` dele; se esse grupo foi excluído, origem nula e o evento vira `retroativo = true`; (2) senão, há `aberto` (o primeiro) → origem = o `grupo_solucao_id` dele; se nulo: portal (`autor_customer_id`) fica nulo sem marcar, `aberto` reconstruído (`retroativo`) marca o encaminhamento `retroativo = true` (origem desconhecida), `aberto` ao vivo fica nulo sem marcar (sem grupo, de fato); (3) sem `aberto` → nulo e `retroativo = true`. `down()` apaga os `removido_grupo` e remove a coluna (não desmarca `retroativo`) |
| `grupo_solucao_id` | `bigint` FK → `grupos_solucao.id` nullable | snapshot do grupo do incidente **no momento do evento**, preenchido pelo hook `creating` do model (só se não informado); `nullOnDelete()`, indexada. Backfill (migration `add_grupo_snapshot_to_incidente_eventos_table`): destino do `encaminhado_grupo` mais recente até o evento; senão, se não há encaminhamento posterior, o grupo atual do incidente; senão `null` (grupo inicial desconhecido — `store()` não gera evento de encaminhamento) |
| `autor_grupo_solucao_id` | `bigint` FK → `grupos_solucao.id` nullable | snapshot do grupo de quem executou a ação; mesmo hook/FK. Backfill é **aproximação**: grupo atual do usuário (o da época nunca foi guardado) |
| `retroativo` | `boolean` default `false` | `true` nos eventos reconstruídos (backfill), cujo grupo do autor é o atual da pessoa e não o da época; alimenta o aviso de "dados aproximados" dos relatórios. No `encaminhado_grupo` também quer dizer **origem desconhecida** (o Mapa de fluxos mostra `Origem desconhecida`): o backfill de `origem_grupo_id` marca assim os encaminhamentos cuja origem não dá pra reconstruir, inclusive encaminhamentos **ao vivo** (depois da Fase 1) em chamados cujo `aberto` reconstruído não tem grupo. Migration `backfill_eventos_abertura_cancelamento` (só query builder, chunks de 500, idempotente): (1) marca `true` os `resolvido`/`fechado`/`encaminhado_*` com `created_at` anterior ao deploy da Fase 1 (`2026-11-11 14:49:34` UTC, fim do job de deploy — melhor marcar a mais que a menos; constante na migration); (2) cria `aberto` pra todo incidente sem um (autor = `criado_por_id`/`criado_por_customer_id`, os dois nulos = desconhecido; `autor_grupo_solucao_id` = grupo atual do usuário; `grupo_solucao_id` = grupo atual do incidente se nunca houve `encaminhado_grupo`, senão `null`; `created_at` = `incidentes.created_at`, senão `updated_at`; sem nenhum dos dois o incidente fica sem `aberto`); (3) cria `cancelado`/`reaberto` a partir dos logs `alteracao` de `incidente_descricoes` ("Campo 'Status' alterado de 'X' para 'Y'": `cancelado` se Y = cancelado; `reaberto` se X ∈ resolvido/fechado/cancelado e Y não) com autor = autor do log, grupo do chamado pela regra do backfill da Fase 1 e `created_at` do log (log sem `created_at` é ignorado), sem repetir evento do mesmo tipo/incidente/`created_at`; (4) incidente `cancelado` ainda sem evento `cancelado` ganha um sem autor, com o grupo atual e `created_at` = `concluido_em`, senão `updated_at`, senão `incidentes.created_at` (sem nenhuma das três, fica sem `cancelado`). Todos os criados têm `retroativo = true` |
| `created_at` | `timestamp` | quando o evento aconteceu; **sem `updated_at`** (`const UPDATED_AT = null`, mesmo padrão de `AuditLog`) — a linha nunca é alterada depois de criada |
| índice | `incidente_id`, `[incidente_id, tipo]`, `autor_customer_id`, `[tipo, created_at]`, `origem_grupo_id` | não únicos, só performance |

> 📌 **Log de eventos, não uma coluna em `Incidente` — de propósito.** Motivação original: um
> chamado resolvido, reaberto (volta pra `em_andamento`/`aberto`/`pendente`) e resolvido de novo —
> por agentes diferentes ou não — precisa preservar **as duas** resoluções num relatório, não só a
> mais recente. Uma coluna única tipo `Incidente.resolvido_por_id` só conseguiria guardar um valor
> por vez, e teria que decidir entre (a) ser limpa na reabertura (mesmo padrão de `concluido_em`,
> perderia a resolução anterior) ou (b) nunca ser limpa (mostraria só a *primeira* resolução, não a
> mais recente nem o histórico completo) — as duas opções perdem informação. Uma tabela
> insert-only, um registro por evento, não perde nada.
>
> **Generalizada de `incidente_resolucoes` (só cobria `resolvido`) assim que apareceu um segundo
> pedido de indicador do mesmo tipo** (`fechado_por`, depois `encaminhado_por`/`encaminhado_para_*`) —
> nesse ponto compensou consolidar num mecanismo só em vez de uma tabela nova por ação (o que criaria
> várias tabelas quase idênticas, só o nome mudando). A migration de generalização
> (`generalize_incidente_resolucoes_to_incidente_eventos_table`) **renomeia** a tabela existente
> (`Schema::rename`) em vez de criar uma nova — é uma migration nova, não uma edição da migration
> original (`create_incidente_resolucoes_table`), já commitada — e adiciona `tipo`/`alvo_type`/
> `alvo_id`. `criado_por_id`/`aberto_por` (ver `incidentes.criado_por_id` abaixo) **não** entram
> nessa tabela — abertura só acontece uma vez por incidente, não é um evento repetível como
> resolvido/fechado/encaminhamento, uma coluna direta em `Incidente` já resolve sem overhead de
> tabela.
>
> `IncidenteController::registrarEventoDeStatus()` cria a linha pra `resolvido`/`fechado`/`cancelado`
> (tipo = status de destino) e pra `reaberto` (saída de um status concluído pra um não-concluído);
> uma transição nunca gera dois eventos (`resolvido → fechado` gera só `fechado`), e status igual não
> gera nenhum; autor = usuário autenticado; `logEscalonamentoSeMudou()` cria a linha pra `encaminhado_grupo`/`encaminhado_responsavel`
> **junto com** a entrada de texto livre no feed (`IncidenteDescricao` tipo `escalonamento`) — as
> duas convivem, servem propósitos diferentes (feed = leitura humana no histórico do chamado; esta
> tabela = agregação em relatório, ver §3.4.9). No `encaminhado_grupo` grava também `origem_grupo_id` = grupo
> anterior do chamado (o do responsável não muda). Quando o grupo é limpo (A → nenhum),
> `registrarRemocaoDeGrupo()` grava um `removido_grupo` com `origem_grupo_id = A`, sem alvo, autor =
> usuário autenticado e **sem** entrada no feed; os relatórios existentes filtram por tipo e o ignoram.

### 3.2. Models e relacionamentos

| Model | Traits/relations relevantes |
|---|---|
| `App\Models\User` | `HasApiTokens`, `Notifiable`, `SoftDeletes` (`deleted_at` — "excluir" = desativar, ver §3.4.3); `roles(): BelongsToMany` → `Role`; `grupoSolucao(): BelongsTo` → `GrupoSolucao`; `incidentesResponsavel(): HasMany` → `Incidente` (FK `responsavel_id`); `anexos(): HasMany` → `Anexo`; `hasPermission(string $slug): bool` (via `loadMissing('roles.permissions')`); `scopeFiltros(array $filtros): Builder` — `name`/`email` busca parcial (`LIKE`), `grupo_solucao_id` igualdade exata, `role_id` via `whereHas('roles', ...)`, ver §3.4.3 |
| `App\Models\Client` | `customers(): HasMany` → `Customer`; `politicasSla(): HasMany` → `PoliticaSla`; `resolvedSlaFor(string $prioridade): ?PoliticaSla` (resolução padrão-por-prioridade, ver §3.1); `scopeFiltros(array $filtros): Builder` — só `name`, busca parcial (`LIKE '%valor%'`), ver §3.4.2 |
| `App\Models\Customer` | `HasApiTokens`, `Notifiable`; `client(): BelongsTo` → `Client`; `incidentes(): HasMany` → `Incidente`; **sem** `roles()`/`hasPermission()`; `scopeFiltros(array $filtros): Builder` — `name`/`email` busca parcial (`LIKE`), `client_id` igualdade exata, ver §3.4.3 |
| `App\Models\Role` | `permissions(): BelongsToMany` → `Permission`; `users(): BelongsToMany` → `User` |
| `App\Models\Permission` | `roles(): BelongsToMany` → `Role` |
| `App\Models\AuditLog` | `MassPrunable` (`prunable()`: `created_at` anterior a `RETENCAO_MESES`); `const UPDATED_AT = null`; `const ACOES` (slug → rótulo), `const ENTIDADES` (alias → [classe, rótulo]); `aliasDe(Model): ?string`; `scopeFiltros(array)` — período obrigatório (dias de Brasília via `PeriodoRelatorio`), `user_id`/`customer_id`, `ator_type`, `acao[]`, `entidade_type`, `entidade_id`, `ip_address`, `busca` (`LOWER(...) LIKE` em `ator_nome`/`entidade_label`, roda em Postgres e SQLite). Gravado por `App\Models\Concerns\Auditavel` (trait nos models de cadastro/chamado) e `App\Support\Auditoria` — ver §3.4.11 |
| `App\Models\PoliticaSla` | `$table = 'politicas_sla'` (pluralização automática do Eloquent não bate com o nome em português); `client(): BelongsTo` → `Client`; `const PRIORIDADES = ['baixa','media','alta','urgente']`; `scopeFiltros(array $filtros): Builder` — `nome` (parcial), `prioridade` (exata), `ativo`/`apenas_horas_uteis` (exatos, via `array_key_exists()` pra não perder `false` explícito), `client_id` (exato, com sentinel `'global'` pra `client_id IS NULL`), ver §3.4.4 |
| `App\Models\Categoria` | `subcategorias(): HasMany` → `Subcategoria` (sem `$table` explícito — `categoria`→`categorias` é o único caso em português onde a pluralização automática do Eloquent acerta por coincidência); `scopeFiltros(array $filtros): Builder` — `nome` (LIKE parcial), `ativo` (via `array_key_exists`, não `??`, ver §3.4.5) |
| `App\Models\Subcategoria` | `categoria(): BelongsTo` → `Categoria`; `itens(): HasMany` → `Item`; `scopeFiltros(array $filtros): Builder` — `nome` (LIKE parcial), `categoria_id` (igualdade exata), `ativo` (via `array_key_exists`) |
| `App\Models\GrupoSolucao` | `$table = 'grupos_solucao'` (mesma pegadinha de pluralização de `PoliticaSla`); `users(): HasMany` → `User`; `incidentes(): HasMany` → `Incidente`; `scopeFiltros(array $filtros): Builder` — `nome` busca parcial (`LIKE '%valor%'`, mesmo estilo de `Client::scopeFiltros()`), `ativo` igualdade exata via `array_key_exists()` (não `?? null` — `false ?? null` também vira `false` e faria `when()` pular o filtro, ver §3.4.6) |
| `App\Models\Item` | `$table = 'itens'` (pluralização automática do Eloquent faria `items`, em inglês); `subcategoria(): BelongsTo` → `Subcategoria`; `incidentes(): HasMany` → `Incidente`; `scopeFiltros(array $filtros): Builder` — `nome` (LIKE parcial), `subcategoria_id` (igualdade exata), `ativo` (via `array_key_exists`) |
| `App\Models\Incidente` | sem `$table` explícito (`incidente`→`incidentes` acerta por coincidência, como `Categoria`); `customer(): BelongsTo` → `Customer`; `item(): BelongsTo` → `Item`; `grupoSolucao(): BelongsTo` → `GrupoSolucao`; `responsavel(): BelongsTo` → `User` (FK `responsavel_id`, `->withTrashed()` — continua resolvendo o nome mesmo se o `User` for desativado); `criadoPor(): BelongsTo` → `User` (FK `criado_por_id`, `->withTrashed()` — quem abriu, nunca muda, ver §3.1); `booted()` com hook `saved`: quando `criado_por_id` ou `criado_por_customer_id` passa de nulo a preenchido (`wasChanged`; o criador é gravado num 2º `save()` fora do `#[Fillable]`) e ainda não há evento `aberto`, grava o evento `aberto` (`user_id`/`autor_customer_id` = criador, `grupo_solucao_id` = grupo do chamado, `created_at` = o do chamado; o `autor_grupo_solucao_id` vem do hook `creating` de `IncidenteEvento`); `descricoes(): HasMany` → `IncidenteDescricao` (`->withTrashed()->latest()` — comentários excluídos continuam no feed, ver §3.1); `anexos(): HasMany` → `Anexo` (`->latest()`); `const STATUSES`, `const ORIGENS`, `const STATUS_CONCLUIDOS`, `const STATUS_ATENDIDOS` (`['resolvido', 'fechado']` — exclui `cancelado`; restrição implícita dos relatórios de desempenho, ver §3.4.9), `const SLA_DENTRO_PRAZO`/`SLA_ESTOURADO`/`SLA_SEM_SLA`; `statusSlaResposta()`/`statusSlaResolucao(): string`; `tempoRestanteRespostaMinutos()`/`tempoRestanteResolucaoMinutos(): ?int` (ver §3.1); `scopeFiltros(array $filtros): Builder` — filtro composto por igualdade exata (`numero` — na prática `id`, só aceito por `DashboardController`, ver §3.4.8 —, `status`, `prioridade`, `origem`, `customer_id`, `item_id`, `grupo_solucao_id`, `responsavel_id`, `todos_status` — bool, só aceito por `DashboardController`, ver §3.4.8), cada chave opcional, combinadas com AND; sem `status` explícito, aplica `whereIn('status', STATUSES_PADRAO_LISTAGEM)` (`['aberto', 'em_andamento', 'pendente']`) por padrão, a menos que `todos_status` seja truthy (ver §3.4.7 e §3.4.8); `const STATUSES_PADRAO_LISTAGEM`; `scopeOrdenarPor(?string $sortBy, string $sortDir = 'asc'): Builder` — ordenação por coluna clicável, só aceita por `DashboardController` (ver §3.4.8), `const SORTABLE_COLUMNS`; `scopeFiltrosRelatorio(array $filtros): Builder` — separado de `scopeFiltros()`, sem a restrição padrão de status, com `data_inicio`/`data_fim` (sobre `concluido_em`), `categoria_id`/`subcategoria_id` (via `whereHas('item.subcategoria')`), `client_id` (via `whereHas('customer')`), `grupo_analista_id` (via `whereHas('responsavel')` — grupo **atual** do responsável) — ver §3.4.9 |
| `App\Models\IncidenteDescricao` | `$table = 'incidente_descricoes'` (pluralização automática do Eloquent daria `incidente_descricaos`); `SoftDeletes` (`deleted_at` — excluir = soft delete, permanece no feed, ver §3.1); `resolveRouteBinding()` sobrescrito com `withTrashed()` (senão um comentário já excluído resolveria como `404` na rota); `incidente(): BelongsTo` → `Incidente`; `user(): BelongsTo` → `User` (`->withTrashed()`); `const TIPO_COMENTARIO`, `const TIPO_ESCALONAMENTO`, `const TIPO_ALTERACAO`, `const TIPO_ENCERRAMENTO`, `const TIPOS` |
| `App\Models\Anexo` | sem `$table` explícito (`anexo`→`anexos` acerta por coincidência); `incidente(): BelongsTo` → `Incidente`; `user(): BelongsTo` → `User` (`->withTrashed()`); `caminho` fora do `#[Fillable(...)]` de propósito (setado por atribuição direta no controller, nunca vem do request — ver §3.4.7.2) |
| `App\Models\RelatorioSalvo` | `$table = 'relatorios_salvos'` (pluralização automática do Eloquent daria `relatorio_salvos`, só o último termo); `user(): BelongsTo` → `User` (`->withTrashed()`); `filtros` cast `array`; `const EQUIVALENTES_LEGADO` (legado → `[contar, agrupar_por]` novo), `consultaEquivalente(): ?array` (null para `aberto_por` com `data_inicio`/`data_fim`, ou com só uma das duas datas), `consultaNova(): ?array` (armazenada ou equivalente); `const CONTAR_INDICADORES = 'indicadores'`; `const AGRUPAMENTOS` = `status_sla`\|`responsavel`\|`aberto_por`\|`resolvido_por`\|`fechado_por`\|`concluido_por`\|`encaminhado_por`\|`encaminhado_para_grupo`\|`encaminhado_para_responsavel`\|`grupo_solucao`\|`categoria`\|`subcategoria`\|`item` |
| `App\Models\IncidenteEvento` | `$table = 'incidente_eventos'` (explícito por consistência com o resto do projeto, mesmo pluralizando certo por coincidência); `const UPDATED_AT = null` (linha nunca atualizada); `const TIPO_ABERTO`/`TIPO_CANCELADO`/`TIPO_REABERTO`/`TIPO_RESOLVIDO`/`TIPO_FECHADO`/`TIPO_ENCAMINHADO_GRUPO`/`TIPO_ENCAMINHADO_RESPONSAVEL`/`TIPO_REMOVIDO_GRUPO`, `const TIPOS`; `incidente(): BelongsTo` → `Incidente`; `user(): BelongsTo` → `User` (`->withTrashed()`); `booted()` com hook `creating` que grava o snapshot `grupo_solucao_id` (grupo do chamado no momento do evento) e `autor_grupo_solucao_id` (grupo do autor, `withTrashed()`; nulo se `user_id` é nulo) quando não informados; `origem_grupo_id`, `autor_customer_id` e `retroativo` (cast boolean) no `#[Fillable]` — o evento é insert-only, então o snapshot não muda; `alvo(): ?Model` — resolve `alvo_type`/`alvo_id` manualmente (não `morphTo()`, pra não depender do comportamento de `withTrashed()` numa relação polimórfica onde só um dos tipos possíveis, `User`, é soft delete); `scopeFiltrosRelatorio(array $filtros): Builder` — mesmas dimensões de `Incidente::scopeFiltrosRelatorio()`, via `whereHas('incidente')`/`whereHas('incidente.item')`/etc. (base é `IncidenteEvento`, não `Incidente`); `data_inicio`/`data_fim` filtram `created_at` **desta tabela** (quando o evento aconteceu), não `Incidente.concluido_em`; o filtro `grupo_solucao_id` usa a coluna snapshot `grupo_solucao_id` do evento e `grupo_analista_id` usa `autor_grupo_solucao_id`; `scopeVisiveisPara()` continua usando o grupo **atual** do incidente (caminho legado); `scopeVisiveisParaRelatorio(User)` é a regra do caminho novo (chamado visível hoje OU snapshot `grupo_solucao_id`/`autor_grupo_solucao_id` nos grupos visíveis, admin sem restrição — ver §3.4.9) |

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
| `GET` | `/me` | `web,customer` | `auth:web,customer` | — | `User` (via `UserResource`, ver 3.4.1) ou `Customer` puro + `client: {id, name}` |
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

`Customer` não tem `UserResource` equivalente — é devolvido como model puro (sem roles/permissions, que não se aplicam a esse guard), com `client: {id, name}` carregado (o portal exibe a empresa na abertura de incidente). Vale para `/me` e para o `user` do login.

### 3.4.2. Endpoints — CRUD de `Client` (`App\Http\Controllers\Api\ClientController`)

`Route::apiResource('clients', ClientController::class)`, sob `auth:web` + `can:clients.manage` (só `User` com a permissão `clients.manage` — por padrão, só a role Admin a possui; `Customer` nunca autentica no guard `web`, então nunca acessa essas rotas).

| Método | Rota | Body | Resposta |
|---|---|---|---|
| `GET` | `/clients` | — | `200` — paginado, `{data: [{id, name, created_at, updated_at}, ...], links, meta}`; aceita `?name=` (`sometimes\|string`) — filtro por nome, busca parcial (`LIKE '%valor%'`, via `Client::scopeFiltros()`), não exige match exato |
| `POST` | `/clients` | `{name}` | `201` — `{data: {id, name, created_at, updated_at}}` |
| `GET` | `/clients/{client}` | — | `200` — `{data: {...}}` |
| `PUT`/`PATCH` | `/clients/{client}` | `{name}` | `200` — `{data: {...}}` |
| `DELETE` | `/clients/{client}` | — | `204` sem corpo; **`409`** se o `Client` ainda tiver `Customer`s vinculados (checado explicitamente no controller antes do `delete()`, não depende de capturar a exceção do banco) |

Todas as respostas passam por `App\Http\Resources\ClientResource` (mesmo formato do model, sem campos extras — existe como Resource, não serialização crua, só para manter o padrão de envelope `{data: ...}` do restante da API).

Validação: `name` ausente/vazio → `422`; sem token → `401`; `User` autenticado sem a permissão `clients.manage` → `403`.

### 3.4.3. Endpoints — CRUD de `User` e `Customer`, e listagem de `Role`

> 📌 **Se `/api/users`, `/api/customers` ou `/api/roles` responderem `404` mesmo com
> `php artisan route:list` mostrando a rota registrada:** o worker do Octane que já estava de pé
> subiu antes dessas rotas existirem no código e continua servindo o router antigo em memória (ver
> aviso geral na seção 1.1). Rode `docker compose exec app php artisan octane:reload` (ou
> `docker compose restart app`) para o worker recarregar o código atual — aconteceu exatamente isso
> ao testar manualmente este CRUD pela primeira vez.

`Route::apiResource('users', UserController::class)` + `Route::get('/roles', ...)`, ambas sob
`auth:web` + `can:users.manage`.

| Método | Rota | Body | Resposta |
|---|---|---|---|
| `GET` | `/users` | — | `200` — paginado (`?per_page=`), `UserResource::collection` com `roles`/`permissions`/`grupoSolucao` carregados; aceita filtros opcionais `?name=` (`LIKE` parcial), `?email=` (`LIKE` parcial), `?grupo_solucao_id=` (`exists:grupos_solucao,id`), `?role_id=` (`exists:roles,id`, via `User::scopeFiltros()`/`whereHas('roles', ...)`) |
| `POST` | `/users` | `{name, email, password?, grupo_solucao_id, role_ids?: number[]}` | `201` — `UserResource`; `password` opcional (ver 3.4.3.1) |
| `GET` | `/users/{user}` | — | `200` — `UserResource` |
| `PUT`/`PATCH` | `/users/{user}` | `{name, email, password?, grupo_solucao_id, role_ids?: number[]}` | `200` — `UserResource`; `password` omitido/vazio mantém o hash atual |
| `DELETE` | `/users/{user}` | — | **desativa** (soft delete), não apaga — `204`; **`409`** só se `$user->id` for o do próprio autenticado |
| `POST` | `/users/{user}/convite` | — | `200` — `{message}`; envia e-mail de convite (ver 3.4.3.1) |
| `GET` | `/roles` | — | `200` — `{data: [{id, name, slug}, ...]}`, sem paginação |

> 📌 **`DELETE` desativa, não apaga.** `User` usa `SoftDeletes` (`deleted_at`) — o registro nunca
> some de verdade. Motivação: `responsavel_id` de `Incidente`, `user_id` de `IncidenteDescricao` e de
> `Anexo` são **históricos** — apagar o `User` de verdade quebraria essas referências (era exatamente
> o bug: `user_id`/`responsavel_id` são `restrictOnDelete()`, então excluir um usuário com qualquer
> uma dessas referências jogava um `QueryException` cru, 500). Soft delete resolve isso de graça: a
> linha continua existindo, a FK nunca é violada. Efeitos concretos:
> - **Login passa a falhar** — `AuthController::login()` busca `User::query()->where('email', ...)`,
>   que já exclui registros com `deleted_at` setado via global scope padrão do `SoftDeletes`, sem
>   precisar de nenhum código extra pra isso.
> - **Some da listagem/`show`/`update`** — mesmo global scope; um `User` desativado devolve `404` em
>   `GET/PUT /users/{user}` a partir de agora (binding de rota padrão também respeita o scope).
> - **Continua aparecendo em incidentes/comentários/anexos antigos** — `Incidente::responsavel()`,
>   `IncidenteDescricao::user()` e `Anexo::user()` usam `->withTrashed()`, então o nome do usuário
>   desativado continua resolvendo normalmente nessas telas (só não aparece mais em endpoints que
>   listam/gerenciam `User` diretamente).
> - **E-mail continua reservado** — a validação `Rule::unique('users', 'email')` é uma query crua,
>   não passa pelo Eloquent/global scope, então o e-mail de um usuário desativado **não pode** ser
>   reusado por um novo cadastro. Decisão deliberada (evita colisão de identidade), não uma limitação
>   técnica não percebida.
> - **`UserResource` ganhou `ativo: bool`** (`is_null(deleted_at)`) — forma explícita do frontend
>   saber o estado sem precisar interpretar `deleted_at` bruto.
> - **Sem endpoint de reativação/restore ainda** — não foi pedido; um `User` desativado fica preso
>   nesse estado via API (reversível só direto no banco por ora). Fica documentado aqui como escopo
>   pendente, mesmo padrão de `tickets.assign`/`apenas_horas_uteis`.
> - **Sem filtro pra ver usuários desativados** — `index()` não ganhou um `?somente_inativos=` ou
>   afim; não foi pedido.

Validação de `User`: `name` obrigatório; `email` obrigatório, único (ignorando o próprio id no
update); `password` **opcional** (`min:8` se presente) tanto na criação quanto no update — na
criação, ausente = conta criada com um hash aleatório inutilizável, pendente de convite (ver
3.4.3.1); `grupo_solucao_id` **obrigatório** (`exists:grupos_solucao,id`) tanto na criação quanto no update —
diferente de `role_ids`, não tem comportamento "preserva se omitido" (é FK escalar obrigatória, não
coleção); `role_ids` opcional, array de ids existentes em `roles`. No `update()`, o comportamento de
`role_ids` é condicionado à presença da chave no payload (`array_key_exists('role_ids', $data)`): se
`role_ids` estiver ausente do request, as roles atuais do usuário não são tocadas; se vier como
array vazio (`role_ids: []`), todas as roles são removidas; se vier com valores, sincroniza
exatamente para esses ids (`roles()->sync()`). Corrigido no commit `b311d63` — antes, a ausência de
`role_ids` zerava as roles do usuário.

`App\Http\Resources\UserResource` ganhou `grupo_solucao_id` e `grupo_solucao: {id, nome} | null`
(`whenLoaded`), mesmo padrão de nesting do `client` em `CustomerResource`.

`Route::apiResource('customers', CustomerController::class)`, sob `auth:web` +
`can:customers.manage` (nova permission — `Customer` do guard `customer` não participa desse
gate, só `User` do guard `web` gerencia).

| Método | Rota | Body | Resposta |
|---|---|---|---|
| `GET` | `/customers` | — | `200` — paginado (`?per_page=`), `CustomerResource::collection` com `client` carregado; aceita filtros opcionais `?name=` (`LIKE` parcial), `?email=` (`LIKE` parcial), `?client_id=` (`exists:clients,id`, via `Customer::scopeFiltros()`) |
| `POST` | `/customers` | `{name, email, password?, client_id}` | `201` — `CustomerResource`; `password` opcional (ver 3.4.3.1) |
| `GET` | `/customers/{customer}` | — | `200` — `CustomerResource` |
| `PUT`/`PATCH` | `/customers/{customer}` | `{name, email, password?, client_id}` | `200` — `CustomerResource`; `password` omitido/vazio mantém o hash atual |
| `DELETE` | `/customers/{customer}` | — | `204`; **`409`** se o customer ainda tiver `Incidente`s vinculados |
| `POST` | `/customers/{customer}/convite` | — | `200` — `{message}`; envia e-mail de convite (ver 3.4.3.1) |

Validação de `Customer`: mesmas regras de `name`/`email`/`password` do `User` (email único na
tabela `customers`); `client_id` obrigatório, `exists:clients,id`.

`App\Http\Resources\CustomerResource`: `{id, name, email, email_verified_at, client: {id, name},
created_at, updated_at}`.

Todas as respostas seguem o mesmo envelope `{data: ...}` do `ClientController`. Validação: campo
ausente/inválido → `422`; sem token → `401`; sem a permission correspondente → `403`.

### 3.4.3.1. Convite de novo usuário/customer (`App\Notifications\ConviteUsuario`)

`POST /users/{user}/convite` e `POST /customers/{customer}/convite`, sob as mesmas
`can:users.manage`/`can:customers.manage` do resto do CRUD — sem throttle dedicado, diferente de
`/forgot-password`: aquele é público (qualquer um pode digitar um e-mail alheio, daí o rate limit
anti-enumeração/spam), este exige sessão de staff autenticado **com** a permission de gerenciar o
recurso, uma barreira já mais forte que throttle por IP.

> 📌 **Reaproveita o mesmo mecanismo do reset de senha (`Password::broker()->createToken()`),
> não implementa um `URL::signedRoute()` à parte.** Um convite é, na prática, um reset de senha
> "com boas-vindas" — o usuário/customer já existe no banco (com um hash de senha aleatório e
> inutilizável, nunca exposto), só falta ele mesmo definir a senha de verdade. Construir um segundo
> mecanismo de token/URL assinada só pra essa tela duplicaria toda a infraestrutura que já existe
> pro reset (broker por guard, tabela de tokens hasheados, expiração de 60min em `config/auth.php`,
> single-use) sem ganhar nenhuma propriedade de segurança a mais. `enviarConvite()` (em
> `UserController`/`CustomerController`) chama `Password::broker('users'|'customers')->createToken($model)`
> — o mesmo método que `PasswordBroker::sendResetLink()` usa internamente — e passa o token pra uma
> notificação própria, em vez do `Illuminate\Auth\Notifications\ResetPassword` padrão do Laravel
> (que teria o assunto/corpo errados pro contexto de "boas-vindas").
>
> **`App\Support\PasswordUrl::build($notifiable, $token)`** — construtor de URL extraído pra não
> duplicar a lógica "qual path do SPA bate com qual guard" (Customer → `/portal/reset-password`,
> User → `/reset-password`, ambos com `?token=&email=`) entre dois lugares: o
> `ResetPassword::createUrlUsing()` registrado em `AppServiceProvider::boot()` (fluxo de
> "esqueci minha senha") e `ConviteUsuario::toMail()` (este fluxo). **Sem tela nova no frontend** —
> o link do convite cai na mesma `ResetPasswordView.vue`/`PortalResetPasswordView` já existente
> (formulário "defina uma nova senha" funciona idêntico pros dois contextos, só muda o e-mail que
> levou o usuário até ali).
>
> **`App\Mail\ConviteUsuarioMail` + `resources/views/emails/convite.blade.php`** — Mailable/view
> próprios em vez do `MailMessage` padrão do Laravel (que renderiza o template Markdown genérico
> com o logo do Laravel). HTML puro com CSS inline (compatível com clientes de e-mail, que ignoram
> `<style>` externo), **sem nenhuma imagem/`<img>`** — pedido explícito — usando a mesma paleta do
> frontend (`gespriority_claude_front/src/plugins/vuetify.js`): laranja `#FF8C1A` (topo/botão),
> roxo `#7922B9` (link/detalhe), sem depender de asset nenhum carregado externamente. `via()` só
> declara o canal `mail` (sem `database`/broadcast — não existe centro de notificações no app ainda,
> seria escopo não pedido); `toMail()` seta `->to()` explicitamente porque, ao devolver um
> `Mailable` (não um `MailMessage`), o Notification não injeta o destinatário sozinho.
>
> **Senha na criação virou opcional na API pros dois models** (`User`/`Customer`) — antes era
> `required` no `store()`. Sem `password` no payload, o controller gera um hash aleatório de 40
> caracteres (`Hash::make(Str::password(40))`) — nunca exposto, nunca comunicado a ninguém — só
> pra satisfazer a coluna `NOT NULL`; a conta fica inutilizável até o convite ser enviado e aceito.
> Essa flexibilização é deliberadamente só da API: existe pra sustentar o botão "Enviar convite"
> (que precisa criar o registro sem senha antes de convidar), não pra abrir uma forma de criar conta
> travada por acidente — ver a checagem do lado do frontend logo abaixo.
>
> **Frontend**: `UserFormModal.vue`/`CustomerFormModal.vue` ganharam um botão "Enviar convite"
> (vira "Reenviar convite" em modo edição) ao lado de "Salvar". Em modo criação, clicar nele
> primeiro faz o `POST /users`\|`/customers` (sem senha, com os campos já preenchidos no
> formulário) e, com o id retornado, encadeia o `POST .../convite` — os dois numa ação só, pra não
> exigir que o admin clique "Salvar" e depois procure o usuário recém-criado numa segunda tela só
> pra convidar. Em modo edição, pula direto pro `POST .../convite` (o registro já existe). Testado
> manualmente via Playwright contra o backend real: criação sem senha, envio do e-mail (log
> conferido em `storage/logs/laravel.log`), mensagem de sucesso exibida no modal.
>
> **O botão "Salvar" continua exigindo senha na criação — só do lado do frontend.** A API aceita
> criar sem senha (necessário pro fluxo de convite acima), mas isso criaria uma armadilha se
> "Salvar" também aceitasse silenciosamente: o admin preenche o formulário, esquece de clicar em
> "Enviar convite" e clica em "Salvar" por hábito — o registro é criado com um hash aleatório que
> **ninguém** conhece e nenhum e-mail é disparado, uma conta travada sem aviso. `onSubmit()` nos
> dois modais barra isso antes do request (`if (!props.user && !password.value)`, mostra erro e não
> chama a API) — não dá pra expressar essa regra como validação de backend (a API não tem como saber se a
> chamada sem senha veio do fluxo de convite ou de um "Salvar" incompleto, ambos batem no mesmo
> `POST /users`), então a guarda vive no componente que sabe qual botão foi clicado.

### 3.4.4. Endpoints — CRUD de `PoliticaSla` (`App\Http\Controllers\Api\PoliticaSlaController`)

Diferente de `Client`/`Customer`/`User` (uma única permission cobrindo todo o CRUD), aqui leitura e
escrita têm permissions separadas — agente/supervisor precisam ver os alvos de SLA pra trabalhar
chamados, mas só admin cria/altera/exclui política:

```php
Route::middleware(['auth:web', 'can:slas.view'])
    ->apiResource('politicas-sla', PoliticaSlaController::class)
    ->parameters(['politicas-sla' => 'politica_sla'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:slas.manage'])
    ->apiResource('politicas-sla', PoliticaSlaController::class)
    ->parameters(['politicas-sla' => 'politica_sla'])
    ->only(['store', 'update', 'destroy']);
```

| Método | Rota | Permission | Body | Resposta |
|---|---|---|---|---|
| `GET` | `/politicas-sla` | `slas.view` | — (filtros via querystring, ver abaixo) | `200` — paginado (`?per_page=`), `{data: [...], links, meta}` |
| `GET` | `/politicas-sla/{politica_sla}` | `slas.view` | — | `200` — `{data: {...}}` |
| `POST` | `/politicas-sla` | `slas.manage` | `{nome, prioridade, tempo_resposta_minutos, tempo_resolucao_minutos, apenas_horas_uteis?, ativo?, client_id?}` | `201` — `{data: {...}}` |
| `PUT`/`PATCH` | `/politicas-sla/{politica_sla}` | `slas.manage` | mesmo body do `POST` | `200` — `{data: {...}}` |
| `DELETE` | `/politicas-sla/{politica_sla}` | `slas.manage` | — | `204` — sem trava adicional (nada referencia `politicas_sla` ainda; `Ticket` deve passar a apontar pra cá quando existir) |

Filtros de `GET /politicas-sla` (querystring, todos opcionais, combinados com AND via
`PoliticaSla::scopeFiltros()`, ver §3.2). Escopo deliberadamente enxuto — `tempo_resposta_minutos`/
`tempo_resolucao_minutos` ficam de fora de propósito, não são campo de busca/seleção de lista
fechada, fora de escopo desta entrega:

| Filtro | Validação | Comportamento |
|---|---|---|
| `nome` | `sometimes\|string` | busca parcial (`LIKE '%valor%'`), igual ao `Client::scopeFiltros()` |
| `prioridade` | `sometimes\|string\|Rule::in(PoliticaSla::PRIORIDADES)` | igualdade exata |
| `ativo` | `sometimes\|boolean` | igualdade exata — **detectado via `array_key_exists()`**, não `$filtros['ativo'] ?? null`: sendo booleano, `ativo=false` colidiria com "sem filtro" se usasse `??` (`false ?? null` avalia `false`, que `->when()` trata como ausente) |
| `apenas_horas_uteis` | `sometimes\|boolean` | igualdade exata — mesma ressalva de `array_key_exists()` que `ativo` |
| `client_id` | `sometimes` + closure customizada (ver abaixo) | três estados possíveis, não dois |

> 📌 **`client_id` tem um terceiro estado — sentinel `'global'`:** como `client_id` é nullable em
> `politicas_sla` (`null` = política "Global", aplicável a todo cliente sem override, ver
> `Client::resolvedSlaFor()` em §3.2), um filtro de igualdade comum não bastaria — precisa
> distinguir "sem filtro de cliente" (chave `client_id` ausente da querystring — mostra tudo) de
> "filtrar só as políticas Global" (`client_id IS NULL` — chave presente com o valor literal da
> string `'global'`) de "filtrar por um cliente específico" (chave presente com um id numérico
> existente em `clients`). A validação não usa `Rule::in`/`exists` puro porque `'global'` não é um
> id: é uma closure que aceita `'global'` sem checar `clients`, e pra qualquer outro valor exige
> `is_numeric` + `Client::whereKey($value)->exists()`, com `fail('Cliente inválido.')` caso
> contrário. O scope (`PoliticaSla::scopeFiltros()`) espelha essa mesma checagem: `$v === 'global'`
> vira `whereNull('client_id')`, qualquer outro valor vira `where('client_id', $v)`.

Validação (`PoliticaSlaController::rules()`):
- `nome`: obrigatório, string.
- `prioridade`: obrigatório, um de `PoliticaSla::PRIORIDADES`; **único por `(client_id, prioridade)`** via `Rule::unique('politicas_sla', 'prioridade')->where(...)` condicional (`whereNull('client_id')` quando o payload não traz `client_id`, `where('client_id', ...)` quando traz) — não dá pra ter duas políticas ativas* da mesma prioridade pro mesmo cliente (ou duas globais da mesma prioridade). *na prática a regra hoje não filtra por `ativo`, ver nota abaixo.
- `tempo_resposta_minutos`: obrigatório, inteiro ≥ 1.
- `tempo_resolucao_minutos`: obrigatório, inteiro ≥ 1, `gte:tempo_resposta_minutos`.
- `apenas_horas_uteis`, `ativo`: booleanos, opcionais — se omitidos, o banco aplica o default (`false`/`true`); o controller dá `refresh()` no model logo após o `create()` pra a resposta refletir esse default (sem o `refresh()`, o objeto em memória devolve `null` pros campos não enviados, mesmo o banco já tendo persistido o valor correto — bug pego no smoke test manual e coberto por teste).
- `client_id`: opcional, `exists:clients,id`.

`App\Http\Resources\PoliticaSlaResource`: `{id, nome, prioridade, tempo_resposta_minutos, tempo_resolucao_minutos, apenas_horas_uteis, ativo, client_id, created_at, updated_at}`.

> 📌 **Unicidade não filtra por `ativo`:** a regra de unicidade de `(client_id, prioridade)` conta
> qualquer registro existente, mesmo com `ativo = false` — hoje não é possível ter uma política
> "desativada" e criar uma nova ativa pra mesma prioridade/cliente sem excluir a antiga primeiro.
> Se esse fluxo (desativar e substituir) for necessário no futuro, a regra de unicidade precisa
> ganhar `->where('ativo', true)` no escopo.

### 3.4.5. Endpoints — CRUD de `Categoria`/`Subcategoria`/`Item` (`App\Http\Controllers\Api\{Categoria,Subcategoria,Item}Controller`)

Mesmo esquema view/manage do `PoliticaSlaController` — mas aqui **as três entidades compartilham as
mesmas permissions** (`categorias.view`/`categorias.manage`), já que é uma taxonomia só (3 níveis:
Categoria → Subcategoria → Item, ver §3.1) gerenciada numa tela só, sem motivo pra granularidade
separada por entidade:

```php
Route::middleware(['auth:web', 'can:categorias.view'])->group(function () {
    Route::apiResource('categorias', CategoriaController::class)->only(['index', 'show']);
    Route::apiResource('subcategorias', SubcategoriaController::class)->only(['index', 'show']);
});

Route::middleware(['auth:web', 'can:categorias.manage'])->group(function () {
    Route::apiResource('categorias', CategoriaController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('subcategorias', SubcategoriaController::class)->only(['store', 'update', 'destroy']);
});

Route::middleware(['auth:web', 'can:categorias.view'])
    ->apiResource('itens', ItemController::class)
    ->parameters(['itens' => 'item'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:categorias.manage'])
    ->apiResource('itens', ItemController::class)
    ->parameters(['itens' => 'item'])
    ->only(['store', 'update', 'destroy']);
```

| Método | Rota | Permission | Body | Resposta |
|---|---|---|---|---|
| `GET` | `/categorias` | `categorias.view` | — | `200` — paginado (`?per_page=`), filtrável (`?nome=`/`?ativo=`), `{data: [...], links, meta}` |
| `GET` | `/categorias/{categoria}` | `categorias.view` | — | `200` — `{data: {...}}` |
| `POST` | `/categorias` | `categorias.manage` | `{nome, ativo?}` | `201` — `{data: {...}}` |
| `PUT`/`PATCH` | `/categorias/{categoria}` | `categorias.manage` | mesmo body do `POST` | `200` — `{data: {...}}` |
| `DELETE` | `/categorias/{categoria}` | `categorias.manage` | — | `204`; **`409`** se a categoria ainda tiver `subcategorias` vinculadas |
| `GET` | `/subcategorias` | `categorias.view` | — | `200` — paginado (`?per_page=`), filtrável (`?nome=`/`?categoria_id=`/`?ativo=`), com `categoria` carregada (`{id, nome}`) |
| `GET` | `/subcategorias/{subcategoria}` | `categorias.view` | — | `200` — `{data: {..., categoria: {id, nome}}}` |
| `POST` | `/subcategorias` | `categorias.manage` | `{categoria_id, nome, ativo?}` | `201` — `{data: {...}}` |
| `PUT`/`PATCH` | `/subcategorias/{subcategoria}` | `categorias.manage` | mesmo body do `POST` | `200` — `{data: {...}}` |
| `DELETE` | `/subcategorias/{subcategoria}` | `categorias.manage` | — | `204`; **`409`** se a subcategoria ainda tiver `itens` vinculados |
| `GET` | `/itens` | `categorias.view` | — | `200` — paginado (`?per_page=`), filtrável (`?nome=`/`?subcategoria_id=`/`?ativo=`), com `subcategoria` carregada (`{id, nome}`) |
| `GET` | `/itens/{item}` | `categorias.view` | — | `200` — `{data: {..., subcategoria: {id, nome}}}` |
| `POST` | `/itens` | `categorias.manage` | `{subcategoria_id, nome, ativo?}` | `201` — `{data: {...}}` |
| `PUT`/`PATCH` | `/itens/{item}` | `categorias.manage` | mesmo body do `POST` | `200` — `{data: {...}}` |
| `DELETE` | `/itens/{item}` | `categorias.manage` | — | `204`; **`409`** se o item ainda tiver `Incidente`s vinculados |

Validação `Categoria`: `nome` obrigatório, **único** (`Rule::unique('categorias', 'nome')`, ignorando
o próprio id no update); `ativo` booleano opcional.

Validação `Subcategoria`: `categoria_id` obrigatório, `exists:categorias,id`; `nome` obrigatório,
**único por `(categoria_id, nome)`** (`Rule::unique('subcategorias', 'nome')->where('categoria_id', ...)`)
— duas categorias diferentes podem ter subcategorias com o mesmo nome, mas não a mesma categoria
duas vezes; `ativo` booleano opcional.

Validação `Item`: `subcategoria_id` obrigatório, `exists:subcategorias,id`; `nome` obrigatório,
**único por `(subcategoria_id, nome)`** (mesmo raciocínio de `Subcategoria`); `ativo` booleano
opcional. Todos os `create()` (`Categoria`, `Subcategoria`, `Item`) dão `refresh()`/`load(...)` antes
de montar a resposta, mesmo cuidado do `PoliticaSlaController` com defaults de banco.

`index()` de cada controller valida seus próprios filtros (`nome` sometimes|string; `categoria_id`/
`subcategoria_id` sometimes|integer|exists; `ativo` sometimes|boolean) e delega o `Builder` pro
respectivo `scopeFiltros()` do model (ver tabela de models em §3.3) — `nome` é `LIKE '%valor%'`
parcial, os FKs (`categoria_id` em `Subcategoria`, `subcategoria_id` em `Item`) são igualdade exata,
e `ativo` usa `array_key_exists('ativo', $filtros)` em vez de `$filtros['ativo'] ?? null`: como
`false ?? null` também avalia pra `false`, e `->when(false, ...)` não dispara o callback, um filtro
`ativo=false` explícito seria silenciosamente ignorado sem esse cuidado (mesmo padrão de
`PoliticaSla`/`GrupoSolucao`, ver §3.4.4/§3.4.6). Nenhum dos três aceita ordenação — só filtro.

`App\Http\Resources\CategoriaResource`: `{id, nome, ativo, created_at, updated_at}`.
`App\Http\Resources\SubcategoriaResource`: `{id, categoria_id, nome, ativo, categoria: {id, nome} | null, created_at, updated_at}`.
`App\Http\Resources\ItemResource`: `{id, subcategoria_id, nome, ativo, subcategoria: {id, nome} | null, created_at, updated_at}`.

> 📌 Parâmetro de rota forçado para `item` via `->parameters(['itens' => 'item'])` — o
> singularizador do Laravel errou sozinho (`Str::singular('itens')` deu `iten`, não `item`), mesmo
> tipo de cuidado do `politica_sla`/`grupo_solucao` em §3.4.4/§3.4.6, mas aqui o erro veio de uma
> palavra em português sem hífen, não de um nome composto.

### 3.4.6. Endpoints — CRUD de `GrupoSolucao` (`App\Http\Controllers\Api\GrupoSolucaoController`)

Mesmo esquema view/manage dos demais cadastros (`grupos_solucao.view` / `grupos_solucao.manage`,
admin tem as duas via sync completo):

```php
Route::middleware(['auth:web', 'can:grupos_solucao.view'])
    ->apiResource('grupos-solucao', GrupoSolucaoController::class)
    ->parameters(['grupos-solucao' => 'grupo_solucao'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:grupos_solucao.manage'])
    ->apiResource('grupos-solucao', GrupoSolucaoController::class)
    ->parameters(['grupos-solucao' => 'grupo_solucao'])
    ->only(['store', 'update', 'destroy']);
```

| Método | Rota | Permission | Body | Resposta |
|---|---|---|---|---|
| `GET` | `/grupos-solucao` | `grupos_solucao.view` | — | `200` — paginado (`?per_page=`), filtrável (`GrupoSolucao::scopeFiltros()`): `?nome=` (`sometimes\|string`, `LIKE '%valor%'`, busca parcial) e `?ativo=` (`sometimes\|boolean`, igualdade exata — `false` filtra de verdade, não é ignorado), `{data: [...], links, meta}` |
| `GET` | `/grupos-solucao/{grupo_solucao}` | `grupos_solucao.view` | — | `200` — `{data: {...}}` |
| `POST` | `/grupos-solucao` | `grupos_solucao.manage` | `{nome, ativo?}` | `201` — `{data: {...}}` |
| `PUT`/`PATCH` | `/grupos-solucao/{grupo_solucao}` | `grupos_solucao.manage` | mesmo body do `POST` | `200` — `{data: {...}}` |
| `DELETE` | `/grupos-solucao/{grupo_solucao}` | `grupos_solucao.manage` | — | `204`; **`409`** se o grupo ainda tiver `User`s **ou** `Incidente`s vinculados |

Validação: `nome` obrigatório, **único** (`Rule::unique('grupos_solucao', 'nome')`, ignorando o
próprio id no update); `ativo` booleano opcional. `App\Http\Resources\GrupoSolucaoResource`:
`{id, nome, ativo, created_at, updated_at}`.

> 📌 Parâmetro de rota forçado para `grupo_solucao` via `->parameters()`, mesmo motivo do
> `politica_sla` em §3.4.4: `Str::singular()` do Laravel não lida de forma confiável com nomes
> compostos em português com hífen (`grupos-solucao`).

### 3.4.7. Endpoints — CRUD de `Incidente` (`App\Http\Controllers\Api\IncidenteController`)

**Só staff (guard `web`)** pode criar/ver por enquanto. `Customer` abrir/ver o próprio incidente
pelo portal (guard `customer`, Policy checando `Incidente::customer_id === $customer->id`, conforme
a seção 1.3 já antecipava) fica pra uma etapa futura — decisão explícita pra manter esta entrega no
mesmo escopo dos demais cadastros. **O cálculo de prazos de SLA já está implementado** (`store()`
chama `Client::resolvedSlaFor()` e persiste `prazo_resposta`/`prazo_resolucao` — ver §3.1), mas isso
é diferente de uma "relação" navegável entre `Incidente` e `PoliticaSla`: não há FK entre as duas
tabelas, o incidente só guarda o resultado congelado do cálculo.

```php
Route::middleware(['auth:web', 'can:tickets.view'])
    ->apiResource('incidentes', IncidenteController::class)
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:tickets.manage'])
    ->apiResource('incidentes', IncidenteController::class)
    ->only(['store', 'update']);
```

**Sem rota de exclusão** (`only` não inclui `destroy`) — incidente é registro histórico, "encerra"
via `status` (`resolvido`/`fechado`/`cancelado`), nunca via `DELETE`. Como `GET`/`PUT` já registram
`/incidentes/{incidente}`, um `DELETE` nessa URL responde **`405`** (verbo não permitido), não `404`.

| Método | Rota | Permission | Body | Resposta |
|---|---|---|---|---|
| `GET` | `/incidentes` | `tickets.view` | — | `200` — paginado (`?per_page=`), filtrável (ver abaixo), ordenado por mais recente, com `customer`/`item`/`grupoSolucao`/`responsavel` carregados |
| `GET` | `/incidentes/{incidente}` | `tickets.view` | — | `200` — `{data: {...}}` |
| `POST` | `/incidentes` | `tickets.manage` | `{customer_id, titulo, descricao, prioridade, origem, tipo?, item_id?, grupo_solucao_id?, responsavel_id?}` | `201` — `{data: {...}}`; `status` sempre `"aberto"`, **qualquer `status` enviado no payload é ignorado** |
| `PUT`/`PATCH` | `/incidentes/{incidente}` | `tickets.manage` | qualquer subconjunto de `{customer_id, titulo, prioridade, origem, tipo, item_id, grupo_solucao_id, responsavel_id, status, mensagem_encerramento}` — `mensagem_encerramento` é **obrigatória quando o status muda para `fechado`** (`422` sem ela) e vira uma entrada `encerramento` no feed, autor = quem fechou; fora dessa transição é ignorada | `200` — `{data: {...}}` |

**Filtro por query string (`GET /incidentes`)** — todos opcionais, combinados com AND
(`Incidente::scopeFiltros()`, ver §3.2, reaproveitado por §3.4.8):

| Query param | Validação |
|---|---|
| `status` | `Rule::in(Incidente::STATUSES)` |
| `prioridade` | `Rule::in(PoliticaSla::PRIORIDADES)` |
| `origem` | `Rule::in(Incidente::ORIGENS)` |
| `tipo` | `Rule::in(Incidente::TIPOS)` |
| `customer_id` | `exists:customers,id` |
| `item_id` | `exists:itens,id` |
| `grupo_solucao_id` | `exists:grupos_solucao,id` |
| `responsavel_id` | `exists:users,id` |

Ex.: `GET /incidentes?status=aberto&prioridade=alta&grupo_solucao_id=2`. Valor inválido (enum errado
ou id inexistente) → `422`, mesmo comportamento de validação do resto da API — não retorna lista
vazia silenciosamente pra um filtro errado.

**Sem `status` explícito, a listagem é restrita por padrão a `aberto`+`em_andamento`+`pendente`**
(`Incidente::STATUSES_PADRAO_LISTAGEM`) — os demais status (`resolvido`, `fechado`,
`cancelado`) ficam de fora até que o chamador informe um `status` explicitamente (ex.:
`?status=resolvido` mostra só os resolvidos, não soma aos padrão). Os outros filtros (`prioridade`,
`origem`, `customer_id`, etc.) não afetam essa restrição — só um `status` explícito a substitui (ou,
exclusivo de `GET /dashboard/incidentes`, `todos_status=1` — ver §3.4.8).
Decisão do produto: a listagem "de trabalho" (o que a equipe precisa ver todo dia) não deve ficar
poluída por chamados já encerrados ou pausados por padrão.

Validação (`store`, campos sempre obrigatórios): `customer_id` (`exists:customers,id`), `titulo`,
`descricao`, `prioridade` (`Rule::in(PoliticaSla::PRIORIDADES)`), `origem`
(`Rule::in(Incidente::ORIGENS)`) obrigatórios; `item_id`/`grupo_solucao_id`/`responsavel_id`
opcionais (`exists` quando presentes). **`descricao` não é mais persistida como coluna do
`Incidente`** — vira a primeira entrada do feed (`incidente_descricoes`, `tipo = 'comentario'`,
autor = quem está autenticado), criada na mesma transação (`DB::transaction()`) do `INSERT` em
`incidentes`: se a criação da entrada do feed falhar, o incidente não fica órfão sem descrição
alguma. `update()` **não** aceita `descricao` — editar o feed é só via §3.4.7.1.

> 📌 **`update()` é update parcial de propósito — diferente de todos os outros cadastros deste
> projeto.** `Client`/`Categoria`/`PoliticaSla`/`GrupoSolucao` exigem reenviar o recurso inteiro a
> cada `PUT` (mesma regra de validação do `POST`). `Incidente` usa `'sometimes'` em todos os campos
> do `update()`: um agente frequentemente só quer mudar o `status` (ex. `{"status": "em_andamento"}`)
> sem precisar reenviar título/prioridade/origem toda vez. `status` só é validado
> (`Rule::in(Incidente::STATUSES)`) e aceito no `update()`, nunca no `store()`.

`App\Http\Resources\IncidenteResource`: `{id, titulo, prioridade, origem, status, customer_id,
customer: {id, name}, item_id, item: {id, nome} | null, grupo_solucao_id, grupo_solucao: {id, nome}
| null, responsavel_id, responsavel: {id, name} | null, prazo_resposta, prazo_resolucao,
respondido_em, concluido_em, created_at, updated_at}`. **Sem `descricao`** — o feed não vem embutido
aqui (poderia crescer muito), é buscado à parte via §3.4.7.1. `prazo_resposta`/`prazo_resolucao`/
`respondido_em`/`concluido_em` são os valores **brutos** (podem ser `null`); o `status_sla_*`/
`tempo_restante_*` **calculados** (prontos pra exibição) só aparecem no dashboard (§3.4.8) — este
endpoint devolve o dado cru do registro, não uma view computada.

> 📌 **`update()` também gera entradas `escalonamento`/`alteracao` automaticamente no feed** —
> `grupo_solucao_id`/`responsavel_id` mudando pra um valor não-nulo diferente do anterior geram
> `escalonamento`; `titulo`/`prioridade`/`origem`/`status`/`customer_id`/`item_id` mudando geram
> `alteracao` (uma entrada por campo, inclusive `status` — cobre explicitamente a transição pra
> `resolvido`/`fechado`, que antes não deixava rastro nenhum no feed). Ver §3.1
> (`incidente_descricoes`) pra formato do texto e regras completas. É a mesma chamada, mesma
> transação; não é uma ação/permission separada — continua exigindo só `tickets.manage`.
>
> **`update()` também grava em `IncidenteEvento`** — `registrarEventoDeStatus()` sempre
> que `status` transiciona pra `'resolvido'`/`'fechado'`/`'cancelado'` ou sai de um status concluído (`reaberto`); `logEscalonamentoSeMudou()` sempre que
> `grupo_solucao_id`/`responsavel_id` muda (junto com a entrada de feed, não em vez dela) — uma linha
> nova por evento, nunca atualiza/apaga uma existente, nem na reabertura. Ver §3.1
> (`incidente_eventos`) e §3.4.9 (`agrupar_por=resolvido_por`/`fechado_por`/`encaminhado_por`/
> `encaminhado_para_grupo`/`encaminhado_para_responsavel`) pro motivo/uso. `criado_por_id` é setado
> no `store()` (não no `update()`) — abertura só acontece uma vez, ver §3.1 (`incidentes`).
>
> **`tickets.assign` existe mas não é usado nesta entrega.** A permission já estava seedada desde
> o início do projeto (placeholder) com o nome "Atribuir chamados". `grupo_solucao_id` e
> `responsavel_id` são só mais dois campos cobertos por `tickets.manage`, sem checagem adicional —
> `tickets.assign` fica reservada pra uma futura ação de roteamento dedicada (ex. endpoint próprio de
> atribuição, com notificação/auditoria), não misturada no `update()` genérico. `agente` tem
> `tickets.manage` (não tinha `tickets.assign`, que continua só admin/supervisor).

### 3.4.7.1. Endpoints — Feed de `Incidente` (`App\Http\Controllers\Api\IncidenteDescricaoController`)

Recurso aninhado em `/incidentes/{incidente}/descricoes`. Leitura segue `tickets.view`/`tickets.manage`
como o resto do CRUD de `Incidente`, mas editar/excluir exige **adicionalmente** ser o autor —
regra de posse, não expressável no middleware `can:`, checada dentro do controller
(`ensureCanModify()`).

```php
Route::middleware(['auth:web', 'can:tickets.view'])
    ->apiResource('incidentes.descricoes', IncidenteDescricaoController::class)
    ->parameters(['descricoes' => 'descricao'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:tickets.manage'])
    ->apiResource('incidentes.descricoes', IncidenteDescricaoController::class)
    ->parameters(['descricoes' => 'descricao'])
    ->only(['store', 'update', 'destroy']);
```

| Método | Rota | Permission + regra | Body | Resposta |
|---|---|---|---|---|
| `GET` | `/incidentes/{incidente}/descricoes` | `tickets.view` | — | `200` — paginado (`?per_page=`), mais recente primeiro, `user` carregado |
| `GET` | `/incidentes/{incidente}/descricoes/{descricao}` | `tickets.view` | — | `200` — `{data: {...}}`; `404` se `descricao` não pertencer a esse `incidente` |
| `POST` | `/incidentes/{incidente}/descricoes` | `tickets.manage` | `{descricao}` | `201` — `{data: {...}}`; `tipo` sempre `"comentario"` e `user` sempre quem está autenticado, **mesmo que o payload tente enviar `tipo`/`user_id` diferentes** (ignorados) |
| `PUT`/`PATCH` | `/incidentes/{incidente}/descricoes/{descricao}` | `tickets.manage` **+ autor** | `{descricao}` | `200` — `{data: {...}}`; `403` se não for o autor, se `tipo = 'escalonamento'` (nunca editável, nem pelo autor), **ou se já estiver excluído** (`excluido_em` setado) |
| `DELETE` | `/incidentes/{incidente}/descricoes/{descricao}` | `tickets.manage` **+ autor** | — | `204` — **soft delete**, a linha continua existindo e visível no feed (ver §3.1); mesmas travas de `403` do `PUT`, incluindo excluir um comentário já excluído de novo |

`App\Http\Resources\IncidenteDescricaoResource`: `{id, incidente_id, tipo, descricao, excluido_em,
user: {id, name}, created_at, updated_at}` — `excluido_em` é `deleted_at`, `null` enquanto não
excluído; `user` resolve mesmo que o autor tenha sido desativado (`User::withTrashed()`, ver §3.4.3).

Validação: `descricao` obrigatória (`string`) em `store`/`update`. Sem validação de `tipo`/`user_id`
no `store` porque esses campos nunca vêm do request — são sempre forçados no controller.

### 3.4.7.2. Endpoints — Anexos de `Incidente` (`App\Http\Controllers\Api\AnexoController`)

Recurso aninhado em `/incidentes/{incidente}/anexos`, armazenado em disco local (`Storage::disk('local')`,
`storage/app/private/anexos/incidentes/{incidente_id}/...`) — **por ora**; trocar pra S3/cloud depois é
só mudar a disk usada no controller, o resto (model/rotas/tabela) não muda. Disco `local` (não
`public`) de propósito: arquivo não é servido por URL direta, só via endpoint de download autenticado
(`can:tickets.view`) — evita expor anexos de incidentes sem checar permissão.

Sem `update` (arquivo é substituído via `DELETE` + novo `POST`, não editado no lugar) e sem restrição
de autor no `destroy` — diferente de `descricoes`/`comentario`, não foi pedido posse aqui: qualquer
staff com `tickets.manage` pode remover um anexo, não só quem enviou.

```php
Route::middleware(['auth:web', 'can:tickets.view'])
    ->apiResource('incidentes.anexos', AnexoController::class)
    ->only(['index']);

Route::middleware(['auth:web', 'can:tickets.view'])
    ->get('/incidentes/{incidente}/anexos/{anexo}/download', [AnexoController::class, 'download']);

Route::middleware(['auth:web', 'can:tickets.manage'])
    ->apiResource('incidentes.anexos', AnexoController::class)
    ->only(['store', 'destroy']);
```

| Método | Rota | Permission | Body | Resposta |
|---|---|---|---|---|
| `GET` | `/incidentes/{incidente}/anexos` | `tickets.view` | — | `200` — paginado (`?per_page=`), mais recente primeiro, `user` carregado |
| `POST` | `/incidentes/{incidente}/anexos` | `tickets.manage` | `multipart/form-data`: `{arquivo}` | `201` — `{data: {...}}` |
| `GET` | `/incidentes/{incidente}/anexos/{anexo}/download` | `tickets.view` | — | `200` — stream do arquivo (`Content-Disposition: attachment`, nome original preservado); `404` se `anexo` não pertencer a esse `incidente` |
| `DELETE` | `/incidentes/{incidente}/anexos/{anexo}` | `tickets.manage` | — | `204` — remove a linha **e** o arquivo do disco; `404` se `anexo` não pertencer a esse `incidente` |

`App\Http\Resources\AnexoResource`: `{id, incidente_id, nome_original, mime_type, tamanho, user:
{id, name}, created_at}` — **não** expõe `caminho` (detalhe de armazenamento interno, não uma URL
utilizável pelo cliente; download é sempre pelo endpoint dedicado).

Validação (`store`): `arquivo` obrigatório, `file`, `max:10240` (10 MB — limite arbitrário pro
primeiro corte). `nome_original`, `mime_type` e `tamanho` vêm do próprio arquivo enviado
(`UploadedFile`), nunca do payload; `caminho` é gerado pelo `Storage::store()` no momento do upload
(nome aleatório, evita colisão e path traversal) e nunca é fillable — setado por atribuição direta no
controller, mesmo padrão de `prazo_resposta`/`prazo_resolucao` em `Incidente`.

> 📌 **Hardening de upload — lista branca de extensão + magic number + remoção de EXIF.** Adicionado
> depois da entrega inicial (que não tinha nenhuma restrição de tipo de arquivo), a pedido explícito
> de revisão de segurança.
>
> **Extensões permitidas** (`AnexoController::EXTENSOES_PERMITIDAS`): `pdf`, `doc`, `docx`, `jpg`,
> `jpeg`, `png`, `xls`, `xlsx`, `csv` — lista branca, não negra: um tipo novo/desconhecido é rejeitado
> por padrão. Aplicada via regra nativa do Laravel `extensions:...`, que também bloqueia
> `.php`/`.php3`/`.php4`/`.php5`/`.php7`/`.php8`/`.phtml`/`.phar` incondicionalmente (built-in do
> framework), mesmo que alguém adicionasse `php` à lista por engano.
>
> **`extensions:` sozinha NÃO confere o conteúdo real do arquivo — só o nome.** Achado verificando o
> código-fonte do Laravel (`ValidatesAttributes::validateExtensions()`): apesar de existir um guard
> específico contra upload de `.php` disfarçado, a regra em geral compara só
> `getClientOriginalExtension()` (o nome que o cliente mandou) contra a lista, nunca o mime real
> detectado do conteúdo — um `malware.php` renomeado pra `foto.jpg` passa por ela normalmente. A
> checagem de conteúdo de verdade é um closure próprio (`validarConteudoRealDoArquivo()`) que compara
> `UploadedFile::getMimeType()` — que o Symfony detecta via `finfo` nos **bytes reais** do arquivo,
> nunca no `Content-Type` que o cliente declarou no header — contra uma lista de mimes esperados **por
> extensão** (`AnexoController::MIME_TYPES_POR_EXTENSAO`), não uma lista global única: se fosse uma
> lista só, aceitar `application/zip` pra `.docx`/`.xlsx` (que são, literalmente, arquivos zip)
> abriria brecha pra um `.zip` qualquer renomeado como `.jpg` passar, já que o mesmo mime estaria na
> lista "geral". `doc`/`xls` aceitam também `application/x-ole-storage`/`application/x-cfb` (o
> container OLE2 genérico) além do mime específico — o magic number sozinho não diferencia Word de
> Excel sem inspecionar a estrutura interna completa do documento.
>
> **Testando isso**: `UploadedFile::fake()` (helper de teste do Laravel) **não serve** pra testar essa
> checagem — `Illuminate\Http\Testing\File::getMimeType()` é sobrescrito pra devolver o mime baseado
> só no **nome** do arquivo (`MimeType::from($this->name)`), nunca no conteúdo real escrito nele. O
> teste que prova o bloqueio de conteúdo malicioso (`test_uploading_anexo_rejects_content_that_does_not_match_its_extension`)
> constrói um `Illuminate\Http\UploadedFile` real (mesma classe do fluxo de produção) apontando pra um
> arquivo temporário de verdade, em vez de usar o fake.
>
> **Remoção de EXIF/metadados em imagens** (`removerMetadadosSeImagem()`): pra `image/jpeg` e
> `image/png`, a imagem é recarregada via GD (`imagecreatefromjpeg`/`imagecreatefrompng`) e resalva
> por cima do mesmo arquivo (`imagejpeg`/`imagepng`) — só os pixels sobrevivem ao reencode, EXIF/GPS/
> fabricante do dispositivo/etc. são descartados como efeito colateral, sem precisar de uma lib de
> EXIF dedicada. Roda **depois** do `Storage::store()` (já validado e salvo), silenciosamente ignorado
> se o GD não conseguir ler o arquivo (defesa extra, não deveria acontecer já que o conteúdo foi
> validado antes). **Extensão `gd` não vinha instalada na imagem PHP** — adicionada ao
> `docker/php/Dockerfile` (`libjpeg-turbo-dev`/`libpng-dev`/`freetype-dev` como build deps +
> `docker-php-ext-install gd`) especificamente pra isso; precisa de `docker compose build app` (não só
> `restart`) pra pegar a mudança num ambiente que já existia antes dela.
>
> **`Content-Disposition: attachment` já vinha coberto** desde a entrega inicial (`Storage::download()`
> do Laravel seta isso nativamente) — navegador baixa o arquivo, nunca executa/renderiza inline, mesmo
> que algo escapasse da whitelist. Reforçado por estar em disco `local` (privado) atrás de auth.

`App\Models\User::anexos(): HasMany` — FK `anexos.user_id` é `restrictOnDelete()`; `UserController::destroy()`
bloqueia com `409` a exclusão de um usuário que enviou algum anexo (mesmo padrão de
`incidentesResponsavel()`). `anexos.incidente_id` é `cascadeOnDelete()` — anexo é registro histórico do
próprio incidente, não tem vida própria fora dele.

### 3.4.8. Endpoint — Dashboard de Incidentes (`App\Http\Controllers\Api\DashboardController`)

View read-only, achatada, pensada pra alimentar uma listagem/tabela de dashboard — distinta do CRUD
normal de `Incidente` (que devolve o registro cru com relações aninhadas). Reaproveita `tickets.view`,
sem permission própria.

```php
Route::middleware(['auth:web', 'can:tickets.view'])
    ->get('/dashboard/incidentes', [DashboardController::class, 'incidentes']);
```

> 📌 **Path `/dashboard/incidentes`, não `/incidentes/dashboard`:** o `apiResource('incidentes', ...)`
> já registra `GET /incidentes/{incidente}` — colocar `dashboard` como sufixo faria esse padrão casar
> primeiro e tentar resolver `{incidente}` com o valor literal `"dashboard"`. Um path próprio evita a
> colisão e também abre espaço pra outras views de dashboard no futuro (`/dashboard/slas`, etc.).

| Método | Rota | Permission | Resposta |
|---|---|---|---|
| `GET` | `/dashboard/incidentes` | `tickets.view` | `200` — paginado (`?per_page=`), filtrável (mesmos query params de `GET /incidentes`, ver §3.4.7, **mais `numero` e `todos_status`**), ordenável (`sort_by`/`sort_dir`), ordenado por mais recente quando `sort_by` não é informado, `{data: [...], links, meta}` |

> 📌 **Mesmo filtro do CRUD, mesma validação, código duplicado de propósito, mais dois filtros
> exclusivos.** `DashboardController` valida os query params (`numero`/`status`/`prioridade`/`origem`/
> `customer_id`/`item_id`/`grupo_solucao_id`/`responsavel_id`/`todos_status`) e aplica
> `Incidente::scopeFiltros()` — o scope no model é compartilhado (evita duplicar a query), a
> validação é duplicada nos dois controllers (sem camada de serviço pra compartilhar, ver §3.5).
> `numero` (`sometimes|integer`, equivale a `where('id', $v)` — `numero` não é uma coluna própria, é
> só como o frontend chama o `id` do incidente, ver `IncidenteDashboardResource.numero`) e
> `todos_status` (`sometimes|boolean`) só existem aqui, não em `IncidenteController::index()` — a UI
> de "Filtro Avançado" da listagem do dashboard é a única consumidora até o momento.
>
> **`sem_classificacao`** (`sometimes`, `boolean`) — triagem: só chamados com `item_id` nulo, ou seja,
> abertos pelo portal e ainda não classificados pelo staff (ver §3.4.10). Prioridade nula aparece
> como `null` na resposta e status de SLA `sem_sla`.

> **`todos_status` desliga a restrição padrão de status** (`STATUSES_PADRAO_LISTAGEM`, ver acima) sem
> precisar informar um `status` específico — é o que alimenta o botão "Mostrar todos os registros" da
> listagem (mostra incidentes de **qualquer** status, incluindo `resolvido`/`fechado`/`cancelado`).
> Um `status` explícito continua tendo prioridade sobre `todos_status` caso os dois sejam enviados
> juntos (mesma ordem de precedência de `Incidente::scopeFiltros()`, ver §3.2) — na prática o frontend
> nunca envia os dois ao mesmo tempo, já que são ações mutuamente exclusivas na UI.

> 📌 **Ordenação por coluna clicável (`sort_by`/`sort_dir`), exclusiva deste endpoint.**
> `sort_by` (`sometimes`, `Rule::in(Incidente::SORTABLE_COLUMNS)`) aceita `numero`, `titulo`,
> `prioridade`, `status`, `data_abertura`, `cliente`, `grupo_solucao`, `responsavel`; `sort_dir`
> (`sometimes`, `asc`/`desc`, padrão `asc`) só tem efeito junto com `sort_by`. Aplicado via
> `Incidente::scopeOrdenarPor()`: os quatro primeiros e `data_abertura` (→ `created_at`) ordenam
> direto por coluna de `incidentes`; `cliente`/`grupo_solucao`/`responsavel` não são colunas
> próprias — o scope monta um `leftJoin` (`customers`+`clients` pro cliente, um hop pros outros
> dois) e ordena pelo `name`/`nome` da tabela relacionada, com `select('incidentes.*')` pra evitar
> colisão de colunas homônimas entre as tabelas (`id`, `name`, `created_at`). Sem `sort_by`,
> mantém o padrão histórico (`latest()`). **`classificacao` e `status_sla_resposta`/
> `status_sla_resolucao` propositalmente não são ordenáveis** — a primeira é concatenada no
> frontend a partir de uma cadeia de 3 tabelas (`item.subcategoria.categoria`), as outras são
> calculadas em PHP por requisição contra `now()` (`calcularStatusSla()`, ver acima) — replicar
> isso em SQL bruto ficou fora de escopo.

`App\Http\Resources\IncidenteDashboardResource`, um item por incidente:

```jsonc
{
  "numero": 1,                          // Incidente.id
  "titulo": "Impressora do 3º andar sem toner",
  "origem": "portal",
  "tipo": "incidente",                  // um de Incidente::TIPOS; filtrável via ?tipo=
  "status": "em_andamento",
  "prioridade": "media",
  "cliente": "Empresa Teste",           // Client.name, via incidente->customer->client
  "email_cliente": "cliente@example.com", // Customer.email — a pessoa que abriu, não o Client
  "data_abertura": "2026-08-13T01:30:34.000000Z",   // Incidente.created_at
  "prazo_resposta": "2026-08-13T05:30:34.000000Z",  // Incidente.prazo_resposta (congelado, ver §3.1)
  "prazo_resolucao": "2026-08-14T01:30:34.000000Z", // Incidente.prazo_resolucao (congelado)
  "status_sla_resposta": "dentro_prazo",   // dentro_prazo | estourado | sem_sla
  "status_sla_resolucao": "dentro_prazo",
  "tempo_resposta_minutos": 240,        // meta da política (prazo_resposta - data_abertura)
  "tempo_resposta_horas": 4,
  "tempo_resolucao_minutos": 1440,
  "tempo_resolucao_horas": 24,
  "tempo_restante_resposta_minutos": 239,   // negativo se já estourado; null se sem_sla
  "tempo_restante_resolucao_minutos": 1439,
  "categoria": "Hardware",              // via incidente->item->subcategoria->categoria
  "subcategoria": "Impressora",
  "item": "Sem toner",
  "grupo_solucao": "Suporte N1",
  "responsavel": "Agente"
}
```

> 📌 **Nenhuma query extra por linha — diferente da primeira versão desta resource.** Antes, cada
> linha chamava `Client::resolvedSlaFor()` de novo (N+1 aceito de propósito, documentado aqui
> mesmo). Agora que `prazo_resposta`/`prazo_resolucao` são colunas persistidas no `Incidente` (ver
> §3.1), a resource só lê o que já está na linha: `tempo_resposta_minutos` vira
> `created_at->diffInMinutes(prazo_resposta)`, `status_sla_*`/`tempo_restante_*` chamam os métodos
> do próprio model (`$this->statusSlaResposta()`, etc., encaminhados via `__call` do `JsonResource`
> pro `Incidente` por baixo). `customer.client` e `item.subcategoria.categoria` continuam eager
> loaded pros campos de nome/e-mail/taxonomia.
>
> Se não houver política aplicável pra aquela prioridade (nem específica do cliente, nem global): os
> 4 campos de tempo, os 2 prazos e os 2 tempos restantes vêm `null`, e `status_sla_*` vem `"sem_sla"`.
> `categoria`/`subcategoria`/`item` vêm `null` quando o incidente ainda não foi classificado
> (`item_id` nulo). `status_sla_resolucao` usa `concluido_em` como referência em vez de "agora"
> quando o incidente já está concluído — congela o resultado, não muda mais depois (ver §3.1).

### 3.4.9. Endpoints — Relatórios (`App\Http\Controllers\Api\RelatorioController` + `RelatorioSalvoController`)

Agregações filtráveis sobre `Incidente` — "incidentes fechados dentro/fora do SLA", "por agente",
"por grupo de solução", "por categoria/subcategoria/item" — todos cobertos por **um único endpoint genérico** parametrizado
por `agrupar_por`, em vez de um endpoint dedicado por indicador. `RelatorioSalvo` guarda uma
*configuração* de filtros+agrupamento pra reexecutar depois (não um snapshot do resultado — sempre
roda contra os dados atuais no momento da execução).

```php
Route::middleware(['auth:web', 'can:relatorios.view'])
    ->get('/relatorios/incidentes', [RelatorioController::class, 'index']);

Route::middleware(['auth:web', 'can:relatorios.view'])
    ->apiResource('relatorios-salvos', RelatorioSalvoController::class)
    ->parameters(['relatorios-salvos' => 'relatorioSalvo'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:relatorios.view'])
    ->get('/relatorios-salvos/{relatorioSalvo}/executar', [RelatorioSalvoController::class, 'executar']);

Route::middleware(['auth:web', 'can:relatorios.manage'])
    ->apiResource('relatorios-salvos', RelatorioSalvoController::class)
    ->parameters(['relatorios-salvos' => 'relatorioSalvo'])
    ->only(['store', 'update', 'destroy']);
```

| Método | Rota | Permission | Resposta |
|---|---|---|---|
| `GET` | `/relatorios/incidentes` | `relatorios.view` | `200` — `{agrupado_por, data: [{chave, rotulo, total}, ...]}` (json) **ou** download do `.xlsx` (`formato=xlsx`; cabeçalho da 1ª coluna = título do agrupamento, ex. "Concluído por", via `RelatorioController::ROTULOS_AGRUPAMENTO`) |
| `GET` | `/relatorios-salvos` | `relatorios.view` | `200` — paginado, `user` carregado |
| `GET` | `/relatorios-salvos/{id}` | `relatorios.view` | `200` — `{data: {...}}` |
| `GET` | `/relatorios-salvos/{id}/executar` | `relatorios.view` | mesma resposta de `/relatorios/incidentes` (caminho novo se há `contar` armazenado ou equivalente legado, senão legado), usando os filtros/agrupamento salvos — nunca um snapshot |
| `POST` | `/relatorios-salvos` | `relatorios.manage` | `201` — `{nome, agrupar_por, filtros}` (legado) ou `{nome, contar, agrupar_por, quebra?, filtros}` (novo) |
| `PUT`/`PATCH` | `/relatorios-salvos/{id}` | `relatorios.manage` | `200` |
| `DELETE` | `/relatorios-salvos/{id}` | `relatorios.manage` | `204` |

**Query params de `GET /relatorios/incidentes`** (todos exceto `agrupar_por` são opcionais,
combináveis; os mesmos ficam em `RelatorioSalvo.filtros`):

| Param | Validação | Nota |
|---|---|---|
| `agrupar_por` | **obrigatório**, `Rule::in(RelatorioSalvo::AGRUPAMENTOS)` = `status_sla`\|`responsavel`\|`aberto_por`\|`resolvido_por`\|`fechado_por`\|`concluido_por`\|`encaminhado_por`\|`encaminhado_para_grupo`\|`encaminhado_para_responsavel`\|`grupo_solucao`\|`categoria`\|`subcategoria`\|`item` | dimensão do relatório |
| `formato` | `sometimes`, `in:json,xlsx` | default `json` |
| `status` | `Rule::in(Incidente::STATUSES)` | sem valor padrão restritivo (diferente de `GET /incidentes`, ver §3.4.7) — um relatório histórico não deveria esconder status por padrão |
| `tipo` | `Rule::in(Incidente::TIPOS)` | aplicado tanto nos agrupamentos sobre `Incidente` quanto nos baseados em evento (`resolvido_por`/`fechado_por`/`encaminhado_*`, via `IncidenteEvento::scopeFiltrosRelatorio()`) |
| `data_inicio`/`data_fim` | `date` | dias em horário de Brasília (`config('app.timezone_exibicao')`), inclusivos nas duas pontas (`[início 00:00 BRT, fim+1 00:00 BRT)` convertido pra UTC); filtra por `concluido_em` (**não** `created_at`) — decisão explícita: os indicadores pedidos são sobre quando o chamado foi *fechado*, não aberto |
| `categoria_id`/`subcategoria_id` | `exists` | via `whereHas('item.subcategoria')`/`whereHas('item')` — `Incidente` não tem essas colunas direto, só `item_id` |
| `item_id`/`grupo_solucao_id`/`responsavel_id`/`customer_id` | `exists` | mesma coluna de `Incidente::scopeFiltros()` |
| `client_id` | `exists:clients,id` | empresa (não confundir com `customer_id`, o usuário do portal) — via `whereHas('customer')` |
| `grupo_analista_id` | `sometimes`, `integer`, `exists:grupos_solucao,id` | grupo dos **analistas**. Agrupamentos por evento (`resolvido_por`/`fechado_por`/`concluido_por`/`encaminhado_*`): `incidente_eventos.autor_grupo_solucao_id` (grupo do autor *no momento do evento*). `aberto_por`: grupo atual do criador (`criadoPor`) — o balde "Portal (cliente)" some. Demais (`responsavel`, `status_sla`, `grupo_solucao`, `categoria`, `subcategoria`, `item`): grupo atual do `responsavel`. Com agrupamento por usuário (`resolvido_por`/`fechado_por`/`concluido_por`/`encaminhado_por`/`responsavel`), usuários **ativos** com `users.grupo_solucao_id` = X ausentes do resultado entram com `total: 0`, e a lista é ordenada por `total` desc, `rotulo`. Visibilidade (`scopeVisiveisPara`) não muda |

> 📌 **`status_sla`/`responsavel`/`grupo_solucao` restringem a `STATUS_ATENDIDOS` (`resolvido`/`fechado`; `cancelado` fora) por padrão;
> `categoria`/`subcategoria`/`item`/`aberto_por` não.** Os indicadores originais 1-3 eram
> especificamente sobre chamados *fechados* ("incidentes fechados dentro/fora do SLA", "fechados
> por agente"); "quantidade por categoria/subcategoria/item" e "aberto por" não mencionam "fechados"
> — são volume geral, independente de status. `grupo_solucao` (agrupamento por equipe, adicionado
> depois) segue o mesmo critério de `responsavel` — mede desempenho do grupo em chamados
> concluídos, não volume geral. Um `status` explícito sempre substitui essa restrição implícita
> (mesmo padrão de `STATUSES_PADRAO_LISTAGEM`, ver §3.4.7), inclusive pra incluir incidentes abertos
> no agrupamento por SLA (`statusSlaResolucao()` já lida com isso — compara contra `now()` em vez de
> `concluido_em` quando ainda não concluído).
>
> **`Incidente::scopeFiltrosRelatorio()` é separado de `scopeFiltros()`** (usado por
> `GET /incidentes`/dashboard) — não herda a restrição padrão de status da listagem (não faz
> sentido pra um relatório histórico) e tem dimensões que a listagem não precisa
> (`data_inicio`/`data_fim`, `categoria_id`/`subcategoria_id` via join, `client_id` via join).
>
> **Restrição implícita de status:** `status_sla`/`responsavel`/`grupo_solucao`, sem `status` explícito,
> restringem a `Incidente::STATUS_ATENDIDOS` (`['resolvido', 'fechado']`). Motivação: são indicadores de
> desempenho sobre chamados efetivamente atendidos; `cancelado` não é atendimento, então fica de fora
> (`STATUS_CONCLUIDOS` continua incluindo `cancelado` e segue sendo usado pelo SLA/`concluido_em`, ver §3.1).
>
> **`resolvido_por`/`fechado_por`/`concluido_por`/`encaminhado_por`/`encaminhado_para_grupo`/
> `encaminhado_para_responsavel` (6 dimensões) fogem às regras acima — base é `IncidenteEvento`, não
> `Incidente`.** Cada uma registra uma *ação* que pode se repetir ao longo da vida do chamado (resolvido,
> reaberto e resolvido de novo por outra pessoa; um chamado pode ser encaminhado várias vezes entre
> grupos/responsáveis), então não dá pra derivar de uma coluna de `Incidente`, que só guarda o estado
> *atual* — ver §3.1 `incidente_eventos`. Por isso essas 6 dimensões:
> (1) **não** têm a restrição implícita de status (um chamado resolvido e depois reaberto pra
> `em_andamento` ainda deve contar a resolução que já aconteceu — restringir por status atual esconderia
> exatamente o caso que essas dimensões existem pra cobrir);
> (2) `data_inicio`/`data_fim` (dias de Brasília, ver tabela acima) filtram `IncidenteEvento.created_at`
> (quando *aquele* evento aconteceu), não `Incidente.concluido_em` (que reflete só a resolução mais
> recente e é limpo na reabertura);
> (3) o filtro `grupo_solucao_id` usa `incidente_eventos.grupo_solucao_id` — o grupo do incidente **no
> momento do evento** —, não o grupo atual do incidente (a visibilidade, `scopeVisiveisPara`, continua
> usando o grupo atual);
> (4) **contagem:** `resolvido_por`/`fechado_por`/`concluido_por` contam **chamados distintos por usuário**
> (`count(distinct incidente_id)`) — o mesmo agente resolvendo o mesmo chamado duas vezes, ou resolvendo e
> depois fechando (`concluido_por`), conta 1; agentes diferentes no mesmo chamado contam 1 cada, porque
> o indicador mede chamados atendidos, não cliques. `encaminhado_*` contam eventos (são ações, não
> estados).
>
> `IncidenteEvento::scopeFiltrosRelatorio()` replica as mesmas dimensões de filtro de
> `Incidente::scopeFiltrosRelatorio()`, mas via `whereHas('incidente')` em vez de coluna direta, já que a
> tabela base é diferente. Cada dimensão filtra por `tipo`:
> `resolvido_por`/`fechado_por` agrupam `user_id` restrito a um único `tipo`
> (`resolvido`/`fechado`); `concluido_por` ("Concluído por (resolvido ou fechado)") agrupa `user_id`
> somando os tipos `resolvido`+`fechado`; `encaminhado_por` agrupa `user_id` somando os dois tipos
> `encaminhado_grupo`+`encaminhado_responsavel` (o pedido era só "quem encaminhou", sem distinguir o
> destino); `encaminhado_para_grupo`/`encaminhado_para_responsavel` agrupam pelo `alvo_id`
> (destino) de cada tipo separadamente — **dois indicadores, não um só "encaminhado para"
> combinado**, decisão explícita porque grupo e responsável são universos de nomes diferentes
> (`GrupoSolucao` vs `User`) e um relatório combinado exigiria prefixar/desambiguar os rótulos,
> perdendo clareza sem ganhar nada.
>
> **Eventos `encaminhado_*` e o snapshot de grupo:** o evento é criado *depois* do `update` do chamado, então
> o "grupo do chamado" gravado nele é o grupo de **destino**. Logo `encaminhado_por&grupo_solucao_id=X`
> conta os encaminhamentos *para* X (não os que saíram de X).
>
> **Visibilidade e usuários zerados:** os resultados — inclusive os analistas zerados de `grupo_analista_id`
> — dependem de quem chama: `scopeVisiveisPara` ainda usa o grupo **atual** do chamado (a fase 2 pode
> migrar pro snapshot). Um usuário restrito pode ver um analista com `total: 0` cujas conclusões estão em
> chamados que hoje pertencem a grupos que ele não enxerga.
>
> **`aberto_por` não usa `IncidenteEvento`** — "quem abriu" nunca se repete nem muda ao longo da
> vida do chamado (diferente de resolver/fechar/encaminhar), então é só `Incidente.criado_por_id`
> (setado uma vez em `store()`, ver §3.4.7) agrupado direto, sem a restrição implícita de
> `STATUS_ATENDIDOS` (é volume geral, não indicador de fechamento). Chamados abertos pelo portal
> (`criado_por_id` nulo, `criado_por_customer_id` preenchido) saem da linha `(desconhecido)` e viram
> uma linha própria `{chave: 'portal', rotulo: 'Portal (cliente)'}` — `(desconhecido)` fica só pros
> legados de antes da coluna existir (`RelatorioController::separarAbertosPeloPortal()`).
>
> **Agrupamento por SLA é calculado em PHP, não SQL puro.** `statusSlaResolucao()` compara
> `concluido_em` (congelado) contra `prazo_resolucao` — lógica já existente no model (mesma usada no
> dashboard, ver §3.4.8) — não dá pra expressar isso num `GROUP BY` do banco sem duplicar a
> comparação de datas em SQL. `RelatorioController` busca as linhas filtradas e tabula em memória,
> reaproveitando o método do model em vez de duplicar a regra.
>
> **Exportação `.xlsx` via `maatwebsite/excel` (v4, `phpoffice/phpspreadsheet` por baixo) — mesma
> agregação dos dois formatos**, nunca duas queries diferentes: `RelatorioController::agregar()` monta
> as linhas `{chave, rotulo, total}` uma vez; `formato=json` serializa direto, `formato=xlsx` passa
> pra `App\Exports\RelatorioIncidentesExport` (`FromCollection`+`WithHeadings`). Exigiu adicionar a
> extensão `zip` à imagem PHP (não vinha instalada — mesma situação do `gd` pra EXIF, ver §3.4.7.2) —
> `phpoffice/phpspreadsheet` depende dela pra gerar o `.xlsx` (que é, por baixo, um contêiner zip).
> **Rodar `composer audit`** ao instalar revelou 12 advisories em `guzzlehttp/guzzle`/
> `league/commonmark` — dependências transitivas do próprio `laravel/framework`, não trazidas pelo
> pacote novo, só nunca auditadas nesta sessão antes. Corrigido com
> `composer update guzzlehttp/guzzle league/commonmark --with-all-dependencies` (dentro da faixa já
> permitida pelo `composer.json` do Laravel, sem mudar nenhum constraint).
>
> **`RelatorioSalvo` é compartilhado entre a equipe, não privado por usuário** — como o resto do
> sistema (`Client`/`Categoria`/etc.), diferente da regra "só o autor edita" de `comentario`
> (`IncidenteDescricao`, ver §3.1). Qualquer staff com `relatorios.view` lê/executa; só
> `relatorios.manage` cria/edita/exclui — não checa autoria.
>
> **Achado de validação do Laravel**: `$request->validate([...])` reconstrói uma chave-array
> (`'filtros' => ['present', 'array']`) **a partir das sub-regras `'filtros.*'`** quando elas existem
> — com `filtros` vazio (`{}`), nenhuma sub-chave é validada, e a chave `filtros` inteira **some** do
> array retornado por `validate()`, mesmo tendo passado a regra `present`. `RelatorioSalvoController::validated()`
> usa `$request->input('filtros', [])` (bruto, já garantido ser array pela validação acima) em vez do
> valor "validado" reconstruído, pra não perder um relatório sem filtro nenhum (caso de uso legítimo:
> "todos os incidentes por item", sem nenhum recorte). Achado ao testar manualmente contra o servidor
> real, não pego pelos testes automatizados originais — teste de regressão adicionado
> (`test_creating_relatorio_salvo_with_empty_filtros_succeeds`).

`App\Http\Resources\RelatorioSalvoResource`: `{id, nome, agrupar_por, filtros, user: {id, name},
created_at, updated_at}`. `App\Models\RelatorioSalvo::user()` usa `->withTrashed()` (mesmo padrão do
resto do sistema pós-soft-delete de `User`, ver §3.4.3) — `RelatorioSalvo.user_id` é
`restrictOnDelete()` **sem** precisar de guard extra em `UserController::destroy()`, porque `User`
nunca é apagado de verdade.

#### Relatórios salvos no caminho novo

- **Validação.** Com `contar` no corpo, `RelatorioSalvoController::validated()` valida `contar`, `agrupar_por` (matriz por `contar`), `quebra` e `filtros.*` com `ConsultaRelatorio::regras()`. As regras cruzadas leem os campos irmãos no topo, então os `filtros` são achatados ao validar e os erros voltam com prefixo `filtros.` (`filtros.periodo`, `filtros.data_fim`...). Chaves de `filtros` fora de `periodo`/`data_inicio`/`data_fim`/`ConsultaRelatorio::FILTROS` são descartadas. Sem `contar`, vale a validação legada e `contar`/`quebra` ficam nulos.
- **Execução.** `executar()` monta a consulta com `ConsultaRelatorio::deDados()` no momento da chamada: o atalho `periodo` é resolvido com o "hoje" de agora (a validação também resolve sem `$agora`, então os dois usam o mesmo relógio) e responde por `RelatorioConsultaController::responder()`. `formato=xlsx` funciona igual.
- **Legado com par exato.** `RelatorioSalvo::EQUIVALENTES_LEGADO`: `resolvido_por`→resolvidos/analista, `fechado_por`→fechados/analista, `concluido_por`→concluidos/analista, `encaminhado_por`→encaminhamentos/quem_encaminhou, `encaminhado_para_grupo`→encaminhamentos/para_grupo, `encaminhado_para_responsavel`→encaminhamentos/para_responsavel, `aberto_por`→abertos/aberto_por. `aberto_por` só se não houver `data_inicio`/`data_fim` (o legado filtra por `concluido_em`, o novo por `created_at`); período com uma ponta só também fica no legado. Os demais legados (`categoria`, `responsavel`, `status_sla`...) seguem em `RelatorioController::responder()` com a resposta `{agrupado_por, data}`.
- **Salvo do tipo `indicadores`.** `contar = 'indicadores'` (`RelatorioSalvo::CONTAR_INDICADORES`), `agrupar_por` = `grupo` ou `integrante` (`integrante` = `por_integrante`), `quebra`, e em `filtros` só `grupos`, `periodo` ou `data_inicio`/`data_fim` (o resto é descartado). `RelatorioSalvoController::validatedIndicadores()` achata `filtros` + `por_integrante` + `quebra` e valida com `ConsultaIndicadores::regras()`; erros em campos de `filtros` voltam como `filtros.*` (inclusive `filtros.por_integrante` quando `integrante` não tem exatamente um grupo; `quebra` fica no topo). Não há migration: `contar` e `agrupar_por` são strings livres. `executar` monta `ConsultaIndicadores::deDados()` com o "hoje" de agora (atalho resolvido na hora) e delega a `IndicadoresController::responder()`, que valida a visibilidade dos grupos para quem executa e devolve JSON ou, com `formato=xlsx`, o `BinaryFileResponse`. O log de auditoria `exportado` guarda em `parametros` os valores da consulta (`grupos`, `por_integrante`, `quebra`, `periodo`, `data_inicio`, `data_fim`), não os da requisição `executar`.
- **Resource.** `RelatorioSalvoResource` devolve `contar`, `quebra` e `agrupar_por` (armazenados; no legado com par exato, os valores do equivalente — ex. `fechado_por` sai como `analista`, para o par bater com a matriz do construtor; legado sem par mantém o `agrupar_por` antigo) e `formato_antigo` (`true` quando não há `contar` nem equivalente).

#### Caminho novo (`contar`) — Fase 2

`GET /relatorios/incidentes` **com** o parâmetro `contar` segue outro caminho:
`RelatorioController::index()` delega para `App\Http\Controllers\Api\RelatorioConsultaController`
(`validado()` → `responder()`). **Sem `contar`, nada muda** — resposta `{agrupado_por, data}` e
validação legada intactas (spec 6.5). O código fica em `app/Relatorios/`:

| Classe | Papel |
|---|---|
| `ConsultaRelatorio` (readonly) | pedido validado: `contar`, `agruparPor`, `quebra`, `inicio`/`fim` (Y-m-d, atalho já resolvido), `atalho`, `filtros`. `regras()` (chaves sem prefixo, reaproveitadas pelo relatório salvo), `regraQuebra()` (regra cruzada quebra × período, pública para `ConsultaIndicadores`), `deDados($validado, ?$agora)`, `fonte()` |
| `FonteRelatorio` (interface) | `linhas($c, $user)` → `Collection<LinhaBase>`, `rotulos($c, $chaves)`, `contaEventos()` |
| `FonteIncidentes` | `abertos`/`cancelados` sobre `incidentes`. Seleciona só `id`, a data e a chave (joins `left` em `itens`/`subcategorias`/`customers`), nunca hidrata o model. Filtros + `visiveisPara` rodam numa subconsulta de ids (`whereIn('incidentes.id', ...)`), porque os scopes usam colunas sem prefixo que ficariam ambíguas ao lado dos joins |
| `LinhaBase` (readonly) | `chave`, `incidenteId`, `eventoId`, `autorId`, `data` (UTC) |
| `AgregadorRelatorio::agregar($c, $linhas, $fonte, ?$zerados)` | linha × coluna de período, totais, %, ordenação |
| `ResultadoRelatorio` | `colunas()`, `linhas()`, `totais()`, `chamadosDistintos()`, `comTitulo()`, `toArray()` |
| `TituloRelatorio::montar($c)` | frase de título (abaixo) |
| `App\Exports\RelatorioTabelaExport` | planilha do caminho novo (abaixo) |
| `RotulosRelatorio` | id → nome (usuários `withTrashed`, grupos, categoria/subcategoria/item, clientes), rótulos vazios, `CHAVE_VAZIA = '__vazio__'`, `CHAVE_PORTAL = 'portal'` |

`ConsultaRelatorio::fonte()` devolve `FonteIncidentes` para `abertos`/`cancelados` e `FonteEventos`
para `concluidos`/`resolvidos`/`fechados`/`encaminhamentos`.

**`FonteEventos`** (`concluidos` = `resolvido`+`fechado`; `resolvidos`; `fechados`; `encaminhamentos` =
`encaminhado_grupo`+`encaminhado_responsavel`; `contaEventos()` só é `true` em `encaminhamentos`):
- Data = `incidente_eventos.created_at` (dias de Brasília, mesmo `PeriodoRelatorio`). Filtros e
  visibilidade rodam numa subconsulta de ids de evento; depois uma consulta enxuta (`id`,
  `incidente_id`, `user_id`, data, chave) com joins só quando a chave pede o chamado.
- **Filtros por snapshot:** `grupo_solucao_id` = `incidente_eventos.grupo_solucao_id` e
  `grupo_analista_id` = `incidente_eventos.autor_grupo_solucao_id`; os demais (`status`, `tipo`,
  classificação, `responsavel_id`, cliente) usam o estado atual do chamado, via
  `Incidente::scopeFiltrosRelatorio` (sem os dois filtros de grupo).
- **Chaves:** `analista`/`quem_encaminhou` = `user_id`; `grupo_analista` = `autor_grupo_solucao_id`;
  `grupo_chamado` = `grupo_solucao_id` (snapshot); `para_grupo`/`para_responsavel` = `alvo_id`, contando só
  os eventos `encaminhado_grupo`/`encaminhado_responsavel` respectivamente (`quem_encaminhou` conta os dois); `categoria`/`subcategoria`/`item`/`cliente`/
  `tipo` vêm do chamado atual; `status_sla` aplica `Incidente::statusSlaResolucao()` a
  `prazo_resolucao`/`concluido_em` do chamado, lidos no mesmo join (sem hidratar models).
- **Visibilidade (spec 5.3):** `IncidenteEvento::scopeVisiveisParaRelatorio($user)` — evento visível se
  o chamado é visível hoje **ou** `grupo_solucao_id` **ou** `autor_grupo_solucao_id` do evento está nos
  grupos visíveis; admin vê tudo. Assim o time continua vendo o que concluiu em chamado que foi para
  outro grupo. `scopeVisiveisPara` (legado) não muda.

**Parâmetros:**

| Param | Validação | Nota |
|---|---|---|
| `contar` | **obrigatório**, `ConsultaRelatorio::CONTAR` | ver tabela abaixo |
| `agrupar_por` | **obrigatório**, ∈ `AGRUPAMENTOS_POR_CONTAR[contar]` | fora da lista → 422 em `agrupar_por` |
| `quebra` | `nenhuma` (padrão)\|`dia`\|`semana`\|`mes` | ≠ `nenhuma` exige período → 422 "Informe um período para quebrar por {dia\|semana\|mês}."; acima de `PeriodoRelatorio::LIMITE_COLUNAS` (dia 62, semana 53, mês 24) → 422 "Período longo demais para quebra por {…}: máximo de {N} colunas." |
| `periodo` | `este_mes`\|`mes_passado`\|`ultimos_7_dias`, `prohibits:data_inicio,data_fim` | resolvido em Brasília no momento da execução (`PeriodoRelatorio::resolverAtalho`) |
| `data_inicio`/`data_fim` | `date_format:Y-m-d`, uma exige a outra, `data_fim` `after_or_equal:data_inicio` | dias de Brasília inclusivos; sem período nenhum = "todo o período" |
| `formato` | `json`\|`xlsx` | validado na mesma passada dos demais campos (um único 422 com todos os erros) |
| filtros | `status`, `tipo`, `categoria_id`, `subcategoria_id`, `item_id`, `grupo_solucao_id`, `grupo_analista_id`, `responsavel_id`, `client_id`, `customer_id` — mesmas regras do legado | nas fontes de incidente: estado atual do chamado (`Incidente::scopeFiltrosRelatorio`, sem as datas legadas); `grupo_analista_id` = grupo atual do responsável, exceto em `aberto_por` (grupo do criador) |

**`contar` → base, data, agrupamentos:**

| `contar` | Base | Data do período | Célula conta | `agrupar_por` |
|---|---|---|---|---|
| `abertos` | `incidentes`, qualquer status (cancelados inclusos) | `created_at` | chamados | `categoria`, `subcategoria`, `item`, `grupo_chamado`, `responsavel`, `aberto_por`, `cliente`, `tipo` |
| `cancelados` | `incidentes` com `status = cancelado` | `concluido_em` | chamados | iguais a `abertos` |
| `concluidos` | eventos `resolvido`+`fechado` | data do evento | chamados distintos | `analista`, `grupo_analista`, `grupo_chamado`, `categoria`, `subcategoria`, `item`, `cliente`, `tipo`, `status_sla` |
| `resolvidos` | eventos `resolvido` | data do evento | chamados distintos | iguais a `concluidos` |
| `fechados` | eventos `fechado` | data do evento | chamados distintos | iguais a `concluidos` |
| `encaminhamentos` | eventos `encaminhado_grupo`+`encaminhado_responsavel` | data do evento | eventos | `quem_encaminhou`, `para_grupo`, `para_responsavel` |

`cliente` = empresa (`customers.client_id`). `aberto_por` mantém a linha `{chave: 'portal', rotulo:
'Portal (cliente)'}` da Fase 1 (`criado_por_id` nulo e `criado_por_customer_id` preenchido); com
`grupo_analista_id` ela some, porque o filtro é pelo grupo do criador. Visibilidade nas fontes de
incidente = `Incidente::scopeVisiveisPara` (grupo atual).

**Resposta (json):**

```json
{
  "titulo": "Chamados abertos (criados) de 01/10/2026 a 30/11/2026 por categoria, por mês",
  "contar": "abertos", "agrupar_por": "categoria", "quebra": "mes",
  "periodo": {"inicio": "2026-10-01", "fim": "2026-11-30"},
  "colunas": [{"chave": "2026-10-01", "rotulo": "Out/2026"}, {"chave": "2026-11-01", "rotulo": "Nov/2026"}],
  "linhas": [{"chave": 3, "rotulo": "Rede", "celulas": {"2026-10-01": 2, "2026-11-01": 1}, "total": 3, "percentual": 75.0}],
  "totais": {"celulas": {"2026-10-01": 3, "2026-11-01": 1}, "total": 4},
  "chamados_distintos": 4
}
```

- `periodo` é `null` sem período. Sem quebra, `colunas = [{chave: "total", rotulo: "Total"}]` e cada
  `celulas = {total: n}`. Colunas em ordem cronológica; a chave é o Y-m-d do início do balde em
  Brasília (semana começa na segunda, então a 1ª coluna pode ser anterior a `data_inicio`).
- A coluna de cada linha-base é `PeriodoRelatorio::colunaDe(data, quebra)` — dia de Brasília, nunca
  a data UTC (um chamado de 01/11 02:30 UTC cai em outubro).
- **Totais:** célula = chamados distintos da linha naquele período (eventos em `encaminhamentos`);
  total da linha = soma das células; total da coluna = soma das linhas; total geral = soma de tudo;
  `percentual` = total da linha ÷ total geral × 100, uma casa (0.0 com total geral 0), serializado
  sempre como número decimal (`JSON_PRESERVE_ZERO_FRACTION`). `chamados_distintos` =
  chamados distintos de todas as linhas-base; a UI mostra o rodapé só quando difere do total geral.
- **Ordenação:** total desc, depois rótulo sem diferenciar maiúsculas/acentos (`Collator('pt_BR')`,
  força primária; `strcasecmp` se `intl` faltar).
- Chave nula vira `"__vazio__"`, com rótulo por agrupamento: `categoria`/`subcategoria`/`item`
  "(sem classificação)", grupos "(sem grupo)", `responsavel`/`para_responsavel` "(sem responsável)",
  `analista`/`quem_encaminhou` "(usuário desconhecido)", `aberto_por` "(desconhecido)", `cliente`
  "(sem cliente)". `tipo` usa `Incidente::TIPO_ROTULOS`; `status_sla`, os rótulos da Fase 1.
- **Zero-fill:** com `grupo_analista_id` e `agrupar_por` ∈ {`analista`, `responsavel`,
  `quem_encaminhou`}, usuários ativos com `users.grupo_solucao_id` = X sem linha entram com todas as
  células 0 (`RelatorioConsultaController::zerados()` → parâmetro `$zerados` do agregador).
- `status_sla` usa o `concluido_em` atual do chamado (não o do evento) para decidir o prazo.

**Título.** `titulo` = `"{Sujeito} {período} por {agrupamento}[, por {dia|semana|mês}][ — {filtros}]"`, montado por
`TituloRelatorio::montar()` e devolvido no JSON e na primeira linha da planilha. Sujeitos: "Chamados abertos
(criados)", "Chamados concluídos (resolvidos ou fechados)", "Chamados resolvidos|fechados|cancelados",
"Encaminhamentos". Período: `de dd/mm/aaaa a dd/mm/aaaa` ou `em todo o período`. Filtros por extenso (ids
resolvidos para nomes), separados por `; `, na ordem: grupo do analista, grupo de solução, cliente, usuário do
cliente (`customer_id`), categoria, subcategoria, item, tipo, responsável, status (rótulos "Em andamento" etc.).
Exemplo: `Chamados concluídos (resolvidos ou fechados) de 01/10/2026 a 31/10/2026 por analista — grupo do analista: N2`.

**Planilha (`formato=xlsx`).** Arquivo `relatorio-{contar}-{agrupar_por}.xlsx`, só valores (sem fórmulas), via
`RelatorioTabelaExport` (`FromArray`): linha 1 título; linha 2 `Gerado em dd/mm/aaaa hh:mm (horário de Brasília) por
{nome}`; linha 3 vazia; linha 4 cabeçalho `[agrupamento capitalizado, ...rótulos das colunas (só com quebra), Total, %]`;
uma linha por registro (`%` numérico com 1 casa); linha `Total`; e, só se `chamados_distintos` ≠ total geral, a
linha final `Chamados distintos no resultado: N`.

**Lista de chamados por linha/célula — `GET /relatorios/chamados`** (`auth:web` + `can:relatorios.view`,
`RelatorioConsultaController::chamados()`). Mesmos parâmetros da consulta (validados com `ConsultaRelatorio::regras()`)
+ `chave` (obrigatório; `__vazio__` = chave nula, comparada como string), `coluna` (opcional; chave de coluna do
período, `total` sem quebra; inexistente → 422 em `coluna`; ausente = todas), `page`/`per_page` (padrão 50, máx 200) e
`formato` (`json`|`xlsx`). `App\Relatorios\ListaChamados` parte das mesmas `LinhaBase` da fonte (por isso bate com a
célula): filtra por chave/coluna e ordena por `data` desc. Uma linha por **chamado** (data/autor do evento mais recente
no escopo), exceto `encaminhamentos`, uma por **evento**, com `destino` = nome do grupo/responsável (`alvo_type`/`alvo_id`).
Fontes de incidente (`abertos`/`cancelados`) não têm autor: `autor` = `null`. `acessivel` = o chamado é visível hoje por
`Incidente::visiveisPara` (a linha pode aparecer por snapshot do evento num chamado que já mudou de grupo). Chamados e
autores são carregados em uma query cada, só para a página.

```json
{ "titulo": "...", "linha": {"chave": "7", "rotulo": "Ana"}, "coluna": {"chave": "2026-10-05", "rotulo": "Sem 05/10"} | null,
  "data": [{"incidente_id": 1, "titulo": "...", "status": "fechado", "data": "ISO-8601", "autor": {"id": 7, "name": "Ana"} | null,
            "grupo_chamado": {"id": 2, "nome": "N2"} | null, "destino": "string" | null, "acessivel": true}],
  "meta": {"current_page": 1, "per_page": 50, "total": 1, "last_page": 1} }
```

`formato=xlsx` baixa `relatorio-chamados.xlsx` (`RelatorioChamadosExport`, todos os itens, sem paginar): linha 1 título;
linha 2 `Linha: {rótulo}` (+ ` · {rótulo da coluna}`); linha 3 `Gerado em … (horário de Brasília) por {nome}`; linha 4
vazia; cabeçalho `Nº, Título, Status, Data, Analista, Grupo de Solução` (+ `Destino` em `encaminhamentos`); datas em
Brasília `dd/mm/aaaa hh:mm`.

#### Indicadores por grupo

`GET /relatorios/indicadores` (`auth:web` + `can:relatorios.view`, `App\Http\Controllers\Api\IndicadoresController`):
abertos, resolvidos, fechados e cancelados por grupo de solução, ou por integrante de um grupo. O número de cada evento
vai sempre para o **grupo do autor no momento** (`incidente_eventos.autor_grupo_solucao_id`), nunca para o grupo atual do
chamado nem para o grupo atual da pessoa. `index()` valida → `ConsultaIndicadores::deDados()` → `responder($c, $formato, $user)` (público, para o
relatório salvo), que começa por `validarVisibilidade($user)` — nenhum chamador consegue pular a checagem. Código em `app/Relatorios/Indicadores/`:

| Classe | Papel |
|---|---|
| `ConsultaIndicadores` (readonly) | `grupos` (ids int na ordem pedida; vazio = todos), `porIntegrante`, `quebra`, `inicio`/`fim` (Y-m-d, atalho resolvido), `atalho`. `regras()`, `deDados($validado, ?$agora)`, `validarVisibilidade($user)` |
| `FonteIndicadores::linhas($c, $user)` | uma única consulta em `incidente_eventos` (`whereIn('tipo')`, sem hidratar models) → `Collection<LinhaIndicador>`. `retroativos($c)` → `[indicadores, último instante]` para o aviso. `TIPOS` (indicador → tipo), `CHAVE_PORTAL = 'portal'`, `CHAVE_SEM_AUTOR = '__sem_autor__'` |
| `LinhaIndicador` (readonly) | `indicador`, `chave` (id do grupo, id do usuário, `portal` ou `__sem_autor__`), `base` (`LinhaBase`) |
| `AgregadorIndicadores::agregar($c, $linhas, $user)` | corpo JSON (abaixo) |
| `App\Exports\IndicadoresExport` | planilha (abaixo); mesmo `bindValue` de `RelatorioTabelaExport` (texto sempre string, sem fórmulas) |
| `TextosIndicadores` | `titulo($c)`, `LEGENDA`, `aviso($indicadores, $ultimo)`, `ROTULOS` |

**Parâmetros.** `periodo` **ou** `data_inicio`+`data_fim` (obrigatório: sem nenhum → 422 em `periodo` "Informe um
período."), `quebra`, com as mesmas regras e mensagens do caminho `contar` (as de período vêm de
`ConsultaRelatorio::regras()`, a cruzada de quebra de `ConsultaRelatorio::regraQuebra()`); `grupos` (array de ids
existentes, distintos); `por_integrante` (boolean; exige exatamente um grupo → 422 em `por_integrante` "Selecione um único
grupo para detalhar por integrante."); `formato` (`json|xlsx`). Para não admin, grupo pedido fora de
`visibleGrupoSolucaoIds()` → 422 em `grupos.N` "Grupo fora dos seus grupos visíveis.".

**Indicadores** (ordem fixa): `abertos` = evento `aberto`, `resolvidos` = `resolvido`, `fechados` = `fechado`,
`cancelados` = `cancelado`. Data = `created_at` do evento em dias de Brasília (`PeriodoRelatorio::inicioUtc`/
`fimExclusivoUtc`; um evento de 31/10 23:30 BRT cai em outubro).

**Classificação de cada evento** (os três ramos da consulta são disjuntos):
- **portal** — `aberto` com `autor_customer_id`. Só tem valor em Abertos. Com `grupos` (ou `por_integrante`), conta só se o
  **primeiro grupo atribuído** ao chamado ∈ grupos: o `grupo_solucao_id` do próprio `aberto` quando não nulo (ex.: backfill
  de chamado nunca encaminhado); senão o `alvo_id` do `encaminhado_grupo` mais antigo do chamado (por `created_at`, depois
  `id`; em qualquer data, mesmo fora do período). O portal abre sem grupo e a triagem encaminha via `update()`, então o
  `aberto` ao vivo sempre tem `grupo_solucao_id` nulo. Em SQL (`FonteIndicadores::primeiroGrupoEm`, `whereExists`
  correlacionado), o que vale também para a lista de chamados da célula. Sem `grupos`, todos.
- **agente** — `user_id` e `autor_grupo_solucao_id` preenchidos. Chave = `autor_grupo_solucao_id` (ou `user_id` por
  integrante). Com `grupos`, só autor_grupo ∈ grupos; sem `grupos`, para não admin, autor_grupo ∈ visíveis.
- **sem autor** — `user_id` e `autor_customer_id` nulos, ou `user_id` preenchido com `autor_grupo_solucao_id` nulo (grupo
  excluído). Só sem `grupos` (e, portanto, fora do detalhamento por integrante).
- Visibilidade de portal e sem autor (não admin): o chamado precisa ser visível hoje (`Incidente::visiveisPara`). Admin
  vê tudo.

**Linhas.** Com `grupos`: exatamente esses grupos, mesmo zerados. Sem `grupos`: grupos visíveis com `ativo = true` +
qualquer grupo com evento contado. Por integrante: usuários (`withTrashed`) com evento contado no grupo — inclusive quem
já saiu dele — + usuários não excluídos com `users.grupo_solucao_id` = o grupo, zerados; rótulo = nome (`"Nome
(excluído)"` para soft-deleted; `#id` se o usuário não existe mais em `users`). Ordenação: soma dos quatro totais desc, depois rótulo (`AgregadorRelatorio::ordenar`,
agora público). Depois vêm `portal` ("Cliente (portal)", sempre presente) e `__sem_autor__` ("Sem autor registrado", só
quando não zerada). `tipo` da linha: `grupo`|`usuario`|`portal`|`sem_autor`. Chaves sempre string.

**Contagem.** Célula = chamados distintos (`incidente_id`) por linha × indicador × coluna; total da linha = soma das
células; `totais[indicador]` = soma das linhas; `distintos[indicador]` = chamados distintos do indicador no resultado
(chamado resolvido por N1 e por N2 no período: Resolvidos N1 = 1, N2 = 1, total 2, distintos 1). As colunas são
independentes: resolvido e fechado no período conta nas duas.

**Resposta:**

```json
{
  "titulo": "Indicadores por grupo de solução — de 01/10/2026 a 31/10/2026 — grupos: N1, N2",
  "periodo": {"inicio": "2026-10-01", "fim": "2026-10-31"}, "quebra": "nenhuma",
  "grupos": [1, 2], "por_integrante": false,
  "indicadores": [{"chave": "abertos", "rotulo": "Abertos"}, {"chave": "resolvidos", "rotulo": "Resolvidos"},
                  {"chave": "fechados", "rotulo": "Fechados"}, {"chave": "cancelados", "rotulo": "Cancelados"}],
  "colunas": [{"chave": "total", "rotulo": "Total"}],
  "linhas": [{"chave": "1", "rotulo": "N1", "tipo": "grupo",
              "valores": {"abertos": {"celulas": {"total": 2}, "total": 2}, "resolvidos": {...}, "fechados": {...}, "cancelados": {...}}}],
  "totais": {"abertos": {"celulas": {"total": 2}, "total": 2}, ...},
  "distintos": {"abertos": 2, "resolvidos": 1, "fechados": 1, "cancelados": 0},
  "legenda": ["...5 textos..."], "aviso": null
}
```

Colunas de `PeriodoRelatorio::colunas()` (sem quebra, `[{chave: "total", rotulo: "Total"}]`, como no caminho `contar`);
cada evento cai na sua por `PeriodoRelatorio::colunaDe()`.

**Título:** `Indicadores por grupo de solução — de dd/mm/aaaa a dd/mm/aaaa[, por {dia|semana|mês}][ — grupos: N1, N2][ —
por integrante]` (grupos na ordem pedida). **Legenda:** os cinco textos fixos de `TextosIndicadores::LEGENDA` (um por
indicador + "As colunas são independentes…"). **Aviso:** se existe no período algum evento de um dos 4 tipos com `retroativo = true` (backfill),
`"Dados aproximados em {colunas}: registros até dd/mm/aaaa foram reconstruídos e usam o grupo atual da pessoa, não o da
época."` — colunas na ordem fixa, unidas por vírgula e "e" ("Abertos, Fechados e Cancelados"); data = `created_at`
(Brasília) do retroativo mais recente no período. Sem retroativos, `null`. **Ignora `grupos`, `por_integrante` e a
visibilidade** (`FonteIndicadores::retroativos`, uma consulta agrupada por `tipo` sobre o índice `(tipo, created_at)`): o
backfill põe o evento no grupo **atual** da pessoa, então quem filtra o grupo antigo dela (ou só vê esse grupo) está
justamente subcontado e precisa do aviso.

**Planilha** (`formato=xlsx`, tratada dentro de `responder()`, então vale também para relatórios salvos): título; `Gerado em
dd/mm/aaaa hh:mm (horário de Brasília) por {nome}`; linha em branco; cabeçalho `Grupo` (ou `Integrante`) + para cada
indicador uma coluna por período (só com quebra) e `{Indicador} — Total`; as linhas (inclusive portal / sem autor); `Total`;
`Chamados distintos` (valores de `distintos` sob as colunas de total, vazio nas de período); linha em branco; `Legenda` + os
5 textos; o aviso, se houver. Registra `Auditoria::registrar('exportado', ...)` com o título do relatório.

**Lista de chamados por célula** — `GET /relatorios/indicadores/chamados` (mesmo middleware; `IndicadoresController::chamados`):
os parâmetros da consulta + `chave` (obrigatório, chave da linha), `indicador` (obrigatório, um dos 4), `coluna` (opcional;
inexistente no período → 422 em `coluna` "Coluna inexistente neste período."), `page`, `per_page` (máx. 200, padrão 50) e
`formato` (`json|xlsx`). Valida a visibilidade como o relatório (grupo fora dos visíveis → 422). A lista parte das mesmas
`LinhaIndicador` do número (`FonteIndicadores::linhas`), filtradas por chave × indicador × coluna, e traz **um item por
chamado**, com o evento mais recente no escopo; assim `meta.total` == valor da célula. Sem `coluna` (linha inteira com
quebra), um chamado que conta em duas colunas aparece uma vez, então `meta.total` pode ser menor que o total da linha.
Resposta: `{titulo, linha: {chave, rotulo}, indicador: {chave, rotulo}, coluna: {chave, rotulo}|null, data, meta}` — `data`
e `meta` no formato de `GET /relatorios/chamados` (`ListaChamados::montar($user, $linhas, $comDestino)`, que não depende
mais de `ConsultaRelatorio`; `acessivel: false` quando o chamado, hoje, não é visível ao usuário, ex.: resolvido por um
agente do N2 e agora no N3). O `xlsx` usa `RelatorioChamadosExport` e registra auditoria.

#### Mapa de fluxos

`GET /relatorios/fluxos` (`auth:web` + `can:relatorios.view`, `App\Http\Controllers\Api\FluxosController`): como os
chamados entram em cada grupo de solução no período, para onde saem e quantos cada grupo resolve. `index()` valida →
`ConsultaIndicadores::deDados()` → `responder($c, $formato, $user)` (público), que começa por `validarVisibilidade($user)`.
Código em `app/Relatorios/Fluxos/`:

| Classe | Papel |
|---|---|
| `Passagem` (readonly) | `origem`, `destino` (chaves de nó, já com a visibilidade aplicada), `via` (`encaminhamento`\|`abertura`), `base` (`LinhaBase`, chave `"{origem}>{destino}"`, `eventoId`, `autorId`, `data`) |
| `FonteFluxos` | `passagens($c, $user)` (uma consulta para `encaminhado_grupo`, outra para `aberto`), `abertos($c, $user)` (chave = id do grupo do autor ou `cliente`), `resolvidos($c, $user)` (chave = `grupo_solucao_id` do evento), `retroativos($c)` (instante UTC ou `null`). Constantes `CLIENTE`, `SEM_GRUPO`, `ORIGEM_DESCONHECIDA`, `OUTROS` |
| `AgregadorFluxos::agregar($c, $user)` | corpo JSON (abaixo) |
| `TextosFluxos` | `titulo($c)`, `ROTULOS` (nós especiais), `LEGENDA`, `aviso($ultimo)` |

**Parâmetros.** Os de `ConsultaIndicadores::regras()` sem `quebra` e `por_integrante` (a consulta usa `quebra = 'nenhuma'`,
`porIntegrante = false`): `periodo` **ou** `data_inicio`+`data_fim` (obrigatório, 422 em `periodo` "Informe um período."),
`grupos` (array de ids), `formato` (`json` ou `xlsx`). Grupo pedido fora dos visíveis → 422 em `grupos.N` "Grupo fora dos
seus grupos visíveis.". Data = `incidente_eventos.created_at` em dias de Brasília.

**Passagem** = evento do período que leva um chamado de uma origem X a um grupo Y, com X ≠ Y (X = Y é descartada):
- `encaminhado_grupo`: Y = `alvo_id`; X = `origem_grupo_id` se preenchido; senão `cliente` se o chamado foi aberto pelo
  portal com `grupo_solucao_id` nulo e não há `encaminhado_grupo` anterior a este (por `created_at`, depois `id`, em
  qualquer data — subconsulta correlacionada em SQL); senão `origem_desconhecida` se `retroativo = true` (o backfill da
  origem marca assim os encaminhamentos de origem desconhecida); senão `sem_grupo` (inclui o encaminhamento depois de um
  `removido_grupo`).
- `aberto` com `grupo_solucao_id` = Y: pelo portal → X = `cliente`; por agente com `autor_grupo_solucao_id` = A ≠ Y → X = A.
  Aberto pelo próprio Y, ou com A nulo, não é passagem.

**Nós e números.** Chaves: id do grupo (string), `cliente`, `sem_grupo`, `origem_desconhecida`, `outros` (o `tipo` é `grupo`
ou a própria chave especial; rótulos: nome do grupo, `Cliente`, `Sem grupo`, `Origem desconhecida`, `Outros grupos`). Para o
grupo G: `abertos` = eventos `aberto` com `autor_grupo_solucao_id = G`; `recebidos` = passagens com destino G;
`encaminhados` = passagens com origem G (inclui aberturas de G direto para outro grupo, para a soma das setas que saem
bater); `resolvidos` = chamados distintos com `resolvido` e `grupo_solucao_id = G` (qualquer que seja o grupo de quem
resolveu). `cliente`: só `abertos` (eventos `aberto` do portal), as outras três `null`; demais especiais: as quatro `null`.
Arestas contam passagens (eventos): ida e volta N1 → N2 → N1 dá N1 → N2 = 1 e N2 → N1 = 1.

**Visibilidade (não admin).** A ponta de grupo invisível vira `outros`; a passagem só entra se ao menos uma ponta é um grupo
visível (Cliente sozinho não basta, para não revelar o volume de outros grupos). `abertos`/`resolvidos` só de grupos
visíveis; `cliente.abertos` só de chamados visíveis hoje (`Incidente::visiveisPara`).

**Filtro `grupos`.** Entram as passagens com ao menos uma ponta nos grupos pedidos. Nós de grupo = os pedidos (sempre, mesmo
zerados) + as outras pontas dessas passagens que são grupos visíveis. Essas pontas fora da seleção são **vizinhos**
(`vizinho: true`): aparecem só para ancorar as setas, com as quatro métricas `null` (seriam parciais). Todo nó, de grupo ou
especial, traz o booleano `vizinho` (`false` fora desse caso, e sempre `false` sem filtro). `cliente.abertos` conta só chamados cujo
primeiro grupo atribuído está nos pedidos (`FonteIndicadores::primeiroGrupoEm`, agora público). **Sem filtro:** grupos
visíveis com algum número > 0 ou alguma passagem.

**Ordem.** Nós: `cliente` (sempre), `sem_grupo` e `origem_desconhecida` (quando têm passagem), grupos por nome (collator de
`AgregadorRelatorio::ordenar`), `outros` (quando tem passagem). Arestas: `quantidade` desc, depois `origem`, depois
`destino` (comparação de texto das chaves).

**Resposta:**

```json
{
  "titulo": "Mapa de fluxos — de 01/10/2026 a 31/10/2026 — grupos: N1",
  "periodo": {"inicio": "2026-10-01", "fim": "2026-10-31"}, "grupos": [1],
  "nos": [{"chave": "cliente", "rotulo": "Cliente", "tipo": "cliente", "vizinho": false,
           "abertos": 15, "recebidos": null, "encaminhados": null, "resolvidos": null},
          {"chave": "1", "rotulo": "N1", "tipo": "grupo", "vizinho": false, "abertos": 2, "recebidos": 15, "encaminhados": 3, "resolvidos": 9},
          {"chave": "2", "rotulo": "N2", "tipo": "grupo", "vizinho": true, "abertos": null, "recebidos": null, "encaminhados": null, "resolvidos": null}],
  "arestas": [{"origem": "cliente", "destino": "1", "quantidade": 15}, {"origem": "1", "destino": "2", "quantidade": 3}],
  "legenda": ["...5 textos..."], "aviso": null
}
```

**Título:** `Mapa de fluxos — de dd/mm/aaaa a dd/mm/aaaa[ — grupos: N1, N2]`. **Legenda:** os cinco textos de
`TextosFluxos::LEGENDA`. **Aviso:** se existe no período evento `aberto`, `encaminhado_grupo` ou `resolvido` com
`retroativo = true`, `"Dados aproximados: registros até dd/mm/aaaa foram reconstruídos; nesses casos a origem de um
encaminhamento pode aparecer como desconhecida e o grupo de quem abriu é o grupo atual da pessoa."` (data = `created_at` do
mais recente, em Brasília); senão `null`. Ignora `grupos` e a visibilidade, como nos Indicadores.

**Lista de chamados** — `GET /relatorios/fluxos/chamados` (mesmo middleware): os parâmetros da consulta + **um** dos modos,
mais `page`, `per_page` (máx. 200, padrão 50) e `formato` (`json`\|`xlsx`). Valida a visibilidade primeiro (mesmo 422 em `grupos.N`).
- Modo nó: `no` (chave) + `metrica` (`abertos`, `recebidos`, `encaminhados`, `resolvidos`). Modo seta: `origem` + `destino`.
  Os dois modos juntos, ou nenhum (ou um modo incompleto), dão 422 em `no`: "Informe um nó e uma métrica, ou uma origem e um
  destino.". Nó que não está no mapa: 422 em `no` "Nó inexistente neste mapa.". Métrica `null` para o nó (`cliente.recebidos`,
  qualquer métrica de especial ou de vizinho sob o filtro `grupos`) dá 422 em `metrica` "Este número não existe para este nó."; a seta que toca
  um vizinho continua listável.
- Unidade: `abertos` e `resolvidos` um item por chamado (evento mais recente); `recebidos`, `encaminhados` e seta um item por
  passagem (`ListaChamados::montar(..., comDestino: true)`), de modo que `meta.total` = número clicado. Vêm das mesmas
  linhas do agregador (`FonteFluxos`).
- Resposta: `{titulo, filtro: {rotulo}, data, meta}` — `rotulo` = `"N1 · Recebidos"` ou `"Cliente → N1"`; `data` e `meta` no formato
  de `GET /relatorios/indicadores/chamados`. Não vaza grupo invisível: o `destino` de uma passagem sai do rótulo do nó
  (`Outros grupos`) e `grupo_chamado` vira `null` se o grupo atual do chamado não é visível ao usuário; `acessivel` segue
  `ListaChamados::montar` (regra de `visiveisPara` de hoje).
- `xlsx`: `RelatorioChamadosExport` (`mapa-de-fluxos-chamados.xlsx`), com coluna `Destino` nas passagens.

**Planilha** — `formato=xlsx` em `GET /relatorios/fluxos` (`responder()`, `App\Exports\FluxosExport`, arquivo
`mapa-de-fluxos.xlsx`), na ordem: título; `Gerado em dd/mm/aaaa hh:mm (horário de Brasília) por {nome}`; linha em branco;
`Nós` + cabeçalho `Grupo | Abertos pelo grupo | Recebidos | Encaminhados | Resolvidos` + uma linha por nó (célula vazia onde a
métrica é `null`, inclusive vizinhos); linha em branco; `Fluxos` + `Origem | Destino | Quantidade` (rótulos dos nós); linha em
branco; `Legenda` + 5 textos; o aviso, se houver. Todo texto é gravado como string (sem fórmulas). As duas exportações
(planilha e lista) registram auditoria `exportado` (`entidade_type = relatorio`, `parametros` da consulta executada).

**Limites conhecidos.**
- Histórico antigo A → (sem grupo) → B é preenchido com origem A: remoções de grupo não eram registradas antes de `removido_grupo`.
- Excluir um grupo de solução que só tem histórico anula o id dele nos snapshots e em `origem_grupo_id` (`nullOnDelete`), mas `alvo_id` guarda o id morto; o balanço de fluxo quebra só para grupos excluídos (grupo com chamados atuais não pode ser excluído, `409`).
- `encaminhados` inclui as aberturas de G direto para outro grupo, então a soma das setas que saem de G é sempre igual a `encaminhados`.

### 3.4.10. Endpoints — Portal do cliente (`App\Http\Controllers\Api\Portal\IncidenteController`)

Guard `customer` (`auth:customer`); token de staff recebe `401`. Controller separado do
`IncidenteController` do staff de propósito — o payload do staff aceita classificação,
encaminhamento e origem, que o cliente não pode tocar.

| Método | Rota | Middleware | Body | Resposta |
|---|---|---|---|---|
| `GET` | `/customer/incidentes` | `auth:customer` | `?busca=` (número exato ou trecho do título, sem diferenciar maiúsculas), `?status=` (um de `Incidente::STATUSES`) | `200` — paginado (15), mais recente primeiro, **só `customer_id` = o cliente do token** (inclui os abertos pelo staff em nome dele) |
| `POST` | `/customer/incidentes` | `auth:customer`, `throttle:portal-abertura` (10/min por cliente+IP) | `{titulo, descricao}` | `201` — `{data: {...}}` |
| `GET` | `/customer/incidentes/{incidente}` | `auth:customer` | — | `200` — o chamado + `descricoes`; `404` se o chamado não é do cliente |
| `POST` | `/customer/incidentes/{incidente}/comentarios` | `auth:customer`, `throttle:portal-abertura` | `{descricao}` (obrigatório, até 5000) | `201` — a entrada criada (`tipo: comentario`, autor = o cliente); `404` se não é do cliente; `422` em `descricao` se o chamado está `fechado` ou `cancelado` |
| `POST` | `/customer/incidentes/{incidente}/reabrir` | `auth:customer`, `throttle:portal-abertura` | `{motivo}` (obrigatório, até 5000) | `200` — o chamado no formato do detalhe (`status: aberto`); `404` se não é do cliente; `422` em `motivo` (ausente/em branco) ou em `status` (chamado não está `fechado`, ou passou o prazo de 7 dias) |

`App\Http\Resources\PortalIncidenteResource`: só `id`, `titulo`, `status`, `created_at`,
`updated_at` — sem grupo, responsável, prioridade, SLA ou classificação. No detalhe, também
`descricoes` (`PortalDescricaoResource`: `id`, `tipo`, `descricao`, `created_at`,
`autor: {nome, equipe}` — `equipe` = escrito pelo staff) e os dados de reabertura:
`reabrir_ate` (ISO 8601, `null` se o status não é `fechado`) e `pode_reabrir` (bool). Esses dois
ficam fora da listagem de propósito (seria uma consulta de evento por linha).

> 📌 **Reabertura pelo cliente (2026-11-13).** Só chamado **`fechado`** (não `resolvido` nem
> `cancelado`), até **7 dias** (`Incidente::PRAZO_REABERTURA_PORTAL_DIAS`) depois do fechamento
> **mais recente** — o último `IncidenteEvento` `fechado`; chamado reaberto e fechado de novo ganha
> prazo novo; sem evento (legado), conta de `concluido_em` e, por fim, `updated_at`
> (`Incidente::reaberturaPortalPermitidaAte()`). Motivo obrigatório. O chamado volta para
> **`aberto` mantendo grupo e responsável** (volta à fila de quem já atendeu), com os mesmos efeitos
> de uma reabertura feita pelo staff: `concluido_em` limpo (o SLA de resolução volta a contar contra
> o `prazo_resolucao` original, que **não** é recalculado), `IncidenteEvento` `reaberto` com
> `autor_customer_id` (e `user_id` nulo), entrada `alteracao` "Campo 'Status' alterado de 'fechado'
> para 'aberto'…" no feed do staff (mesmo formato que o backfill de eventos lê) e o motivo como
> comentário do cliente ("Chamado reaberto pelo cliente. Motivo: …"). No log de ações (§3.4.11),
> aparece como `atualizado` do chamado com autor cliente. Não há notificação ao staff: o chamado
> reaparece na fila como `aberto`.

> 📌 **O feed do staff é interno.** No detalhe, o cliente vê só: a **descrição de abertura**
> (primeira entrada, mesmo quando o staff abriu em nome dele), os **próprios comentários** e a
> **mensagem de encerramento** (`tipo: encerramento`). Comentários do staff, escalonamentos,
> alterações e comentários excluídos nunca aparecem — inclusive a linha de alteração de status que a
> reabertura grava com autoria do cliente: do cliente, só entram entradas `tipo: comentario`.

> 📌 **Por auditoria, o cliente só abre em nome próprio.** Tudo além de `titulo`/`descricao` é
> fixado no servidor e qualquer valor no payload é ignorado: `customer_id` e
> `criado_por_customer_id` = o cliente do token; `tipo = 'incidente'` (Solicitação é só staff);
> `origem = 'portal'`; `status = 'aberto'`; `item_id`/`prioridade`/`prazo_*`/`grupo_solucao_id`/
> `responsavel_id`/`criado_por_id` = `null`. A descrição vira a primeira entrada do feed com
> `customer_id` como autor.

> 📌 **Classificação na triagem, SLA desde a abertura.** O cliente não classifica. O chamado nasce
> sem grupo (visível a todo staff pela regra de `visiveisPara()`) e sem prioridade (status de SLA
> `sem_sla`). Quando o staff define o `item_id` pela primeira vez (`PUT /incidentes/{id}`), o
> `update()` deriva a prioridade do item e calcula os prazos a partir de `created_at` — o tempo
> de triagem consome o prazo, de propósito (tempo percebido pelo cliente, padrão ITSM).

### 3.4.11. Endpoints — Log de ações (`App\Http\Controllers\Api\AuditLogController`)

Tela **Administração > Auditoria > Logs de ações** do frontend. Permission `auditoria.view` (só
`admin`, concedida pela migration `2026_11_12_000001_add_auditoria_view_permission` — produção não
roda o seeder a cada deploy).

| Método | Rota | Middleware | Parâmetros | Resposta |
|---|---|---|---|---|
| `GET` | `/audit-logs` | `auth:web`, `can:auditoria.view` | **`data_inicio`, `data_fim` obrigatórios** (`Y-m-d`, dias de Brasília, inclusivos, no máximo 93 dias); opcionais `user_id`, `customer_id`, `ator_type` (`user`\|`customer`), `acao[]`, `entidade_type`, `entidade_id`, `ip_address`, `busca` (até 100 caracteres), `page` | `200` — `{data: [{id, created_at, acao, acao_rotulo, ator: {type, id, nome}\|null, entidade: {type, type_rotulo, id, label}\|null, alteracoes, ip_address, user_agent}], meta: {current_page, per_page: 50, has_more}}`, mais recente primeiro; `422` sem período ou com período acima do limite |
| `GET` | `/audit-logs/opcoes` | idem | — | `200` — `{acoes: [{value, title}], entidades: [{value, title}], max_dias, retencao_meses}` (não consulta a tabela) |

> 📌 **Nada carrega sem filtro.** A tela abre só com o formulário (o período vem preenchido com os
> últimos 7 dias, mas nada é consultado) e só chama `/audit-logs` ao clicar em **Consultar**. O
> backend reforça: sem período → `422`, período limitado, e `simplePaginate` (sem `COUNT(*)` numa
> tabela que só cresce — o frontend usa "Carregar mais").

**O que é registrado**

| Origem | Ações |
|---|---|
| Trait `Auditavel` (eventos `created`/`updated`/`deleted` do Eloquent) em `User`, `Customer`, `Client`, `PoliticaSla`, `Categoria`, `Subcategoria`, `Item`, `GrupoSolucao`, `RelatorioSalvo`, `Incidente`, `IncidenteDescricao`, `Anexo` | `criado` (valores iniciais não nulos como `{campo: {de: null, para}}`), `atualizado` (só as colunas que mudaram, `{de, para}`; sem linha se nada auditável mudou), `excluido` (inclui soft delete) |
| `Auditoria::registrarRelacao()` — `sync()` de pivot não dispara evento de model | papéis do usuário (`papeis`), grupos visíveis extras (`grupos_visiveis`), permissões liberadas/bloqueadas do grupo de solução (`permissoes_liberadas`/`permissoes_bloqueadas`, por slug) |
| `AuthController` | `login`, `login_falhou` (e-mail tentado + id da conta, se existir), `token_renovado`, `logout`, `logout_todos`, `senha_redefinida` |
| `UserController`/`CustomerController::enviarConvite()` | `convite_enviado` |
| `AnexoController::download()` | `download` |
| `RelatorioController`/`RelatorioConsultaController` (`formato=xlsx`, inclusive via `relatorios-salvos/{id}/executar`) | `exportado`, com os parâmetros da consulta |

Regras:
- **Só com usuário autenticado.** O trait ignora eventos sem `Auth::user()` — seeders,
  `chamados:popular`, factories e o `save()` interno do reset de senha (que tem log explícito) não
  geram linhas. O autor é o usuário do guard que autenticou o request (`web` ou `customer`), então
  ações do portal aparecem com autor "Usuário de cliente".
- **Nunca grava segredo:** `password`/`remember_token` (e `id`/timestamps) ficam fora; troca de
  senha aparece como `senha: (alterada)`.
- **Chamado, modo econômico:** em `Incidente` só as colunas do `#[Fillable]` são consideradas
  (prazos de SLA/`concluido_em` recalculados não geram linha) e `alteracoes` guarda **só os nomes**
  (`{campos: ["status", ...]}`) — os valores de/para já ficam no feed do chamado
  (`alteracao`/`escalonamento`). Em `IncidenteDescricao` só entra `comentario` (entradas de sistema
  são efeito de uma ação já registrada), sem o texto.
- **Leituras comuns não são registradas** — só download de anexo e exportação de planilha.
- **Síncrono**, no mesmo request (e na mesma transação, quando a ação está num
  `DB::transaction()`): rollback leva o log junto.
- **Retenção de 6 meses:** `model:prune --model=App\Models\AuditLog` agendado diariamente às 03:30
  em `routes/console.php`; em produção roda no container `scheduler` (`php artisan schedule:work`,
  `docker-compose.vps.yml`), que o deploy sobe junto com `app` e `queue`.

### 3.5. Outras decisões de implementação

- **`AppServiceProvider::boot()`**:
  - `RateLimiter::for('login', ...)` — 5 tentativas/min, chave `lower(email)|ip`, cobre `/login`, `/customer/login` e `/refresh`.
  - `ResetPassword::createUrlUsing(...)` — o backend é API-only (não existe rota web nomeada `password.reset`), então a notificação de reset é redirecionada para `{FRONTEND_URL}/reset-password?token=...&email=...`, onde o Vue faz o `POST /api/reset-password`.
- **`config/app.php`**: nova chave `'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173')`, consumida pelo item acima.
- **`config/cors.php`**: `allowed_origins` lido de `FRONTEND_URL` (suporta lista separada por vírgula), nunca `*`; `supports_credentials` `false` (já é o padrão do Laravel, mantido).
- **`AuthServiceProvider::boot()`**: `Gate::before(fn($user, $ability) => $user instanceof User && $user->hasPermission($ability) ? true : null)` — `Customer` nunca passa nesse `before` (cai direto pra Policy, se houver).
- **Soft delete em `User` e `IncidenteDescricao`, deliberadamente não estendido a mais nada.** Motivação
  original: excluir um `User` referenciado por `Incidente.responsavel_id`/`IncidenteDescricao.user_id`/
  `Anexo.user_id` (todas `restrictOnDelete()`) jogava um `QueryException` cru (500) — a correção óbvia
  seria checar cada FK antes do `delete()` (padrão já usado em `Client`/`Categoria`/`GrupoSolucao`/etc.,
  `409` explícito), mas isso não escala: toda nova relação pra `User` exigiria mais um guard manual. Soft
  delete resolve de vez (a linha nunca some, a FK nunca é violada) e ainda preserva o histórico de quem
  fez o quê num chamado — decisão de produto tanto quanto técnica. Estendido também a
  `IncidenteDescricao` (excluir um `comentario` não apaga, só marca — auditoria de comunicação do
  chamado). **Não** aplicado a `Client`/`Customer`/`Categoria`/`Subcategoria`/`Item`/`PoliticaSla`/
  `GrupoSolucao`/`Anexo` — esses não têm o mesmo problema de "referência histórica quebrada por exclusão
  de verdade" (ou já são bloqueados por `409` antes de chegar nesse ponto, ou soft delete simplesmente
  não faz sentido pro tipo de dado). Detalhes: `users` em §3.1, `incidente_descricoes` em §3.1,
  `DELETE /users/{user}` em §3.4.3, `DELETE /incidentes/{incidente}/descricoes/{descricao}` em §3.4.7.1.
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
| Extensão `gd` | não mencionada | `libjpeg-turbo-dev`/`libpng-dev`/`freetype-dev` + `docker-php-ext-configure gd`/`docker-php-ext-install gd` | Necessária pra `AnexoController::removerMetadadosSeImagem()` (remoção de EXIF via reencode, ver §3.4.7.2) — exige `docker compose build app`, não só `restart`, num ambiente que já existia antes dessa mudança |
| Extensão `zip` | não mencionada | `libzip-dev` + `docker-php-ext-install zip` | `phpoffice/phpspreadsheet` (via `maatwebsite/excel`) depende dela pra gerar `.xlsx` — mesma situação do `gd`, mesmo `docker compose build app` necessário (ver §3.4.9) |

### 3.7. Seed padrão (`RolesAndPermissionsSeeder` + `PoliticasSlaSeeder` + `CategoriasSeeder` + `GruposSolucaoSeeder` + `IncidentesSeeder` + `DatabaseSeeder`) e massa de demonstração sob comando (`ChamadosDemoSeeder`)

Permissions: `users.manage`, `roles.manage`, `clients.manage`, `customers.manage`, `slas.view`,
`slas.manage`, `categorias.view`, `categorias.manage`, `grupos_solucao.view`,
`grupos_solucao.manage`, `tickets.view`, `tickets.manage`, `tickets.assign` (`tickets.assign` é
placeholder ainda não usado por nenhuma lógica — ver nota em §3.4.7), `relatorios.view`,
`relatorios.manage` (ver §3.4.9), `auditoria.view` (log de ações, ver §3.4.11). `admin` recebe todas; `supervisor` recebe `relatorios.view` **e**
`relatorios.manage`; `agente` recebe só `relatorios.view` (roda/vê relatórios, não cria/edita
configuração salva pra equipe toda).

> 📌 **Deploy em ambiente já semeado:** `customers.manage` é permission nova desta feature. Em qualquer ambiente onde `db:seed` já rodou antes dela existir, é preciso rodar `php artisan db:seed` de novo após o deploy para que a role `admin` receba essa permission — do contrário o item de menu "Usuários de Clientes" do frontend (que é liberado pela role `admin`, não por essa permission) já aparece, mas as chamadas à API de `Customer` retornam `403` até o reseed.

| Role (`slug`) | Permissions atribuídas |
|---|---|
| `admin` | todas |
| `supervisor` | `tickets.view`, `tickets.manage`, `tickets.assign`, `slas.view`, `categorias.view`, `grupos_solucao.view` |
| `agente` | `tickets.view`, `tickets.manage`, `slas.view`, `categorias.view`, `grupos_solucao.view` |

`PoliticasSlaSeeder` cria as 4 políticas "padrão global" (`client_id = null`, uma por prioridade,
`updateOrCreate` por `(client_id, prioridade)` — idempotente):

| Prioridade | Nome | Resposta | Resolução |
|---|---|---|---|
| `urgente` | Padrão Urgente | 15 min | 240 min (4h) |
| `alta` | Padrão Alta | 60 min (1h) | 480 min (8h) |
| `media` | Padrão Média | 240 min (4h) | 1440 min (24h) |
| `baixa` | Padrão Baixa | 480 min (8h) | 2880 min (48h) |

Todas com `apenas_horas_uteis = true`, `ativo = true`. São os valores que `Client::resolvedSlaFor()`
devolve pra qualquer cliente sem política própria naquela prioridade.

`CategoriasSeeder` cria a taxonomia de categorias/subcategorias/**itens** de incidentes
(`updateOrCreate` por nome, em cada nível — idempotente), espelhando os produtos atendidos:

| Categoria | Subcategoria | Itens |
|---|---|---|
| Dossie | Detalhado | Divergência, Falha |
| Dossie | Analítico | Divergência, Falha |
| Dossie | Workflow | Critérios, Aprovação |
| Dossie | Upscore | Cálculo, Erro |
| Dossie | Fonte | Não processa, Erro |
| Veiculo | Gravame | Chassi, Erro |
| Veiculo | Chassi | Erro |
| Upminer | Upacademy | Erro, Vídeo não carrega |
| Upminer | SSO | — |
| Uplink | QSA | Erro, Lentidão |
| Uplink | Veiculo | — |
| Fonte | Monitoramento | Captura, Preventivo, Corretivo |

`prioridade_padrao` de cada item segue o ciclo `[null, baixa, media, alta, urgente]` com um índice
**corrido por todo o catálogo** (não reiniciado a cada subcategoria — com 2 itens por subcategoria
um índice reiniciado nunca passaria de `baixa`/`media`). Os itens com `null` continuam existindo de
propósito: o `store()` do staff recusa (`422`) item sem prioridade padrão, o que mantém esse caminho
exercitável; o `ChamadosDemoSeeder` (abaixo) só usa itens **com** prioridade padrão.

> 📌 **Sem role "cliente":** uma versão anterior desta implementação criava uma role `cliente` vazia (seguindo o texto literal do rascunho original da spec) e chegava a semear um usuário fictício `cliente.interno@example.com` só pra ocupá-la. Isso foi removido — não faz sentido nenhum `User` ter uma role "Cliente", já que quem abre chamado é sempre o model/guard `Customer` (sem RBAC nenhum, ver seção 1.3). Manter essa role só confundia qual guard estava sendo testado.

`GruposSolucaoSeeder` cria 4 grupos padrão (`updateOrCreate` por `nome` — idempotente): **Suporte
N1**, **Suporte N2**, **Redes**, **Administração**. Precisa rodar **antes** da criação dos `User`s
no `DatabaseSeeder`, já que `grupo_solucao_id` é `NOT NULL` (ver §3.1).

`DatabaseSeeder` também cria usuários de teste, um por role (guard `web`), todos com senha `password`:

| E-mail | Role | Grupo de Solução |
|---|---|---|
| `admin@example.com` | `admin` | Administração |
| `supervisor@example.com` | `supervisor` | Suporte N2 |
| `agente@example.com` | `agente` | Suporte N1 |

E do lado `customer` (quem de fato abre chamado pelo portal):
- `cliente@example.com` (senha `password`) — e-mail fixo, para login previsível.
- +3 customers extras com dados aleatórios via `Customer::factory()->count(3)->create()` — só massa pra exercitar listagem/paginação, sem senha/e-mail previsíveis (usar o e-mail fixo acima pra testar login).

> 📌 **Idempotência:** os registros de e-mail fixo (`admin@example.com`, `supervisor@example.com`, `agente@example.com`, `cliente@example.com`) são criados via `updateOrCreate(['email' => ...], [...])`, não `factory()->create()` — `db:seed` (sem `--fresh`) precisa poder rodar quantas vezes forem necessárias sem quebrar. Uma versão anterior usava `create()` direto e uma segunda chamada a `db:seed` sem `migrate:fresh` derrubava com `UniqueConstraintViolationException` no e-mail do admin. Os 3 customers aleatórios **não** são idempotentes por design (rodar `db:seed` de novo não os duplica, mas também não os "atualiza" — a condição `if (Customer::count() <= 1)` só os cria na primeira vez).

`IncidentesSeeder` roda por último (depois de customers/itens/grupos/users todos existirem) e cria 2
incidentes de exemplo pro `cliente@example.com`, cada um já com seu feed em `incidente_descricoes`
(a descrição de abertura nunca é passada direto pro `Incidente::create()`, mesma regra do
`IncidenteController::store()`) **e com `prazo_resposta`/`prazo_resolucao` calculados** — o seeder
duplica a lógica de `calcularPrazosSla()` (sem camada de serviço pra compartilhar, ver §3.5; o
seeder não passa pela camada HTTP então não dá pra reaproveitar o método do controller diretamente).

- **"Consulta de gravame não retorna o chassi informado"** (`media`, `portal`, já roteado pro grupo Suporte N1 com o
  `agente@example.com` como responsável, status `em_andamento`) — feed com 3 entradas: abertura
  (`comentario`, autor admin), `escalonamento` pro Suporte N1 (autor admin), e um `comentario` de
  acompanhamento do próprio agente. Demonstra o feed completo.
- **"Consulta de QSA retornando erro para todos os clientes"** (`urgente`, `telefone`, sem roteamento ainda,
  status `aberto`) — feed com só a entrada de abertura. Demonstra o caso "recém-aberto".

Não idempotente por design (mesmo raciocínio dos customers aleatórios — só roda `if (! Incidente::exists())`).

> 📌 **+10 incidentes fechados via `criarIncidentesDemoRelatorios()`** — adicionados depois, sem os
> quais um `migrate:fresh --seed` não dava **nenhum** dado pra ver em `/relatorios/incidentes`
> (os 2 incidentes acima são `em_andamento`/`aberto`, nenhum concluído; `status_sla`/`responsavel`/
> `grupo_solucao` restringem a `STATUS_ATENDIDOS` por padrão, ver §3.4.9). Espalhados de propósito
> por **todas** as dimensões de `agrupar_por`:
> - **`status_sla`**: 6 `dentro_prazo`, 3 `estourado`, 1 `sem_sla` (`prazo_resolucao`/`prazo_resposta`
>   forçados a `null` via `forceFill`, simulando uma política removida depois da abertura — não dá
>   pra obter isso organicamente já que `PoliticasSlaSeeder` cobre as 4 prioridades globalmente).
> - **`responsavel`**: `agente@example.com` (5), `admin@example.com` (2), `supervisor@example.com`
>   (2), 1 sem responsável.
> - **`grupo_solucao`**: Suporte N1 (4), Suporte N2 (3), Redes (2), 1 sem grupo.
> - **`categoria`/`subcategoria`/`item`**: espalhados pelas categorias (Dossie/Veiculo/Uplink/
>   Upminer/Fonte) da taxonomia de `CategoriasSeeder`, 1 sem `item_id`.
> - **Datas**: `created_at` retroativo de 1 a 25 dias (`now()->subDays(N)`, não datas fixas — o seeder
>   continua fazendo sentido não importa quando rodar), pra `data_inicio`/`data_fim` terem o que
>   filtrar.
> - **Eventos**: `encaminhado_*` para grupo/responsável; `resolvido` (+ `fechado`) nos concluídos; o
>   cancelado ganha o evento `cancelado` (autor = responsável, senão admin; `created_at` = `concluido_em`).
> - **Segundo `Client`** ("TechCorp Soluções", `financeiro@techcorp.example.com`) criado só aqui
>   (não em `DatabaseSeeder`) — com um único cliente, o filtro `client_id` nunca mudava o resultado.
> - **Sem feed** (`IncidenteDescricao`) de propósito — o feed completo já é demonstrado no
>   `$incidente1` acima; os 10 novos são massa focada em volume/variedade pra relatórios, não em
>   histórico de comunicação.
> - `criarIncidenteFechado()`: cria o `Incidente` normalmente, sobrescreve `created_at`/`updated_at`
>   via `forceFill` (precisa vir **antes** de `calcularPrazosSla()`, que lê `created_at` pra computar
>   os prazos), depois `forceFill` de `respondido_em`/`concluido_em`.
> - **`aberto_por`**: cada linha da lista tem um "criador" próprio (5ª coluna a mais no array —
>   `$agente`/`$admin`/`$supervisor` variados, não sempre o mesmo autor), setado em
>   `$incidente->forceFill(['criado_por_id' => $criador->id])->save()` logo após a criação — mesmo
>   padrão usado em `$incidente1`/`$incidente2` (linha 60/109), ambos criados pelo `$admin`.
> - **`encaminhado_por`/`encaminhado_para_grupo`/`encaminhado_para_responsavel`**: todo incidente com
>   `grupo`/`responsavel` não-nulo na lista ganha um `IncidenteEvento` `encaminhado_grupo`/
>   `encaminhado_responsavel` correspondente (autor = o próprio `criador` da linha, `alvo` = o
>   `GrupoSolucao`/`User` de destino) — cobre os casos "sem grupo"/"sem responsável" (linhas com
>   `null`) e garante volume nas duas dimensões de destino.
> - **`resolvido_por`/`fechado_por`**: um `IncidenteEvento` `resolvido` por incidente que não é
>   `cancelado` (nunca passou por `resolvido`) — autor = `responsavel` do incidente (ou `admin` se
>   sem responsável) — mais um `IncidenteEvento` `fechado` (mesmo autor) quando `status === 'fechado'`.
>   O **primeiro** incidente da lista ("Dossiê detalhado retorna com falha...") foge desse padrão de propósito, pra
>   simular o cenário de reabertura que motivou a generalização de `incidente_resolucoes` pra
>   `incidente_eventos` (ver §3.1): **dois** eventos `resolvido` (`agente@example.com`, depois
>   `supervisor@example.com`) seguidos de um `fechado` (`supervisor@example.com`) — resolvido,
>   reaberto, resolvido de novo por outra pessoa, fechado por quem resolveu por último; ambas as
>   resoluções preservadas, não só a mais recente.

#### `ChamadosDemoSeeder` — 100 chamados de demonstração, **só por comando**

```bash
docker compose exec app php artisan chamados:popular                  # 100 chamados
docker compose exec app php artisan chamados:popular --quantidade=300 # outra quantidade
```

Comando definido em `routes/console.php` (closure `Artisan::command`), que instancia
`Database\Seeders\ChamadosDemoSeeder`, ajusta `$quantidade` e o invoca. **Não** é chamado pelo
`DatabaseSeeder` — `db:seed`/`migrate:fresh --seed` nunca o executam — porque **não é idempotente**:
cada execução cria mais `--quantidade` chamados. Em produção (`app()->isProduction()`) pede
confirmação, a menos que venha `--force`; `--quantidade` menor que 1 é recusado. Pré-requisito:
`db:seed` já rodado (itens com prioridade padrão, usuários staff, grupos e customers) — sem isso
lança `RuntimeException` com a instrução.

O que gera (Faker `pt_BR` com seed fixa `20261109` — a mesma execução sobre a mesma base gera a mesma
massa):

- **~70% incidentes / ~30% solicitações** (`tipo`, ver §3.1). Execução local de 2026-11-09: 79
  incidentes / 35 solicitações no total da base (100 novos + 14 pré-existentes).
- **Classificação pela taxonomia do `CategoriasSeeder`**: item sorteado entre os ativos **com**
  `prioridade_padrao`; `prioridade` = a do item (mesma regra do `IncidenteController`); prazos de SLA
  calculados (lógica de `calcularPrazosSla()` duplicada, como no `IncidentesSeeder`). Títulos
  derivados do item/subcategoria/categoria (ex.: "Lentidão em QSA (Uplink)" pra incidente, "Criar
  script de reprocessamento para Upscore (Dossie)" pra solicitação).
- **Solicitações** sempre abertas pelo staff (`criado_por_id`), com solicitante da empresa interna:
  `Client` **"Equipe Interna"** + 4 customers fixos (`ana.produtos@`, `bruno.comercial@`,
  `carla.sustentacao@`, `diego.financeiro@interno.example.com`, senha `password`) criados com
  `firstOrCreate` (idempotente) — "a própria empresa é cadastrada como um customer".
- **~20% dos incidentes chegam pelo portal ainda sem triagem**: `origem = portal`, `status = aberto`,
  `item_id`/`prioridade`/prazos nulos, `criado_por_customer_id` = o cliente, descrição de abertura
  com autor cliente (`incidente_descricoes.customer_id`) — alimentam o filtro "sem classificação" e
  o grupo "Portal (cliente)" do relatório `aberto_por` (ver §3.4.10).
- **Status ponderados** (aberto 20, em andamento 20, pendente 10, resolvido 20, fechado 25,
  cancelado 5) e `created_at` espalhado pelos últimos 60 dias. Coerência com o status: fora de
  `aberto` → `respondido_em`; concluídos → `concluido_em` (nunca no futuro); `resolvido`/`fechado`
  → `IncidenteEvento` `resolvido` (+ `fechado`); `cancelado` → `IncidenteEvento` `cancelado` (autor = responsável,
  senão criador; `created_at` = `concluido_em`); grupo/responsável → eventos `encaminhado_*`.
- Toda entrada tem a **descrição de abertura** no feed (autor staff ou cliente).

Coberto por `tests/Feature/Seeders/ChamadosDemoSeederTest.php` (ver §3.9).

Todos permitem login imediato (`POST /api/login` para os 3 users staff, `POST /api/customer/login` para o customer fixo) após `migrate:refresh --seed` ou `migrate:fresh --seed` em ambiente local.

> ⚠️ Credenciais de conveniência para dev local apenas — trocar/remover antes de qualquer ambiente staging/produção.

### 3.8. Suíte de testes automatizados (`tests/Feature/Auth`)

Feature tests com `RefreshDatabase`, dados montados via factories (`UserFactory`, `CustomerFactory`, `RoleFactory`, `PermissionFactory` — as duas últimas criadas junto com essa suíte), **sem depender dos seeders locais** (`RolesAndPermissionsSeeder`/`DatabaseSeeder` nunca são chamados nos testes).

| Arquivo | Cobre |
|---|---|
| `LoginTest.php` | Login staff (com roles/permissions no payload), login customer, e-mail/senha inválidos (staff e customer), e-mail de `User` não autentica no guard `customer` (tabelas separadas), `User` desativado (soft-deleted) não consegue logar mesmo com senha correta |
| `TokenLifecycleTest.php` | `/refresh` (rotação atômica — token antigo é revogado, abilities preservadas), `/logout` (revoga só o token atual), `/logout-all` (revoga todos, não afeta tokens de outro usuário), `401` sem autenticação |
| `PasswordRecoveryTest.php` | Throttle em `/forgot-password` e `/reset-password` (5 tentativas + bloqueio na 6ª, bucket isolado por e-mail), revogação de todos os tokens após reset bem-sucedido (staff e customer), reset com token inválido não muda a senha |
| `ConviteTest.php` | `POST /users/{user}/convite` e `/customers/{customer}/convite` disparam `ConviteUsuario` (`Notification::fake()`/`assertSentTo`), `403` sem `users.manage`/`customers.manage` (nenhuma notificação enviada), `401` sem autenticação, `ConviteUsuarioMail::render()` contém o nome do destinatário/URL do token e não tem nenhuma tag `<img>` |

Rodar com `docker compose exec app php artisan test` (ou `./vendor/bin/phpunit`).

> 🚨 **Incidente durante a implementação — banco de dev real foi zerado por engano.** A primeira versão do `phpunit.xml` (herdada do skeleton do Laravel) configurava `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` via `<env>`, mas isso **não tinha efeito**: como o `docker-compose.yml` usa `env_file: .env` no serviço `app`, as variáveis do `.env` (incluindo `DB_CONNECTION=pgsql`) já chegam setadas tanto em `$_ENV`/`getenv()` quanto em `$_SERVER` antes do PHPUnit subir. O `<env force="true">` do PHPUnit só sobrescreve `$_ENV`/`putenv()` — **não toca em `$_SERVER`** — e o resolvedor de env do Laravel (`Illuminate\Support\Env`, via `vlucas/phpdotenv`) prioriza `$_SERVER`. Resultado: o override para sqlite era ignorado silenciosamente, os testes rodavam contra o Postgres de desenvolvimento de verdade, e o primeiro `RefreshDatabase` da suíte executou o equivalente a um `migrate:fresh` no banco `itsm` real, apagando os dados de dev (foram restaurados depois com `php artisan db:seed`).
>
> **Fix:** todo `<env>` em `phpunit.xml` tem agora um `<server>` espelhado com o mesmo nome/valor/`force="true"` — o `PhpHandler` do PHPUnit escreve `<server>` direto em `$_SERVER` incondicionalmente, cobrindo a lacuna. Validado forçando um dump de `getenv()`/`$_ENV`/`$_SERVER`/`Env::getRepository()->get()`/`config('database.default')` dentro de um teste até os 5 baterem em `sqlite`.
>
> **Lição para qualquer novo ambiente Docker + PHPUnit deste tipo:** `env_file` no `docker-compose.yml` é exatamente o tipo de configuração que faz esse problema aparecer — não é geral a todo projeto Laravel, é específico de rodar os testes dentro de um container cujas variáveis já vêm setadas no processo antes do PHP iniciar. Se decidir buildar contra Postgres real em vez de sqlite no futuro, usar um banco `_testing` dedicado (nunca o mesmo `DB_DATABASE` do dev) reduziria o dano de uma futura recorrência desse tipo de bug de configuração.

### 3.9. Suíte de testes automatizados — CRUD administrativo (`tests/Feature/{Clients,Users,Customers,Roles,PoliticasSla,Categorias,GruposSolucao,Incidentes,Dashboard,Relatorios,Portal,Seeders,Auditoria}`)

Mesmo padrão do §3.8: `RefreshDatabase`, dados via factories, permissions/roles montadas ad-hoc por
teste (nunca via `RolesAndPermissionsSeeder`).

| Arquivo | Cobre |
|---|---|
| `Clients/ClientCrudTest.php` | CRUD completo de `Client`, `409` ao excluir com `Customer`s vinculados, `?per_page=` |
| `Users/UserCrudTest.php` | CRUD completo de `User`, roles via `role_ids`, **senha opcional tanto na criação quanto no update** (criação sem senha gera hash aleatório inutilizável, pendente de convite — ver 3.4.3.1), `grupo_solucao_id` obrigatório/validado (criação e update), `409` só ao tentar excluir a própria conta, `DELETE` é soft delete (`assertSoftDeleted`, não some da tabela), desativar um usuário `responsavel_id` de `Incidente` ou que enviou `Anexo` **agora funciona** (sem mais `409`), desativado some da listagem, `UserResource.ativo`, `403`/`401` |
| `Customers/CustomerCrudTest.php` | CRUD completo de `Customer`, `client_id` obrigatório/validado, **senha opcional tanto na criação quanto no update** (mesmo comportamento pendente-de-convite do `User`), `409` ao excluir com `Incidente`s vinculados, `403`/`401` |
| `Roles/RoleIndexTest.php` | Listagem de roles (sem paginação), `403`/`401` |
| `PoliticasSla/PoliticaSlaCrudTest.php` | CRUD completo, `slas.view` vs `slas.manage` (leitura x escrita separadas), validação de `prioridade`/`gte` entre tempos, unicidade por `(client_id, prioridade)` (global e por cliente, sem colidir entre clientes diferentes), defaults de banco refletidos na resposta do `POST`, `Client::resolvedSlaFor()` (override do cliente vence, fallback pro global, ignora política inativa), `403`/`401`; filtros de listagem (`nome` parcial, `prioridade` exata + `422` se inválida, `ativo=true`/`ativo=false` — cobre a pegadinha de `array_key_exists()` —, `client_id` por id específico, `client_id=global`, `client_id` inválido → `422`) |
| `Categorias/CategoriaCrudTest.php` | CRUD completo de `Categoria`, `categorias.view` vs `categorias.manage`, `nome` único, `409` ao excluir com `Subcategoria`s vinculadas, `403`/`401` |
| `Categorias/SubcategoriaCrudTest.php` | CRUD completo de `Subcategoria`, `categoria_id` obrigatório/validado, `nome` único por `(categoria_id, nome)` (mesmo nome permitido em categorias diferentes), `categoria` carregada na resposta, `409` ao excluir com `Item`s vinculados, `403`/`401` |
| `Categorias/ItemCrudTest.php` | CRUD completo de `Item`, `subcategoria_id` obrigatório/validado, `nome` único por `(subcategoria_id, nome)` (mesmo nome permitido em subcategorias diferentes), `subcategoria` carregada na resposta, `409` ao excluir com `Incidente`s vinculados, `403`/`401` |
| `GruposSolucao/GrupoSolucaoCrudTest.php` | CRUD completo de `GrupoSolucao`, `grupos_solucao.view` vs `grupos_solucao.manage`, `nome` único, `409` ao excluir com `User`s ou `Incidente`s vinculados, `403`/`401` |
| `Incidentes/IncidenteCrudTest.php` | `tickets.view` vs `tickets.manage`, criação força `status="aberto"` (ignora valor enviado), `descricao` gera a 1ª entrada do feed (não fica na tabela `incidentes`), `item_id`/`grupo_solucao_id`/`responsavel_id` opcionais, validação de `customer_id`/`prioridade`/`origem`/`status`, `update()` parcial (só `status`, sem tocar nos outros campos), mudança de `grupo_solucao_id`/`responsavel_id` gera entrada(s) `escalonamento` automática(s) (nenhuma se não mudar), mudança de `titulo`/`prioridade`/`origem`/`status`/`customer_id`/`item_id` gera entrada(s) `alteracao` automática(s) — uma por campo, com nomes resolvidos pra `customer_id`/`item_id`, `'(nenhum)'` pra `item_id` nulo, nenhuma entrada se reenviar o mesmo valor, `403` ao tentar editar/excluir uma entrada `alteracao`, cálculo de `prazo_resposta`/`prazo_resolucao` na criação (com override do cliente e fallback pro global), `null` sem política aplicável, `respondido_em`/`concluido_em` setados na 1ª transição de status relevante (e nunca sobrescritos por uma conclusão subsequente), **reabertura limpa `concluido_em`** (mas não `respondido_em`) e faz `statusSlaResolucao()` voltar a comparar contra `now()` em vez de ficar congelado, campos de SLA expostos crus no `IncidenteResource`, **filtro por `status`/`prioridade`/`origem`/`customer_id`/`item_id`/`grupo_solucao_id`/`responsavel_id`** (isolado, combinado, `422` em valor inválido, **sem `status` explícito restringe a `aberto`+`em_andamento`+`pendente`** e um `status` explícito substitui essa restrição), `405` em `DELETE` (sem rota de exclusão), relações carregadas na resposta, `responsavel.name` continua resolvendo depois que o `User` responsável é desativado (`withTrashed()`), **transição pra `status="resolvido"` cria um `IncidenteEvento` `tipo=resolvido`** (autor = quem fez o `PUT`), **transição direta pra `status="fechado"` (pulando `resolvido`) cria só um evento `fechado`** (nenhum `resolvido`), **reabertura + resolução de novo cria um SEGUNDO evento `resolvido`** (não sobrescreve o primeiro), `cancelado` **não** cria nenhum evento de conclusão, reenvio do mesmo `status` **não** cria evento, mudança de `grupo_solucao_id`/`responsavel_id` cria um `IncidenteEvento` `encaminhado_grupo`/`encaminhado_responsavel` (`alvo_type`/`alvo_id` apontando pro grupo/usuário de destino) além da entrada `escalonamento` no feed, **criação de incidente registra `criado_por_id`** = autor do `POST`, `403`/`401` |
| `Incidentes/IncidenteDescricaoCrudTest.php` | CRUD do feed aninhado, `tipo`/`user_id` sempre forçados no `store` (ignora payload), só o próprio autor edita/exclui um `comentario` (`403` pra outro agente), `escalonamento` nunca editável/excluível (nem pelo autor), `404` se a descrição não pertencer ao incidente da URL, **exclusão é soft delete** (`assertSoftDeleted`, continua aparecendo no feed com `excluido_em` setado), `403` ao tentar editar ou excluir um comentário já excluído, `user.name` continua resolvendo depois que o autor é desativado (`withTrashed()`), `403`/`401` |
| `Incidentes/IncidenteAnexoCrudTest.php` | CRUD de anexos aninhado (`index`/`store`/`download`/`destroy`, sem `update`), upload real via `UploadedFile::fake()` + `Storage::fake('local')`, `nome_original`/`mime_type`/`tamanho` persistidos a partir do arquivo enviado, arquivo maior que 10 MB rejeitado (`422`), download preserva o `nome_original` no `Content-Disposition`, `destroy` remove a linha **e** o arquivo do disco, `404` se o anexo não pertencer ao incidente da URL, sem restrição de autor no `destroy` (diferente de `descricoes`), `user.name` continua resolvendo depois que quem enviou é desativado (`withTrashed()`), **extensão fora da whitelist rejeitada (`422`)**, **conteúdo que não bate com a extensão declarada rejeitado (`422`, `UploadedFile` real construído à mão — `fake()` não detecta mime por conteúdo, ver §3.4.7.2)**, **upload real de `.jpg`/`.csv` aceito**, **EXIF injetado num JPEG real (via GD) some do arquivo salvo após o upload**, **arquivo não-imagem passa intacto (byte a byte) pela etapa de remoção de EXIF**, `403`/`401` |
| `Relatorios/RelatorioIncidentesTest.php` | `agrupar_por` = `status_sla`/`responsavel`/`aberto_por`/`resolvido_por`/`fechado_por`/`encaminhado_por`/`encaminhado_para_grupo`/`encaminhado_para_responsavel`/`grupo_solucao`/`categoria`/`subcategoria`/`item`, `status_sla`/`responsavel`/`grupo_solucao` restringem a `STATUS_ATENDIDOS` (`resolvido`/`fechado`, `cancelado` excluído) sem `status` explícito (explícito inclui abertos), `categoria`/`subcategoria`/`item`/`aberto_por`/`resolvido_por`/`fechado_por`/`encaminhado_por`/`encaminhado_para_*` **não** têm essa restrição (`concluido_por` incluído), contagem correta por classificação (categoria→subcategoria→item), por agente (incluindo `(sem responsável)` e nome resolvido após desativação via `withTrashed()`) e por grupo de solução (incluindo `(sem grupo de solução)`), **`resolvido_por`/`fechado_por`/`concluido_por` contam chamados distintos por usuário** (agentes A e B no mesmo chamado = 1 e 1; o mesmo usuário resolvendo duas vezes com reabertura no meio = 1; `concluido_por` com `resolvido` + `fechado` do mesmo usuário no mesmo chamado = 1), **`concluido_por` soma `resolvido`+`fechado`**, **`fechado_por` conta só os eventos `tipo=fechado`**, **`encaminhado_por` soma eventos `encaminhado_grupo`+`encaminhado_responsavel` por autor** (sem distinguir destino), **`encaminhado_para_grupo`/`encaminhado_para_responsavel` agrupam pelo `alvo_id`** (destino) de cada tipo separadamente, **`aberto_por` agrupa `Incidente.criado_por_id` direto** (sem `IncidenteEvento`) e não restringe a `STATUS_ATENDIDOS` mesmo sem `status` explícito, **todas as dimensões baseadas em `IncidenteEvento` filtram pela data do evento (`IncidenteEvento.created_at`), não `Incidente.concluido_em`**, filtro por `data_inicio`/`data_fim` interpretados como dias de Brasília, inclusivos (sobre `concluido_em` nas outras dimensões; evento às 23:30 BRT do último dia entra, da véspera do início não), `grupo_solucao_id` nas dimensões por evento usa o snapshot do evento, `grupo_analista_id` (autor no momento do evento / grupo atual do responsável / criador, com usuários ativos do grupo zerados — inclusive `responsavel`), `client_id`, `grupo_solucao_id`, `formato=xlsx` devolve planilha de verdade (`Content-Type`/`Content-Disposition` corretos), `422` sem `agrupar_por` ou com valor inválido, `403`/`401` |
| `Relatorios/RelatorioSalvoCrudTest.php` | CRUD completo (`relatorios.view` pra ler/executar, `relatorios.manage` pra criar/editar/excluir), `filtros` vazio (`{}`) é aceito (regressão — `'required'` rejeitava array vazio, corrigido pra `'present'`; achado também que `$request->validate()` some com a chave pai quando reconstrói a partir de sub-regras `filtros.*` vazias, contornado lendo o input bruto), `422` em `nome`/`agrupar_por`/`filtros` ausentes ou `agrupar_por` inválido, `executar` roda os filtros salvos contra dados atuais (json e xlsx), `403`/`401` |
| `Incidentes/IncidenteSlaTest.php` | Métodos `statusSlaResposta()`/`statusSlaResolucao()`/`tempoRestanteRespostaMinutos()`/`tempoRestanteResolucaoMinutos()` do `Incidente` isolados (sem HTTP): `sem_sla` sem prazo, `dentro_prazo`/`estourado` comparando com "agora" enquanto aberto, uso de `respondido_em`/`concluido_em` como referência congelada em vez de "agora" quando já concluído |
| `Dashboard/IncidentesDashboardTest.php` | Formato achatado completo (cliente/email/taxonomia/SLA), `status_sla_*`/`tempo_restante_*` calculados sem query extra (derivados de `prazo_*` já persistido, não chama mais `resolvedSlaFor()`), `estourado` quando aberto e passou do prazo, congela via `concluido_em` mesmo com o prazo já no passado em relação a "agora", `sem_sla` sem política aplicável, classificação `null` sem `item_id`, mesmo filtro do CRUD funcionando (isolado, combinado, `422` em valor inválido, mesma restrição padrão de `status`), `todos_status=1` traz todos os status (incluindo `resolvido`/`fechado`/`cancelado`), `status` explícito tem prioridade sobre `todos_status` quando os dois são enviados, `403`/`401` |
| `Incidentes/IncidenteTipoTest.php` | `tipo` na criação (ausente → `incidente`, `solicitacao` aceito, valor inválido → `422`), troca de `tipo` com `tickets.edit_all` gera entrada `alteracao` no feed com os rótulos "Incidente"/"Solicitação", filtro `?tipo=` em `GET /incidentes` |
| `Incidentes/IncidenteCamposRestritosTest.php` | (atualizado) `tipo` entra nos campos restritos após a abertura — `422` sem `tickets.edit_all`, reenvio do mesmo valor aceito |
| `Incidentes/IncidenteDescricaoAutorTest.php` | Guarda "exatamente um autor" de `IncidenteDescricao` (`LogicException` sem autor ou com `user_id`+`customer_id`; só `customer_id` salva) |
| `Portal/PortalIncidenteTest.php` | Cliente abre incidente em nome próprio com tudo fixado no servidor; campos forjados no payload (`customer_id`, `item_id`, `prioridade`, `status`, `tipo`, `origem`, grupo/responsável) ignorados; descrição de abertura com autor cliente; resposta só com `id`/`titulo`/`status`/datas; validação; "meus chamados" só traz os do cliente (nem de colega da mesma empresa); token de staff `401` nas rotas do portal e token de cliente `401` nas do staff |
| `Portal/PortalTriagemTest.php` | Primeira classificação pelo staff 3h depois da abertura → prioridade do item e prazos contados de `created_at` (SLA de resposta já `estourado`); chamado não triado visível a todo staff com `prioridade: null`/`sem_sla`; `?sem_classificacao=1` (inclusive com `sort_by=prioridade`) |
| `Portal/PortalReaberturaTest.php` | Cliente reabre chamado `fechado`: volta para `aberto` com o mesmo grupo/responsável, `concluido_em` limpo, evento `reaberto` com `autor_customer_id`, motivo como comentário, linha de alteração no feed (oculta no portal) e log de ações com autor cliente; `pode_reabrir`/`reabrir_ate` só no detalhe; `422` para os outros status, depois de 7 dias do fechamento mais recente (reabrir e fechar de novo renova o prazo), chamado legado sem evento (usa `concluido_em`) e motivo ausente/em branco; `404` em chamado de outro cliente; `401` com token de staff |
| `Seeders/ChamadosDemoSeederTest.php` | `db:seed` padrão **não** cria a massa de demo; `chamados:popular` cria 100 chamados com os dois tipos, solicitações sempre do staff, classificados com prioridade do item, chamados do portal sem triagem com autor cliente, todos com descrição de abertura; todo chamado `cancelado` (do seed padrão e da massa de demo) tem evento `cancelado`; `--quantidade`; recusa em produção sem confirmação |
| `Relatorios/*` e `Dashboard/*` | (atualizados) filtro `tipo` em relatório, em agrupamento por evento e em relatório salvo; grupo "Portal (cliente)" no `aberto_por`; `tipo` exposto no dashboard |
| `Customers/CustomerCrudTest.php` | (atualizado) `409` (não `500`) ao excluir um customer que abriu pelo portal um chamado depois trocado de cliente |
| `Auditoria/AuditLogCapturaTest.php` | Nada é gravado sem usuário autenticado (factories); CRUD via API grava `criado`/`atualizado`/`excluido` com autor, diff e rótulo snapshot; update sem mudança não grava; troca de senha vira `senha: (alterada)` sem o hash; `sync()` de papéis e permissões do grupo grava diff de relação; chamado grava só nomes de campos (coluna de sistema ignorada); feed grava só `comentario`, sem texto; `login`/`login_falhou` (com e sem conta existente); ação do portal com autor `customer`; `model:prune` apaga só o que passou de 6 meses |
| `Auditoria/AuditLogConsultaTest.php` | `403` sem `auditoria.view`; `422` sem período e acima de 93 dias; filtros combinados (período em dias de Brasília, autor, ação, tipo de registro, busca); `/audit-logs/opcoes` |

### 3.10. Histórico de entregas — Tipo do chamado e portal do cliente (2026-11-09)

Origem: pedido "na tela de Novo Incidente, já trazer o nome do usuário logado e a empresa dele,
permitindo alterar, pois ele pode estar abrindo para outra pessoa". A análise mostrou que só o staff
(`User`, sem empresa) abria chamados; as regras de negócio definidas com o produto foram:

1. Todo chamado tem um **tipo**: Incidente (via de regra) ou **Solicitação** (demanda pontual de
   cliente interno — ajuste de infra, um script, contatar um cliente). É só categorização.
2. **Staff abre os dois tipos; o cliente, pelo portal, só abre Incidente.**
3. **Por auditoria, o cliente só abre em nome próprio** — nome e empresa vêm preenchidos e travados.
   Abrir para outra pessoa é papel do staff.
4. A própria empresa é cadastrada como um `Client`; não há marcação de "empresa interna".
5. O cliente não classifica: **o staff classifica na triagem**, e o **SLA conta desde a abertura**.

Spec e planos: `docs/superpowers/specs/2026-11-09-tipo-chamado-e-abertura-portal-design.md`,
`docs/superpowers/plans/2026-11-09-tipo-chamado-entrega-1.md`,
`docs/superpowers/plans/2026-11-09-portal-abertura-incidente-entrega-2.md` (cópia da spec no repo do
frontend).

**Entrega 1 — tipo do chamado + formulário do staff**
- Backend: coluna `incidentes.tipo` (§3.1), `Incidente::TIPOS`/`TIPO_ROTULOS`, campo restrito após a
  abertura com auditoria no feed, filtro `tipo` em `GET /incidentes`, dashboard (§3.4.8), relatórios
  (inclusive agrupamentos por evento) e relatórios salvos (§3.4.9).
- Frontend (`gespriority_claude_front`): campo **Tipo** no formulário; **Empresa → Solicitante** em
  selects separados (empresa derivada do solicitante; o payload segue só com `customer_id`); títulos
  "Novo Chamado"/"Chamado #id"; coluna e filtro Tipo no dashboard e filtro Tipo nos relatórios.

**Entrega 2 — portal do cliente**
- Backend: `incidentes.prioridade` nullable, `incidentes.criado_por_customer_id`,
  `incidente_descricoes.customer_id` com regra "exatamente um autor" (§3.1); `POST`/`GET
  /customer/incidentes` (§3.4.10); `client` no `/me` do cliente; filtro `sem_classificacao` no
  dashboard; grupo "Portal (cliente)" no `aberto_por`; `CustomerController::destroy()` considera
  também a autoria pelo portal (`409` em vez de FK violada).
- Frontend: "Meus chamados" (`PortalView`) e "Abrir incidente" (`PortalIncidentNewView`, Solicitante
  e Empresa somente leitura, `fetchMe()` ao montar); no lado do staff, "Não classificado" no
  dashboard, checkbox "Sem classificação (triagem)" no filtro avançado (enviado como `1` — o Axios
  serializa `true` como `"true"`, que a regra `boolean` recusa) e autor "Fulano (cliente)" no feed.

**Massa de demonstração**: `php artisan chamados:popular` (§3.7).

**Deploy**: as migrations `2026_11_09_120000_add_tipo_to_incidentes_table` e
`2026_11_10_120000_add_customer_authorship_and_nullable_prioridade` rodam no `migrate --force` do
deploy (ambas seguras em tabela populada no Postgres 16: `ADD COLUMN ... DEFAULT` sem reescrita,
`->change()` sem perda de modificadores, `CHECK` satisfeito pelas linhas existentes). Subir o
**backend antes do frontend** — o frontend novo chama `/customer/incidentes` e envia `tipo`.

**Pendências conhecidas (não bloqueantes)**: sem limite de tamanho para `descricao` no portal (o
throttle de 10/min limita); relatórios por categoria/subcategoria não contam chamados ainda sem
classificação (comportamento pré-existente para `item_id` nulo); se o staff trocar o cliente de um
chamado do portal, ele some do "meus chamados" de quem abriu; o formulário do staff exige escolher a
classificação para salvar um chamado do portal (não dá para só encaminhar antes de classificar);
o tipo vindo da URL de clonagem não é validado no frontend; nenhum teste cobre a execução
(`/executar`) de relatório salvo com `filtros.tipo`.

### 3.11. Histórico de entregas — Log de ações na Administração (2026-11-12)

Pedido: seção de logs de ação de usuários em "Administração", que por padrão não carrega nada —
só consulta com filtros/busca. Decisões com o produto: registrar **todas as ações** (staff e
clientes do portal); no chamado, o **modo mais econômico** (sem duplicar o feed); registrar
download de anexo e exportação, mas não leituras comuns; **apagar após 6 meses**; **importar o
histórico de `token_audit_logs`**.

- Backend: tabela `audit_logs` (§3.1) substituindo `token_audit_logs`, trait `Auditavel`, serviço
  `App\Support\Auditoria`, endpoints `/audit-logs` e `/audit-logs/opcoes` (§3.4.11), permission
  `auditoria.view`, `model:prune` agendado e container `scheduler` no `docker-compose.vps.yml`
  (o `deploy-ec2.yml` passa a subir `app queue scheduler`).
- Frontend (`gespriority_claude_front`): item **Auditoria > Logs de ações** no menu da
  Administração (`AuditLogsView`), filtros de período, autor (usuários da aplicação e de clientes),
  ações, tipo/ID do registro, busca textual e IP; resultado com linha expansível (de → para) e
  "Carregar mais".

**Deploy**: subir o backend antes do frontend. As migrations rodam no `migrate --force`; a tabela
antiga é copiada e removida na mesma migration (Postgres faz DDL transacional).

**Pendências conhecidas (não bloqueantes)**: chaves estrangeiras aparecem como ids no diff (ex.:
`grupo_solucao_id: 2 → 5`), sem resolução de nome; a busca textual não usa índice (o período
obrigatório limita a varredura); a listagem de autores usa `/lookups/*`, que não traz usuários
desativados — a busca textual pelo nome ainda os encontra.
