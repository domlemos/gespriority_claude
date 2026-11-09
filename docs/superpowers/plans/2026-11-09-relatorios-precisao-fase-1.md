# Relatórios — precisão dos números (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer `GET /relatorios/incidentes` responder com precisão "quantos chamados os analistas do grupo X concluíram no período P" — período no horário de Brasília, chamados contados uma vez por analista, cancelados fora dos indicadores de desempenho, e grupo (do chamado e do analista) congelado no momento do evento.

**Architecture:** Sem endpoint novo. O período passa por um helper único (`App\Support\PeriodoRelatorio`) usado pelos dois `scopeFiltrosRelatorio`. `incidente_eventos` ganha duas colunas de snapshot (`grupo_solucao_id`, `autor_grupo_solucao_id`), preenchidas por um hook `creating` no model e retroativamente por migration. `RelatorioController` ganha o agrupamento `concluido_por`, contagem distinta por chamado nas dimensões de conclusão, e o filtro `grupo_analista_id` (que também lista analistas do grupo com zero). Frontend: só o necessário para usar os novos parâmetros.

**Tech Stack:** Laravel 12 / PHPUnit (backend `gespriority_claude`, testes em SQLite em memória, produção em PostgreSQL); Vue 3 + Vuetify 3 (frontend `gespriority_claude_front`, sem suíte de testes — verificação por build + navegador).

**Spec:** a análise do módulo de relatórios feita na conversa de 2026-11-09 (itens "Fase 1" + itens 6/7 restritos ao exemplo do usuário). As decisões que ela fixa estão em "Global Constraints" abaixo; a seção §3.4.9 / §3.1 `incidente_eventos` de `BACKEND_SPECS.md` é atualizada em cada task.

## Global Constraints

- Fuso de exibição/período: `America/Sao_Paulo`, lido de `config('app.timezone_exibicao')`. `config('app.timezone')` continua `UTC` (não mudar).
- `data_inicio`/`data_fim` continuam no formato `Y-m-d` e **inclusivos** nos dois extremos, interpretados como dias do horário de Brasília: `[inicio 00:00 BRT, (fim + 1 dia) 00:00 BRT)` convertido para UTC, com `>=` / `<`. Nunca `whereDate`.
- Novo agrupamento `concluido_por` = eventos `resolvido` **ou** `fechado`. Rótulo de UI: "Concluído por (resolvido ou fechado)".
- `resolvido_por`, `fechado_por`, `concluido_por` contam **chamados distintos por usuário** (`count(distinct incidente_id)`). `encaminhado_por`, `encaminhado_para_grupo`, `encaminhado_para_responsavel` continuam contando eventos (são ações, decisão já documentada em §3.4.9).
- O teste existente `test_agrupar_por_resolvido_por_counts_each_resolve_reopen_resolve_cycle_separately` (Agente A e Agente B no mesmo chamado → 1 e 1) deve continuar passando sem alteração.
- Nova constante `Incidente::STATUS_ATENDIDOS = ['resolvido', 'fechado']`. A restrição implícita de `status_sla`/`responsavel`/`grupo_solucao` (sem `status` explícito) passa de `STATUS_CONCLUIDOS` para `STATUS_ATENDIDOS`. `STATUS_CONCLUIDOS` em si **não muda** (é usado pelo SLA/`concluido_em`).
- Nos agrupamentos por evento, o filtro `grupo_solucao_id` passa a usar `incidente_eventos.grupo_solucao_id` (grupo do chamado no momento do evento), não o grupo atual do chamado.
- Novo filtro `grupo_analista_id` (`sometimes|integer|exists:grupos_solucao,id`), também em `filtros.grupo_analista_id` de relatório salvo. Semântica:
  - agrupamentos por evento: `incidente_eventos.autor_grupo_solucao_id = X`;
  - `responsavel`: `responsavel.grupo_solucao_id = X` (grupo atual do responsável);
  - `aberto_por`: `criadoPor.grupo_solucao_id = X`;
  - demais agrupamentos (`status_sla`, `grupo_solucao`, `categoria`, `subcategoria`, `item`): `responsavel.grupo_solucao_id = X`.
- Com `grupo_analista_id` e agrupamento por usuário (`resolvido_por`, `fechado_por`, `concluido_por`, `encaminhado_por`, `responsavel`), usuários **ativos** (não soft-deleted) com `users.grupo_solucao_id = X` que não aparecem no resultado entram com `total: 0`.
- Visibilidade (`scopeVisiveisPara`) **não muda** nesta fase.
- O formato da resposta `{agrupado_por, data: [{chave, rotulo, total}]}` não muda.
- O working tree tem alterações não relacionadas (`database/seeders/*`, `infra/terraform/...`) — nunca adicioná-las aos commits deste plano (`git add` só dos arquivos de cada task).
- Testes: `docker compose exec -T app php artisan test --filter=<Classe>`; suíte inteira com `docker compose exec -T app php artisan test`.
- Commits: um por task, no repo correspondente, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Evento às 23:30 BRT do último dia do período (02:30 UTC do dia seguinte) → **entra**; evento às 23:30 BRT da véspera do início (02:30 UTC do dia de início) → **não entra**. (Task 1)
2. Mesmo analista marca `resolvido` e depois `fechado` no mesmo chamado → `concluido_por` conta 1 para ele, não 2. (Task 3)
3. Chamado concluído no grupo X e depois encaminhado para Y → filtro `grupo_solucao_id=X` em `concluido_por` ainda conta. (Task 2)
4. Analista conclui chamados no grupo X e depois é transferido para Y → `grupo_analista_id=X` ainda conta essas conclusões; `grupo_analista_id=Y` não. (Task 2/4)
5. `grupo_analista_id=X` com um analista de X que não concluiu nada → aparece com `total: 0`; analista desativado de X sem conclusões → **não** aparece. (Task 4)

---

### Task 1: Período no horário de Brasília

**Files:**
- Create: `app/Support/PeriodoRelatorio.php`
- Modify: `config/app.php` (nova chave `timezone_exibicao` logo após `timezone`)
- Modify: `app/Models/Incidente.php:171-172`, `app/Models/IncidenteEvento.php:77-78`
- Modify: `app/Http/Controllers/Api/IncidenteController.php:30` (`TIMEZONE_EXIBICAO` sai; `agoraLocal()` usa `config('app.timezone_exibicao')`)
- Modify: `BACKEND_SPECS.md` §3.4.9 (linha de `data_inicio`/`data_fim`: dias em horário de Brasília, inclusivos)
- Test: `tests/Feature/Relatorios/RelatorioIncidentesTest.php`

**Interfaces:**
- Produces: `PeriodoRelatorio::inicioUtc(string $data): Carbon` (00:00 BRT do dia → UTC) e `PeriodoRelatorio::fimExclusivoUtc(string $data): Carbon` (00:00 BRT do dia seguinte → UTC). Uso: `where($col, '>=', inicioUtc(...))` / `where($col, '<', fimExclusivoUtc(...))`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_period_uses_brasilia_days_for_event_groupings(): void
{
    $setembro = User::factory()->create(['name' => 'Agente Setembro']);
    $outubro = User::factory()->create(['name' => 'Agente Outubro']);
    // 30/09 23:30 BRT — fora de outubro (hoje entra, pela data UTC)
    IncidenteEvento::factory()->create(['user_id' => $setembro->id])
        ->forceFill(['created_at' => '2026-10-01 02:30:00'])->save();
    // 31/10 23:30 BRT — dentro de outubro (hoje fica de fora)
    IncidenteEvento::factory()->create(['user_id' => $outubro->id])
        ->forceFill(['created_at' => '2026-11-01 02:30:00'])->save();
    // GET agrupar_por=resolvido_por&data_inicio=2026-10-01&data_fim=2026-10-31
    // assert: rótulos === ['Agente Outubro'], total 1
}

public function test_period_uses_brasilia_days_for_concluido_em(): void
{
    Incidente::factory()->create(['status' => 'fechado', 'concluido_em' => '2026-10-01 02:30:00']); // 30/09 BRT
    Incidente::factory()->create(['status' => 'fechado', 'concluido_em' => '2026-11-01 02:30:00']); // 31/10 BRT
    // GET agrupar_por=status_sla&data_inicio=2026-10-01 → soma === 1 (hoje dá 2)
    // GET agrupar_por=status_sla&data_fim=2026-09-30   → soma === 1 (hoje dá 0)
}
```

- [ ] **Step 2: Run** `docker compose exec -T app php artisan test --filter=RelatorioIncidentesTest` — Expected: os dois novos FALHAM.

- [ ] **Step 3: Implement** `PeriodoRelatorio` (`Carbon::parse($data, config('app.timezone_exibicao'))->startOfDay()->utc()`, e `->addDay()` para o fim) e troque os quatro `whereDate` pelos `where` acima. Mantenha o comentário de cabeçalho de `scopeFiltrosRelatorio` coerente.

- [ ] **Step 4: Run** a suíte `RelatorioIncidentesTest` e `RelatorioSalvoCrudTest` — Expected: PASS (inclusive `test_filters_by_data_inicio_and_data_fim_on_concluido_em` e `test_agrupar_por_resolvido_por_filters_by_the_resolution_events_own_date`).

- [ ] **Step 5: Commit** — `fix(relatorios): interpret report period as Brasília days instead of UTC dates`

---

### Task 2: Snapshot de grupo nos eventos

**Files:**
- Create: `database/migrations/2026_11_09_000000_add_grupo_snapshot_to_incidente_eventos_table.php`
- Modify: `app/Models/IncidenteEvento.php` (fillable, `booted()` com `creating`, `scopeFiltrosRelatorio` linha 91)
- Modify: `BACKEND_SPECS.md` §3.1 `incidente_eventos` (duas colunas novas + regra de backfill) e §3.4.9 (`grupo_solucao_id` em agrupamento por evento = grupo no momento do evento)
- Test: `tests/Feature/Relatorios/RelatorioIncidentesTest.php`, `tests/Feature/Relatorios/IncidenteEventoSnapshotTest.php` (novo)

**Interfaces:**
- Produces: colunas `incidente_eventos.grupo_solucao_id` e `incidente_eventos.autor_grupo_solucao_id` (ambas `foreignId` nullable → `grupos_solucao`, `nullOnDelete()`, indexadas). O hook `creating` preenche cada uma **só se o atributo não foi informado** (`array_key_exists` em `getAttributes()`), a partir de `Incidente::find(incidente_id)->grupo_solucao_id` e `User::withTrashed()->find(user_id)->grupo_solucao_id`. Nenhuma mudança em `IncidenteController`.

- [ ] **Step 1: Write the failing tests**
  - `IncidenteEventoSnapshotTest::test_event_snapshots_incident_group_and_author_group_on_creation`: grupo X, incidente em X, analista em Y; cria evento; depois move incidente para Z e analista para Z → `assertSame(X, $evento->fresh()->grupo_solucao_id)` e `assertSame(Y, $evento->fresh()->autor_grupo_solucao_id)`.
  - `IncidenteEventoSnapshotTest::test_explicit_snapshot_values_are_not_overwritten`: cria evento com `grupo_solucao_id => null` explícito → continua `null`.
  - `RelatorioIncidentesTest::test_event_groupings_filter_by_group_at_event_time`: incidente em X, evento `fechado` criado, incidente movido para Y → `agrupar_por=fechado_por&grupo_solucao_id={X}` soma 1; com `{Y}` soma 0.

- [ ] **Step 2: Run** `--filter='IncidenteEventoSnapshotTest|RelatorioIncidentesTest'` — Expected: FAIL (coluna inexistente).

- [ ] **Step 3: Implement** a migration (colunas + backfill) e o model.

  Backfill, só com query builder (sem models — mesma regra da migration de generalização), em `chunkById`:
  - `autor_grupo_solucao_id` = `users.grupo_solucao_id` atual do `user_id` (melhor informação disponível; documentar como aproximação).
  - `grupo_solucao_id` de um evento E do incidente I:
    1. se existe evento `encaminhado_grupo` de I com `created_at <= E.created_at` (desempate por `id <=`), usa o `alvo_id` do mais recente;
    2. senão, se I não tem nenhum `encaminhado_grupo` posterior a E, usa `incidentes.grupo_solucao_id` atual;
    3. senão, `null` (grupo inicial desconhecido — `store()` não gera evento de encaminhamento).

  `down()`: `dropConstrainedForeignId` nas duas colunas.

- [ ] **Step 4: Run** `--filter='IncidenteEventoSnapshotTest|RelatorioIncidentesTest|DashboardRelatorioVisibilidadeTest'` — Expected: PASS.

- [ ] **Step 5: Verify backfill** num banco com dados: `docker compose exec -T app php artisan migrate` e `docker compose exec -T app php artisan tinker --execute="dump(DB::table('incidente_eventos')->selectRaw('count(*) total, count(grupo_solucao_id) com_grupo, count(autor_grupo_solucao_id) com_autor')->first());"` — Expected: `com_grupo` e `com_autor` próximos de `total`; anote os números no relatório da task.

- [ ] **Step 6: Commit** — `feat(relatorios): snapshot incident and author group on incidente_eventos`

---

### Task 3: `concluido_por`, contagem distinta e cancelados

**Files:**
- Modify: `app/Http/Controllers/Api/RelatorioController.php` (`EVENTOS_POR_USUARIO`, `agregar()`, `contarAgrupado()`, linha 153)
- Modify: `app/Models/Incidente.php` (const `STATUS_ATENDIDOS` ao lado de `STATUS_CONCLUIDOS`)
- Modify: `app/Models/RelatorioSalvo.php` (`AGRUPAMENTOS` + `concluido_por`)
- Modify: `BACKEND_SPECS.md` §3.4.9 (novo agrupamento, regra de contagem distinta, restrição implícita = atendidos) e a lista de `AGRUPAMENTOS` em §3.2/§3.1 `relatorios_salvos`
- Test: `tests/Feature/Relatorios/RelatorioIncidentesTest.php`

**Interfaces:**
- Produces: `contarAgrupado(Builder $query, string $coluna, ?string $contarDistinto = null): Collection` — com `$contarDistinto` (ex. `'incidente_id'`) usa `count(distinct {$contarDistinto})`. Constante privada `RelatorioController::CONTAGEM_POR_CHAMADO = ['resolvido_por', 'fechado_por', 'concluido_por']`.

- [ ] **Step 1: Write the failing tests**
  - `test_concluido_por_counts_resolved_and_closed_once_per_incident`: analista A marca `resolvido` e `fechado` no incidente 1; `fechado` no incidente 2; analista B `resolvido` no incidente 3 → A = 2, B = 1.
  - `test_resolvido_por_counts_distinct_incidents_per_user`: A resolve o mesmo incidente duas vezes (dois eventos) → A = 1.
  - `test_performance_groupings_exclude_cancelled_by_default`: incidentes `fechado`, `resolvido`, `cancelado` no mesmo grupo → `agrupar_por=grupo_solucao` total 2; `status_sla` soma 2; com `status=cancelado` explícito, `grupo_solucao` total 1.
  - `test_saved_report_accepts_concluido_por`: em `RelatorioSalvoCrudTest`, POST com `agrupar_por: 'concluido_por'` → 201.

- [ ] **Step 2: Run** `--filter='RelatorioIncidentesTest|RelatorioSalvoCrudTest'` — Expected: FAIL (422 para `concluido_por`; contagens 3/2).

- [ ] **Step 3: Implement** — `'concluido_por' => [TIPO_RESOLVIDO, TIPO_FECHADO]` em `EVENTOS_POR_USUARIO`; no ramo de eventos, passar `'incidente_id'` para `contarAgrupado` quando `$agruparPor` está em `CONTAGEM_POR_CHAMADO`; linha 153 usa `STATUS_ATENDIDOS`. Atualizar o docblock de `agregar()` com as duas regras novas.

- [ ] **Step 4: Run** a suíte inteira — Expected: PASS, incluindo o teste de ciclo resolve/reabre/resolve (A=1, B=1, soma 2).

- [ ] **Step 5: Commit** — `feat(relatorios): concluido_por grouping, distinct-ticket counting, exclude cancelled from performance`

---

### Task 4: Filtro `grupo_analista_id` com analistas zerados

**Files:**
- Modify: `app/Http/Controllers/Api/RelatorioController.php` (`validado()`, `agregar()`, novo método privado de preenchimento com zero)
- Modify: `app/Models/Incidente.php` (`scopeFiltrosRelatorio` — ver regra abaixo), `app/Models/IncidenteEvento.php` (`scopeFiltrosRelatorio`)
- Modify: `app/Http/Controllers/Api/RelatorioSalvoController.php:81-91` (`filtros.grupo_analista_id`)
- Modify: `app/Exports/RelatorioIncidentesExport.php` (cabeçalho da 1ª coluna = rótulo do agrupamento, não "Categoria")
- Modify: `BACKEND_SPECS.md` §3.4.9 (novo param + regra de zerados + cabeçalho da planilha) e §3.1 `relatorios_salvos.filtros`
- Test: `tests/Feature/Relatorios/RelatorioIncidentesTest.php`, `tests/Feature/Relatorios/RelatorioSalvoCrudTest.php`

**Interfaces:**
- Consumes: `incidente_eventos.autor_grupo_solucao_id` (Task 2); `CONTAGEM_POR_CHAMADO`/`concluido_por` (Task 3).
- Produces:
  - `IncidenteEvento::scopeFiltrosRelatorio`: `grupo_analista_id` → `where('autor_grupo_solucao_id', $v)`.
  - `Incidente::scopeFiltrosRelatorio`: `grupo_analista_id` → `whereHas('responsavel', fn ($q) => $q->where('grupo_solucao_id', $v))`.
  - `aberto_por` é a exceção: `agregar()` retira `grupo_analista_id` de `$filtros` e aplica ele mesmo `whereHas('criadoPor', ...)`. Com esse filtro presente, `separarAbertosPeloPortal()` não roda (chamado do portal não tem analista criador).
  - `RelatorioIncidentesExport::__construct(Collection $linhas, string $rotuloAgrupamento)`.
  - Constante privada `RelatorioController::AGRUPAMENTOS_POR_USUARIO = ['resolvido_por', 'fechado_por', 'concluido_por', 'encaminhado_por', 'responsavel']`.

- [ ] **Step 1: Write the failing tests**
  - `test_grupo_analista_filters_by_author_group_at_event_time`: analistas A (grupo X) e B (grupo Y) concluem 1 chamado cada; A é transferido para Y depois → `concluido_por&grupo_analista_id={X}` retorna só A com 1; `{Y}` retorna B com 1 (A não, pois concluiu enquanto estava em X).
  - `test_grupo_analista_lists_active_members_with_zero`: grupo X com A (1 conclusão), C (0) e D (0, soft-deleted) → linhas A=1, C=0; D ausente.
  - `test_grupo_analista_on_responsavel_grouping_uses_current_group`: dois chamados `fechado`, responsáveis em X e em Y → `responsavel&grupo_analista_id={X}` soma 1.
  - `test_grupo_analista_on_aberto_por_uses_creator_group_and_drops_portal_bucket`: um chamado criado por analista de X, um por analista de Y, um do portal → `aberto_por&grupo_analista_id={X}` retorna só o analista de X com 1, sem linha "Portal (cliente)".
  - `test_xlsx_heading_uses_grouping_label`: `agrupar_por=concluido_por&formato=xlsx` → abrir o download com `Excel::fake()`/`Excel::assertDownloaded(... fn (RelatorioIncidentesExport $e) => $e->headings() === ['Concluído por', 'Total'])`.
  - `RelatorioSalvoCrudTest::test_saved_report_accepts_grupo_analista_id`: válido → 201 e persistido; id inexistente → 422 em `filtros.grupo_analista_id`.

- [ ] **Step 2: Run** `--filter='RelatorioIncidentesTest|RelatorioSalvoCrudTest'` — Expected: FAIL.

- [ ] **Step 3: Implement.** Rótulos dos agrupamentos para a planilha: um array `RelatorioController::ROTULOS_AGRUPAMENTO` com os mesmos textos de `AGRUPAMENTO_OPTIONS` do frontend (`'concluido_por' => 'Concluído por'`, demais iguais ao front). Os zerados entram depois de `comRotulos()`, com `chave` = id do usuário e `rotulo` = nome; ordenar o resultado final por `total` desc, depois `rotulo`.

- [ ] **Step 4: Run** a suíte inteira — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(relatorios): grupo_analista_id filter listing idle analysts; xlsx heading per grouping`

---

### Task 5: Frontend — usar os novos parâmetros

**Repo:** `/home/daniel/Workspace/gespriority_claude_front`

**Files:**
- Modify: `src/views/ReportsView.vue`

**Interfaces:**
- Consumes: `agrupar_por=concluido_por`, filtro `grupo_analista_id` (Tasks 3–4).

- [ ] **Step 1: Implement**
  - `AGRUPAMENTO_OPTIONS`: inserir `{ value: 'concluido_por', title: 'Concluído por (resolvido ou fechado)' }` como primeira opção do bloco de eventos (antes de `resolvido_por`).
  - Novo `ref` `grupoAnalistaId`, `v-select` "Grupo do analista" (mesmas opções de `solutionGroupOptions`) na linha de Grupo de Solução/Responsável; renomear o rótulo existente "Grupo de Solução" para "Grupo do chamado". Incluir em `buildFiltros()` (`grupo_analista_id`) e em `loadSavedIntoFilters()`.
  - Campos de data: `hint` dinâmico — "Data da conclusão do chamado" para agrupamentos sobre `Incidente`, "Data da ação (resolução/fechamento/encaminhamento)" para os de evento — com `persistent-hint`.
  - Remover `{ value: 'linha', ... }` de `CHART_TYPE_OPTIONS` (série categórica; linha volta na Fase 2 junto com eixo temporal).

- [ ] **Step 2: Verify** — `npm run build` sem erros; com backend local rodando, na tela Relatórios: `Concluído por` + Grupo do analista = um grupo com membros ociosos + período do mês → barras incluem membros com 0; exportar `.xlsx` e conferir cabeçalho "Concluído por".

- [ ] **Step 3: Commit** (no repo do front) — `feat(relatorios): concluido_por grouping and analyst-group filter`

---

## Fora do escopo (Fase 2)

Seletor explícito "o que contar" (abertos × concluídos × …) com a data do período seguindo o fato contado; tabela com total/percentual; drill-down para a lista de chamados; eixo temporal (dia/semana/mês) e tabela cruzada; períodos relativos em relatórios salvos; visibilidade de eventos baseada no snapshot de grupo.
