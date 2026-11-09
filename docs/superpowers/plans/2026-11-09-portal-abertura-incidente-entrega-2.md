# Portal do cliente: abrir incidente + "Meus chamados" (Entrega 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O cliente logado no portal abre um incidente em nome próprio (sem classificação) e lista os próprios chamados; o staff classifica na triagem e o SLA conta desde a abertura.

**Architecture:** Endpoints novos sob `/api/customer/incidentes` (`auth:customer`) num controller separado do staff, com tudo que não é título/descrição fixado no servidor. Autoria do cliente em colunas explícitas (`incidentes.criado_por_customer_id`, `incidente_descricoes.customer_id`). Chamado do portal nasce sem `item_id`/`prioridade`/prazos; a primeira classificação pelo staff passa pelo `update()` existente, que já recalcula prioridade e prazos a partir de `created_at`.

**Tech Stack:** Laravel 12 / PHPUnit / Sanctum (backend `gespriority_claude`); Vue 3 + Vuetify 3 + Pinia (frontend `gespriority_claude_front`, sem suíte de testes).

**Spec:** `docs/superpowers/specs/2026-11-09-tipo-chamado-e-abertura-portal-design.md` (seção "Entrega 2"). Cópia idêntica no repo do frontend.

## Global Constraints

- O cliente só abre incidente **em nome próprio**: `customer_id` vem sempre do token, nunca do payload.
- Payload aceito no portal: só `titulo` (required, string, max 255) e `descricao` (required, string). Todo o resto é ignorado.
- Fixado no servidor: `tipo = 'incidente'`, `origem = 'portal'`, `status = 'aberto'`, `item_id`/`grupo_solucao_id`/`responsavel_id`/`prioridade`/`prazo_*` = `null`, `criado_por_id = null`, `criado_por_customer_id = <cliente>`.
- O cliente não classifica. Staff classifica na triagem; **SLA conta desde a abertura** (`created_at`).
- Respostas do portal nunca expõem grupo, responsável, prioridade, SLA ou classificação.
- O `store()` do staff continua exigindo `item_id`; nada muda no fluxo de abertura pelo staff.
- Migrations rodam em Postgres 16 (produção, via `migrate --force` no deploy) e SQLite em memória (testes).
- Testes do backend: `docker compose exec -T app php artisan test --filter=<Classe>`. Mudou rota? `docker compose exec -T app php artisan octane:reload` antes de testar à mão.
- Build do frontend: `PATH=~/.nvm/versions/node/v22.14.0/bin:$PATH npm run build` (Vite exige Node ≥ 20).
- Um commit por task no repo correspondente, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Cliente manda `customer_id`, `item_id`, `prioridade`, `status`, `grupo_solucao_id`, `tipo: solicitacao` no POST → tudo ignorado; chamado nasce em nome dele, sem classificação. (Task 2)
2. Cliente A nunca vê chamados do cliente B na listagem, inclusive da mesma empresa. (Task 2)
3. Staff classifica um chamado do portal 3h depois da abertura → prazos contam de `created_at`, não da classificação. (Task 3)
4. Chamado sem prioridade no dashboard, na ordenação por prioridade e nos relatórios → não quebra; aparece como "Não classificado". (Tasks 3 e 4)
5. Cliente com sessão antiga (salva antes do deploy, sem `client` no usuário) abre a tela de novo incidente → Empresa aparece preenchida (o front chama `fetchMe()` ao montar). (Task 5)

---

## Backend (`/home/daniel/Workspace/gespriority_claude`)

### Task 1: Schema de autoria do cliente e prioridade opcional

**Files:**
- Create: `database/migrations/2026_11_10_120000_add_customer_authorship_and_nullable_prioridade.php`
- Modify: `app/Models/Incidente.php` (relação `criadoPorCustomer()`)
- Modify: `app/Models/IncidenteDescricao.php` (`customer_id` no `#[Fillable]`, relação `customer()`, guarda de autor)
- Modify: `app/Http/Resources/IncidenteDescricaoResource.php`, `app/Http/Controllers/Api/IncidenteDescricaoController.php` (carregar `customer` junto de `user`)
- Modify: `BACKEND_SPECS.md` (colunas novas, `prioridade` nullable, regra "exatamente um autor")
- Test: `tests/Feature/Incidentes/IncidenteDescricaoCrudTest.php`, `tests/Unit/IncidenteDescricaoAutorTest.php` (novo)

**Interfaces:**
- Produces: colunas `incidentes.criado_por_customer_id` (FK `customers`, nullable, `restrictOnDelete`), `incidente_descricoes.customer_id` (FK `customers`, nullable, `restrictOnDelete`); `incidentes.prioridade` e `incidente_descricoes.user_id` nullable. `Incidente::criadoPorCustomer(): BelongsTo`; `IncidenteDescricao::customer(): BelongsTo`. Resource do feed devolve `customer: {id, name} | null` ao lado de `user`.

- [ ] **Step 1: Write the failing tests:**
  - `IncidenteDescricaoAutorTest::test_requires_exactly_one_author`: criar `IncidenteDescricao` sem `user_id` nem `customer_id` → `LogicException`; com os dois → `LogicException`; só com `customer_id` → salva.
  - `IncidenteDescricaoCrudTest::test_feed_shows_customer_authored_entry`: entrada `comentario` com `customer_id` (sem `user_id`) → `GET /api/incidentes/{id}/descricoes` devolve `user: null` e `customer.name` igual ao nome do cliente.
  - `IncidenteDescricaoCrudTest::test_nobody_can_edit_a_customer_authored_entry`: staff com `tickets.manage` faz PUT nessa entrada → 403.
- [ ] **Step 2: Run** `docker compose exec -T app php artisan test --filter='IncidenteDescricaoAutorTest|IncidenteDescricaoCrudTest'` — Expected: FAIL (coluna `customer_id` inexistente).
- [ ] **Step 3: Implement:**
  - Migration `up()`: `prioridade` → `nullable()->change()`; `criado_por_customer_id` em `incidentes` depois de `criado_por_id`; em `incidente_descricoes`, `user_id` → `nullable()->change()` e `customer_id` novo. Só em `pgsql` (`DB::getDriverName() === 'pgsql'`), `ALTER TABLE incidente_descricoes ADD CONSTRAINT incidente_descricoes_um_autor CHECK ((user_id IS NULL) <> (customer_id IS NULL))`. `down()` desfaz na ordem inversa (constraint só no pgsql).
  - Guarda de autor no `booted()` de `IncidenteDescricao` (evento `saving`): `LogicException` se `user_id` e `customer_id` forem ambos nulos ou ambos preenchidos. É a garantia que vale no SQLite dos testes.
  - Resource: `'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => …, 'name' => …] : null)`; com o `user` agora nullable, o `whenLoaded('user', …)` também precisa devolver `null` quando não houver user.
  - Controller do feed: `with(['user', 'customer'])` / `load(['user', 'customer'])` em todos os pontos que hoje carregam só `user`. `ensureCanModify` não muda: `user_id` nulo nunca é igual ao do staff, então entradas do cliente ficam não editáveis.
- [ ] **Step 4: Run** o mesmo comando — Expected: PASS. Depois `docker compose exec -T app php artisan test` — Expected: PASS (suíte inteira).
- [ ] **Step 5: Commit** `feat: customer authorship columns and nullable prioridade for portal tickets`.

### Task 2: Endpoints do portal (abrir + "meus chamados") e empresa no `/me`

**Files:**
- Create: `app/Http/Controllers/Api/Portal/IncidenteController.php`
- Create: `app/Http/Resources/PortalIncidenteResource.php`
- Modify: `routes/api.php` (grupo `customer` autenticado), `app/Providers/AppServiceProvider.php` (rate limiter `portal-abertura`)
- Modify: `app/Http/Controllers/Api/AuthController.php` (`presentAuthenticatable()`)
- Modify: `BACKEND_SPECS.md` (rotas do portal, regras fixadas no servidor, `/me` do cliente)
- Test: `tests/Feature/Portal/PortalIncidenteTest.php` (novo), `tests/Feature/Auth/LoginTest.php`

**Interfaces:**
- Consumes: colunas e relações da Task 1.
- Produces: `POST /api/customer/incidentes` → `201 {data: {id, titulo, status, created_at, updated_at}}`; `GET /api/customer/incidentes?page=` → paginado, mesmo formato por item, mais recente primeiro, `per_page` 15. `/me` e o `user` do login, para Customer, passam a incluir `client: {id, name}`.

- [ ] **Step 1: Write the failing tests** em `PortalIncidenteTest` (helper `customerToken(Customer $c)` → `$c->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken`):
  - `test_customer_opens_incident_in_own_name`: POST `{titulo, descricao}` → 201; no banco: `customer_id` = o cliente, `tipo` `incidente`, `origem` `portal`, `status` `aberto`, `item_id`/`prioridade`/`prazo_resposta`/`grupo_solucao_id`/`responsavel_id`/`criado_por_id` nulos, `criado_por_customer_id` = o cliente.
  - `test_forged_fields_in_payload_are_ignored`: POST com `customer_id` de outro cliente, `item_id`, `prioridade: 'urgente'`, `status: 'resolvido'`, `tipo: 'solicitacao'`, `origem: 'email'`, `grupo_solucao_id` → 201 e todos os valores fixados acima prevalecem.
  - `test_opening_description_is_first_feed_entry_authored_by_customer`: `incidente_descricoes` tem uma entrada `comentario` com `customer_id` = cliente, `user_id` nulo, texto = `descricao`.
  - `test_response_does_not_expose_internal_fields`: JSON de `data` tem exatamente as chaves `id`, `titulo`, `status`, `created_at`, `updated_at`.
  - `test_validation`: sem `titulo` → 422 `titulo`; `titulo` com 256 caracteres → 422; sem `descricao` → 422.
  - `test_lists_only_own_tickets`: cliente A tem 2 chamados (um aberto pelo staff em nome dele), cliente B da **mesma empresa** tem 1 → GET de A devolve só os 2 de A.
  - `test_staff_token_cannot_use_portal_routes`: token de staff em POST/GET `/api/customer/incidentes` → 401.
  - `test_customer_token_still_cannot_use_staff_incident_routes`: token de cliente em `GET /api/incidentes` → 401.
  - `LoginTest::test_customer_me_includes_client`: `GET /api/me` com token de cliente → `client.id` e `client.name` do `Client` dele.
- [ ] **Step 2: Run** `docker compose exec -T app php artisan test --filter='PortalIncidenteTest|LoginTest'` — Expected: FAIL (rota 404 / `client` ausente).
- [ ] **Step 3: Implement:**
  - Rotas: `Route::prefix('customer')->middleware('auth:customer')->group(...)` com `GET /incidentes` → `Portal\IncidenteController@index` e `POST /incidentes` → `@store` + `throttle:portal-abertura`. Comentário no padrão do arquivo explicando que é separado do controller do staff de propósito.
  - Rate limiter `portal-abertura`: `Limit::perMinute(10)->by($request->user()?->id.'|'.$request->ip())`.
  - `store()`: `validate(['titulo' => required|string|max:255, 'descricao' => required|string])`; numa transação, criar o `Incidente` com os valores fixados (as colunas fora do `#[Fillable]` atribuídas direto, como o staff faz com `criado_por_id`), depois a descrição `comentario` com `customer_id`. Não chamar cálculo de SLA (sem prioridade não há política).
  - `index()`: `Incidente::query()->where('customer_id', $request->user()->id)->latest()->paginate(15)`. Nenhum parâmetro de filtro aceito.
  - `PortalIncidenteResource`: as 5 chaves acima.
  - `presentAuthenticatable()`: para `Customer`, `$model->loadMissing('client:id,name')` antes de devolver.
- [ ] **Step 4:** `docker compose exec -T app php artisan octane:reload`, depois o mesmo comando de teste — Expected: PASS. Depois a suíte inteira — Expected: PASS.
- [ ] **Step 5: Commit** `feat: portal endpoints to open and list the customer's own incidents`.

### Task 3: Triagem: classificar chamado do portal, filtro "sem classificação" e relatório "aberto por"

**Files:**
- Modify: `app/Models/Incidente.php` (`scopeFiltros`: `sem_classificacao`)
- Modify: `app/Http/Controllers/Api/DashboardController.php` (validação `sem_classificacao`)
- Modify: `app/Http/Controllers/Api/RelatorioController.php` (agrupamento `aberto_por`)
- Modify: `BACKEND_SPECS.md` (filtro novo, bucket "Portal (cliente)", relógio do SLA)
- Test: `tests/Feature/Portal/PortalTriagemTest.php` (novo), `tests/Feature/Relatorios/RelatorioIncidentesTest.php`

**Interfaces:**
- Consumes: chamado do portal (Task 2); `IncidenteController::update()` existente (recalcula prioridade e prazos quando `item_id` muda, usando `created_at` como base).
- Produces: `GET /dashboard/incidentes?sem_classificacao=1`; linha `{chave: 'portal', rotulo: 'Portal (cliente)', total}` no relatório `aberto_por`.

- [ ] **Step 1: Write the failing tests** em `PortalTriagemTest` (chamado criado via `POST /api/customer/incidentes`, para exercitar o caminho real):
  - `test_first_classification_sets_priority_and_sla_from_opening`: abrir em `T0`; `travelTo(T0 + 3h)`; staff com `tickets.manage` faz PUT `item_id` (item `prioridade_padrao: 'alta'`, empresa do cliente com política `alta` de resposta 60 min e resolução 240 min) → `prioridade` `alta`, `prazo_resposta == T0 + 60min`, `prazo_resolucao == T0 + 240min`; status SLA de resposta `estourado`.
  - `test_unclassified_portal_ticket_is_visible_to_all_staff`: staff de um grupo qualquer, com `tickets.view`, vê o chamado em `GET /api/dashboard/incidentes` com `prioridade: null` e `status_sla_resposta: 'sem_sla'`.
  - `test_dashboard_filters_unclassified`: um chamado do portal e um do staff (com item) → `?sem_classificacao=1` devolve só o do portal; `?sem_classificacao=1&sort_by=prioridade` responde 200.
  - `RelatorioIncidentesTest::test_aberto_por_has_a_portal_bucket`: um chamado do staff (criador "Agente A"), um do portal e um legado (`criado_por_id` e `criado_por_customer_id` nulos) → linhas `Agente A: 1`, `Portal (cliente): 1`, `(desconhecido): 1`.
- [ ] **Step 2: Run** `docker compose exec -T app php artisan test --filter='PortalTriagemTest|RelatorioIncidentesTest'` — Expected: o teste de classificação já pode passar (o `update()` existente cobre isso; se passar, é a confirmação de que nenhum código novo é preciso para o relógio). Os testes de filtro e de relatório falham.
- [ ] **Step 3: Implement:** `sem_classificacao` (`sometimes|boolean`) no `DashboardController` e `->when(! empty($filtros['sem_classificacao']), fn ($q) => $q->whereNull('item_id'))` em `scopeFiltros`. No `aberto_por`: depois do `comRotulos`, se houver chamados com `criado_por_id` nulo e `criado_por_customer_id` preenchido (mesma query filtrada), tirar essa contagem da linha `(desconhecido)` (remover a linha se zerar) e acrescentar `['chave' => 'portal', 'rotulo' => 'Portal (cliente)', 'total' => n]`.
- [ ] **Step 4: Run** o mesmo comando — Expected: PASS. Suíte inteira — Expected: PASS.
- [ ] **Step 5: Commit** `feat: triage of portal tickets (unclassified filter, portal bucket in aberto_por)`.

---

## Frontend (`/home/daniel/Workspace/gespriority_claude_front`)

Sem suíte de testes. Cada task verifica com o build (ver Global Constraints) e com um smoke test na API local; telas que não puderem ser abertas num navegador ficam listadas para o usuário conferir.

### Task 4: Lado do staff: "Não classificado", filtro de triagem e autor cliente no feed

**Files:**
- Modify: `src/views/DashboardView.vue` (chip de prioridade nula)
- Modify: `src/components/IncidentAdvancedFilterModal.vue` (checkbox "Sem classificação" → `sem_classificacao: true`, mesmo ciclo dos outros filtros)
- Modify: `src/components/IncidentFeed.vue` (autor cliente)

**Interfaces:**
- Consumes: `prioridade: null` no dashboard; `?sem_classificacao=1`; `customer: {id, name}` nas entradas do feed (Task 1).

- [ ] **Step 1: Implement:**
  - Dashboard: prioridade nula → chip `variant="outlined"` com texto "Não classificado".
  - Filtro avançado: `v-checkbox` "Sem classificação"; o valor só vai em `draftFilters()` quando marcado (`true`), senão `null`, para o `compactFilters` existente descartar.
  - Feed: nome do autor = `entry.user?.name ?? \`${entry.customer?.name} (cliente)\``, inclusive nas iniciais do avatar e na linha compacta; `isOwnComment` continua comparando só `entry.user?.id`.
- [ ] **Step 2: Run** o build — Expected: sem erros.
- [ ] **Step 3: Smoke:** com o backend local (Tasks 1–3 aplicadas), abrir um chamado via `POST /api/customer/incidentes` (login `cliente@example.com` / `password`) e confirmar via API que `GET /api/dashboard/incidentes?sem_classificacao=1` o devolve e que o feed traz `customer.name`.
- [ ] **Step 4: Commit** `feat: show unclassified tickets and customer-authored feed entries to staff`.

### Task 5: Portal: "Meus chamados" e "Abrir incidente"

**Files:**
- Create: `src/services/portalIncidentService.js`
- Create: `src/views/PortalIncidentNewView.vue`
- Modify: `src/views/PortalView.vue` (vira "Meus chamados")
- Modify: `src/router/index.js` (rota `portal-incident-new`, path `/portal/incidentes/novo`, `meta: { requiresAuth: true, guard: 'customer' }`)

**Interfaces:**
- Consumes: `GET/POST /customer/incidentes` (Task 2); `auth.user.client.name` (Task 2); `auth.fetchMe()` (existente); `STATUS_LABELS`/`STATUS_COLORS`.
- Produces: `portalIncidentService.list(params) → {data, meta}` e `portalIncidentService.create({titulo, descricao}) → data`.

- [ ] **Step 1: Implement:**
  - `PortalView`: mantém a saudação; troca o card "ainda não implementado" por uma `v-data-table-server` "Meus chamados" (Número, Título, Status como chip, Aberto em, Atualizado em; paginação do backend) e o botão "Abrir incidente". Se o history state trouxer `criado: <id>`, mostrar alerta de sucesso "Incidente #<id> aberto." e limpar o state (mesmo padrão de `showAttachmentFailuresFromCreation()` no form do staff).
  - `PortalIncidentNewView`: chama `auth.fetchMe()` ao montar (sessões antigas não têm `client`); campos somente leitura **Solicitante** (`nome (email)`) e **Empresa** (`auth.user.client.name`); campos **Título** (máx. 255) e **Descrição** (textarea); Salvar → `create` → `router.push({ name: 'portal', state: { criado: id } })`; erros pela `extractErrorMessage` existente; botão Voltar.
- [ ] **Step 2: Run** o build — Expected: sem erros.
- [ ] **Step 3: Smoke:** pela API local, logado como `cliente@example.com`, criar e listar; conferir que `/me` traz `client`. As telas ficam para o usuário conferir no navegador.
- [ ] **Step 4: Commit** `feat: customer portal: my tickets list and open incident form`.
