# Tipo do chamado + formulário do staff (Entrega 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Todo chamado ganha `tipo` (`incidente` | `solicitacao`), filtrável e auditado, e o formulário do staff passa a ter Tipo e Empresa → Solicitante.

**Architecture:** Coluna `tipo` em `incidentes` (sem nova entidade). Backend segue os padrões existentes: validação inline no controller, `scopeFiltros`/`scopeFiltrosRelatorio` no model, campos restritos via `CAMPOS_RESTRITOS_APOS_ABERTURA`, auditoria via `logAlteracaoSeMudou()`. Frontend reaproveita o padrão Empresa→Usuário já existente em `ReportsView.vue` (`filteredCustomerOptions` + `onClientChange`).

**Tech Stack:** Laravel 12 / PHPUnit (backend `gespriority_claude`); Vue 3 + Vuetify 3 + Pinia (frontend `gespriority_claude_front`, sem suíte de testes — verificação por build + navegador).

**Spec:** `docs/superpowers/specs/2026-11-09-tipo-chamado-e-abertura-portal-design.md` (seção "Entrega 1"). Existe cópia idêntica no repo do frontend.

## Global Constraints

- Valores de `tipo`: exatamente `incidente` e `solicitacao`. Rótulos de UI: "Incidente" e "Solicitação".
- Coluna `tipo`: `string`, `NOT NULL`, `default 'incidente'`, indexada. Existentes viram `incidente` pelo default.
- `store()`: `tipo` é `sometimes` — ausente vira `incidente`. Não exigir o campo na API.
- `tipo` é campo restrito após a abertura (rótulo "Tipo"): só `tickets.edit_all` altera.
- Não existe `client_id` no incidente: o payload continua mandando só `customer_id`; a empresa é sempre derivada do solicitante.
- Não renomear `Incidente` em código/rotas/tabelas. Só rótulos de UI: "Novo Chamado" / "Chamado #id".
- Rodar testes do backend com `docker compose exec app php artisan test --filter=<Classe>` (o app roda no container `itsm-app`; testes usam SQLite em memória).
- Commits: um por task, no repo correspondente, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Cliente antigo da API faz `POST /incidentes` sem `tipo` → deve criar com `tipo = incidente`, não 422. (Task 1)
2. Analista reenvia o formulário inteiro com `tipo` inalterado → não pode dar 422 de campo restrito. (Task 1)
3. Relatório agrupado por `resolvido_por`/`fechado_por`/`encaminhado_*` com filtro `tipo` → deve respeitar o filtro (passa por `IncidenteEvento`, não por `Incidente`). (Task 3)
4. Relatório salvo com `filtros.tipo` inválido → 422; válido → é gravado e aplicado ao executar. (Task 3)
5. Editar um chamado existente → o campo Empresa aparece preenchido com a empresa do solicitante (derivada após as listas carregarem), e trocar a empresa limpa o solicitante. (Task 5, verificação manual)

---

## Backend (`/home/daniel/Workspace/gespriority_claude`)

### Task 1: Coluna `tipo`, criação, edição restrita e auditoria

**Files:**
- Create: `database/migrations/2026_11_09_120000_add_tipo_to_incidentes_table.php`
- Modify: `app/Models/Incidente.php` (consts, `#[Fillable]`, `CAMPOS_RESTRITOS_APOS_ABERTURA`)
- Modify: `app/Http/Controllers/Api/IncidenteController.php` (`store()`, `update()`)
- Modify: `app/Http/Resources/IncidenteResource.php`
- Modify: `database/factories/IncidenteFactory.php` (default `'tipo' => 'incidente'`)
- Modify: `BACKEND_SPECS.md` (tabela `incidentes`: linha `tipo`; nota de campos restritos passa a listar Tipo)
- Test: `tests/Feature/Incidentes/IncidenteTipoTest.php` (novo), `tests/Feature/Incidentes/IncidenteCamposRestritosTest.php`

**Interfaces:**
- Produces: `Incidente::TIPOS = ['incidente', 'solicitacao']`; `Incidente::TIPO_ROTULOS = ['incidente' => 'Incidente', 'solicitacao' => 'Solicitação']`; `CAMPOS_RESTRITOS_APOS_ABERTURA['tipo'] = 'Tipo'`; campo `tipo` em `IncidenteResource`.

- [ ] **Step 1: Write the failing tests** em `IncidenteTipoTest` (copiar os helpers `staffToken()`/`authHeader()` de `IncidenteCamposRestritosTest`; payload de criação igual ao de `IncidenteCrudTest`):
  - `test_store_without_tipo_defaults_to_incidente`: POST sem `tipo` → 201, `json('data.tipo') === 'incidente'`, `assertDatabaseHas('incidentes', ['tipo' => 'incidente'])`.
  - `test_store_accepts_solicitacao`: POST com `'tipo' => 'solicitacao'` → 201, `data.tipo === 'solicitacao'`.
  - `test_store_rejects_unknown_tipo`: `'tipo' => 'problema'` → 422 com erro em `tipo`.
  - `test_changing_tipo_with_edit_all_logs_feed_entry`: usuário com `tickets.manage` + `tickets.edit_all` faz PUT `'tipo' => 'solicitacao'` num incidente `incidente` → 200; `assertDatabaseHas('incidente_descricoes', ['incidente_id' => $id, 'tipo' => IncidenteDescricao::TIPO_ALTERACAO])` e a `descricao` contém `"Campo 'Tipo' alterado de 'Incidente' para 'Solicitação'"`.
- [ ] **Step 2:** Em `IncidenteCamposRestritosTest::camposRestritos()` adicionar `'tipo' => ['tipo', 'solicitacao']`; em `test_analista_can_resend_restricted_fields_unchanged` incluir `'tipo' => $incidente->tipo` no payload reenviado.
- [ ] **Step 3: Run** `docker compose exec app php artisan test --filter='IncidenteTipoTest|IncidenteCamposRestritosTest'` — Expected: FAIL (coluna `tipo` não existe).
- [ ] **Step 4: Implement.**
  - Migration: `$table->string('tipo')->default('incidente')->index()->after('status')`; `down()` remove índice e coluna.
  - `Incidente`: consts acima; `'tipo'` no `#[Fillable]`; `'tipo' => 'Tipo'` em `CAMPOS_RESTRITOS_APOS_ABERTURA`.
  - `store()`: regra `'tipo' => ['sometimes', 'string', Rule::in(Incidente::TIPOS)]`; antes do `create`, `$data['tipo'] ??= 'incidente'`.
  - `update()`: regra `'tipo' => ['sometimes', 'required', 'string', Rule::in(Incidente::TIPOS)]`; capturar `$tipoAnterior` junto dos demais `*Anterior` e passá-lo ao closure; dentro da transação, se `array_key_exists('tipo', $data)`, chamar `logAlteracaoSeMudou($incidente, $request->user(), 'Tipo', Incidente::TIPO_ROTULOS[$tipoAnterior], Incidente::TIPO_ROTULOS[$incidente->tipo])`.
  - `IncidenteResource`: `'tipo' => $this->tipo` logo após `status`.
  - Factory: `'tipo' => 'incidente'`.
  - `BACKEND_SPECS.md`: documentar coluna, regra de default e restrição.
- [ ] **Step 5: Run** o mesmo comando — Expected: PASS. Depois `docker compose exec app php artisan test --filter=Incidente` — Expected: PASS (nada quebrou no CRUD/SLA/feed).
- [ ] **Step 6: Commit** `feat: add tipo (incidente/solicitacao) to incidentes` com os arquivos acima.

### Task 2: Filtro `tipo` na listagem e no dashboard

**Files:**
- Modify: `app/Models/Incidente.php` (`scopeFiltros`)
- Modify: `app/Http/Controllers/Api/IncidenteController.php` (`index()`), `app/Http/Controllers/Api/DashboardController.php`
- Modify: `app/Http/Resources/IncidenteDashboardResource.php`
- Modify: `BACKEND_SPECS.md` (lista de filtros de `scopeFiltros` e do dashboard)
- Test: `tests/Feature/Incidentes/IncidenteTipoTest.php`, `tests/Feature/Dashboard/IncidentesDashboardTest.php`

**Interfaces:**
- Consumes: `Incidente::TIPOS` (Task 1).
- Produces: query param `tipo` em `GET /incidentes` e `GET /dashboard/incidentes`; campo `tipo` em cada linha do dashboard.

- [ ] **Step 1: Write the failing tests:**
  - `IncidenteTipoTest::test_index_filters_by_tipo`: um `incidente` e uma `solicitacao` (ambos `aberto`) → `GET /api/incidentes?tipo=solicitacao` retorna só o id da solicitação.
  - `IncidentesDashboardTest::test_filters_by_tipo_and_exposes_it`: mesmo cenário em `GET /api/dashboard/incidentes?tipo=solicitacao` → 1 linha, `data.0.tipo === 'solicitacao'`; `?tipo=problema` → 422.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --filter='IncidenteTipoTest|IncidentesDashboardTest'` — Expected: FAIL.
- [ ] **Step 3: Implement:** em `scopeFiltros` acrescentar `->when($filtros['tipo'] ?? null, fn (Builder $q, string $v) => $q->where('tipo', $v))`; regra `'tipo' => ['sometimes', 'string', Rule::in(Incidente::TIPOS)]` em `IncidenteController::index()` e `DashboardController`; `'tipo' => $this->tipo` em `IncidenteDashboardResource` (após `origem`).
- [ ] **Step 4: Run** o mesmo comando — Expected: PASS.
- [ ] **Step 5: Commit** `feat: filter incidentes and dashboard by tipo`.

### Task 3: Filtro `tipo` nos relatórios e relatórios salvos

**Files:**
- Modify: `app/Models/Incidente.php` (`scopeFiltrosRelatorio`), `app/Models/IncidenteEvento.php` (`scopeFiltrosRelatorio`)
- Modify: `app/Http/Controllers/Api/RelatorioController.php` (`validado()`), `app/Http/Controllers/Api/RelatorioSalvoController.php` (`validated()`)
- Modify: `BACKEND_SPECS.md` (§3.4.9 filtros de relatório; schema de `relatorios_salvos.filtros`)
- Test: `tests/Feature/Relatorios/RelatorioIncidentesTest.php`, `tests/Feature/Relatorios/RelatorioSalvoCrudTest.php`

**Interfaces:**
- Consumes: `Incidente::TIPOS`.
- Produces: filtro `tipo` em `GET /relatorios/incidentes` e em `filtros.tipo` de relatórios salvos.

- [ ] **Step 1: Write the failing tests** (seguir os helpers/fixtures já existentes em cada arquivo):
  - `RelatorioIncidentesTest::test_tipo_filter_applies_to_incidente_groupings`: 2 incidentes `incidente` e 1 `solicitacao` no mesmo item → `agrupar_por=item&tipo=solicitacao` soma total 1.
  - `RelatorioIncidentesTest::test_tipo_filter_applies_to_event_groupings`: um incidente `incidente` e uma `solicitacao`, ambos resolvidos pelo mesmo staff (evento de resolução criado como os testes existentes de `resolvido_por` criam) → `agrupar_por=resolvido_por&tipo=solicitacao` conta 1.
  - `RelatorioSalvoCrudTest::test_saved_report_accepts_tipo_filter`: POST com `filtros: {tipo: 'solicitacao'}` → 201 e `filtros.tipo` persistido; com `filtros: {tipo: 'problema'}` → 422 em `filtros.tipo`.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --filter='RelatorioIncidentesTest|RelatorioSalvoCrudTest'` — Expected: FAIL.
- [ ] **Step 3: Implement:** regra `'tipo' => ['sometimes', 'string', Rule::in(Incidente::TIPOS)]` em `RelatorioController::validado()` e `'filtros.tipo'` equivalente em `RelatorioSalvoController::validated()`; em `Incidente::scopeFiltrosRelatorio` `where('tipo', $v)`; em `IncidenteEvento::scopeFiltrosRelatorio` `whereHas('incidente', fn ($qi) => $qi->where('tipo', $v))` (mesmo padrão de `status`).
- [ ] **Step 4: Run** o mesmo comando — Expected: PASS. Depois a suíte inteira: `docker compose exec app php artisan test` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: filter reports and saved reports by tipo`.

---

## Frontend (`/home/daniel/Workspace/gespriority_claude_front`)

Sem suíte de testes no repo. Cada task verifica com `npm run build` (sem erros) e no navegador contra a API local (`http://localhost:8000`, `npm run dev`), logado como admin.

### Task 4: Rótulos de tipo

**Files:**
- Modify: `src/utils/incidentLabels.js`

**Interfaces:**
- Produces: `export const TIPO_LABELS = { incidente: 'Incidente', solicitacao: 'Solicitação' }` e `export const TIPO_COLORS = { incidente: 'error', solicitacao: 'info' }`.

Pequena demais para ficar sozinha: implementar e commitar junto com a Task 5.

### Task 5: Formulário — Tipo, Empresa → Solicitante, título "Chamado"

**Files:**
- Modify: `src/views/IncidentFormView.vue`
- Modify: `src/utils/incidentLabels.js` (Task 4)

**Interfaces:**
- Consumes: `TIPO_LABELS`; `clientService.lookup()` → `{ data: [{id, name}] }`; `customerService.lookup()` → `{ data: [{id, name, email, client: {id, name}}] }`; API aceita/devolve `tipo`.

- [ ] **Step 1: Implement:**
  - Refs novos `tipo` (default `'incidente'` em `resetForm()`), `clientId`, `clientOptions`; `loadClients()` no `Promise.all` de `init()`.
  - `filteredCustomerOptions = computed(() => clientId.value ? customerOptions.value.filter(c => c.client?.id === clientId.value) : [])`; `onClientChange()` zera `customerId`.
  - Derivar `clientId` do solicitante **depois** do `Promise.all` de `init()` (mesmo motivo da classificação: as listas podem não ter carregado), tanto na edição quanto no clone/`?customer_id=`: `clientId = customerOptions.find(c => c.id === customerId)?.client?.id ?? null`.
  - Substituir o `v-autocomplete` "Cliente" por dois: **Empresa** (`v-autocomplete`, `clientOptions`, `item-title="name"`, `@update:model-value="onClientChange"`) e **Solicitante** (`v-autocomplete`, `filteredCustomerOptions`, label `` `${name} (${email})` ``, `:disabled="!canEditRestrictedFields || !clientId"`). Os dois herdam `:disabled`/`:hint` de campo restrito como o atual. Remover `customerLabel()` se ficar sem uso.
  - Campo **Tipo** (`v-select`, opções de `TIPO_LABELS`, obrigatório, mesma regra de campo restrito), antes de Título.
  - `buildBasePayload()` inclui `tipo`; `loadIncident()` lê `incident.tipo`; `cloneAsNew()`/`applyCloneFromQuery()` carregam `tipo`.
  - Título: `isEditing ? \`Chamado #${id}\` : 'Novo Chamado'`; mensagens "Incidente atualizado…"/"…salvar o incidente." passam a "Chamado …"/"…o chamado."; comentário de campos restritos passa a citar Tipo.
- [ ] **Step 2: Run** `npm run build` — Expected: build sem erros.
- [ ] **Step 3: Verificar no navegador:** (a) Novo Chamado: Solicitante desabilitado até escolher Empresa; escolher Empresa filtra; trocar Empresa limpa Solicitante; criar como Solicitação → abre `Chamado #id` com Tipo = Solicitação. (b) Abrir um chamado antigo → Empresa preenchida, Tipo = Incidente. (c) Usuário sem `tickets.edit_all` → Tipo, Empresa e Solicitante desabilitados com o hint. (d) Clonar → Tipo, Empresa e Solicitante preservados.
- [ ] **Step 4: Commit** `feat: tipo field and Empresa → Solicitante selects on ticket form`.

### Task 6: Dashboard e relatórios — coluna e filtro Tipo

**Files:**
- Modify: `src/views/DashboardView.vue` (coluna, botão "Novo Chamado")
- Modify: `src/components/IncidentAdvancedFilterModal.vue` (filtro)
- Modify: `src/views/ReportsView.vue` (filtro, gravar/carregar em relatório salvo)
- Check: `src/utils/savedIncidentFilters.js` (se serializa chaves de filtro explicitamente, incluir `tipo`)

**Interfaces:**
- Consumes: `TIPO_LABELS`, `TIPO_COLORS`; `tipo` em cada linha de `/dashboard/incidentes`; query/filtro `tipo` nas três APIs.

- [ ] **Step 1: Implement:**
  - Dashboard: header `{ title: 'Tipo', key: 'tipo', sortable: false }` após "Número", renderizado como `v-chip` com `TIPO_LABELS`/`TIPO_COLORS` (mesmo padrão do chip de status). Botão "Novo Incidente" → "Novo Chamado".
  - Filtro avançado: `v-select` "Tipo" ao lado de Origem, mesmo ciclo dos demais (`props.filters.tipo`, limpar, emitir `tipo`).
  - Relatórios: `tipo` ref, `v-select` "Tipo" ao lado de Status; `buildFiltros()` inclui `tipo`; carregar relatório salvo lê `f.tipo`; limpar zera.
- [ ] **Step 2: Run** `npm run build` — Expected: sem erros.
- [ ] **Step 3: Verificar no navegador:** dashboard mostra a coluna Tipo e filtra por Solicitação; filtro salvo do dashboard volta com o tipo; relatório agrupado por Item e por Resolvido por, filtrado por Solicitação, muda os totais; salvar e reabrir o relatório preserva o Tipo.
- [ ] **Step 4: Commit** `feat: tipo column and filters on dashboard and reports`.
