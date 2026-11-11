# Relatórios — Fase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O gestor escolhe *o que contar* e *como agrupar*, com quebra opcional por dia/semana/mês, e recebe gráfico + tabela com total/% e frase de título; clicar numa linha mostra os chamados; imagem e planilha saem autoexplicativas; relatórios salvos guardam atalhos de período.

**Architecture:** `GET /api/relatorios/incidentes` com `contar` passa a um caminho novo (`RelatorioConsultaController`); sem `contar`, o caminho legado responde exatamente como hoje. O caminho novo vive em `app/Relatorios/`: uma `ConsultaRelatorio` validada, duas fontes (`FonteIncidentes`, `FonteEventos`) que devolvem **linhas-base** (`chave`, `incidente_id`, `evento_id`, `autor_id`, `data`) já filtradas e com visibilidade aplicada, e um `AgregadorRelatorio` em PHP que monta colunas de período, contagem por célula, totais, % e rodapé de distintos. A mesma linha-base alimenta a lista de chamados — por construção a lista bate com o número.

**Tech Stack:** Laravel 12 / PHPUnit / maatwebsite/excel (backend `gespriority_claude`, testes SQLite em memória, produção PostgreSQL); Vue 3 + Vuetify 3 + Chart.js + chartjs-plugin-datalabels (frontend `gespriority_claude_front`, sem suíte de testes, build com Node 22).

**Spec:** `docs/superpowers/specs/2026-11-09-relatorios-fase-2-design.md` (aprovado). Leia as seções 5, 6 e 6A antes de qualquer task.

## Global Constraints

- Valores de `contar`: `abertos`, `concluidos`, `resolvidos`, `fechados`, `cancelados`, `encaminhamentos`.
- Base, data e contagem por `contar` (spec 5.2 + 6A.1): `abertos` = `incidentes`, `created_at`, todos os status (cancelados inclusos); `cancelados` = `incidentes` com `status = cancelado`, `concluido_em`; `concluidos` = eventos `resolvido`+`fechado`, `resolvidos` = `resolvido`, `fechados` = `fechado` — data do evento, chamados distintos por célula; `encaminhamentos` = eventos `encaminhado_grupo`+`encaminhado_responsavel`, data do evento, conta eventos.
- Valores de `agrupar_por` no caminho novo, por `contar`:
  - `abertos`, `cancelados`: `categoria`, `subcategoria`, `item`, `grupo_chamado`, `responsavel`, `aberto_por`, `cliente`, `tipo`
  - `concluidos`, `resolvidos`, `fechados`: `analista`, `grupo_analista`, `grupo_chamado`, `categoria`, `subcategoria`, `item`, `cliente`, `tipo`, `status_sla`
  - `encaminhamentos`: `quem_encaminhou`, `para_grupo`, `para_responsavel`
- `quebra`: `nenhuma` (padrão) | `dia` | `semana` | `mes`. Semana começa na segunda; chave da coluna = data (Y-m-d) do início do balde em Brasília. Rótulos: dia `06/10`, semana `Sem 06/10`, mês `Out/2026` (meses `Jan Fev Mar Abr Mai Jun Jul Ago Set Out Nov Dez`).
- Limite de colunas: dia 62, semana 53, mês 24 → 422 com erro em `quebra`: `"Período longo demais para quebra por {dia|semana|mês}: máximo de {N} colunas."`. `quebra` ≠ `nenhuma` exige período (atalho ou datas) → 422 em `quebra`: `"Informe um período para quebrar por {dia|semana|mês}."`.
- `periodo` (atalho): `este_mes` = [dia 1 do mês, hoje]; `mes_passado` = [dia 1, último dia do mês anterior]; `ultimos_7_dias` = [hoje − 6, hoje]. "Hoje" em Brasília (`config('app.timezone_exibicao')`). `periodo` e `data_inicio`/`data_fim` são mutuamente exclusivos (422 em `periodo`).
- Datas continuam dias de Brasília inclusivos via `App\Support\PeriodoRelatorio` (`>=` início UTC, `<` fim exclusivo UTC). Nunca `whereDate`.
- Filtros (spec 6A.2/6A.3): nas fontes de evento, `grupo_solucao_id` = `incidente_eventos.grupo_solucao_id` e `grupo_analista_id` = `incidente_eventos.autor_grupo_solucao_id`; demais filtros = estado atual do chamado. Nas fontes de incidente, `grupo_solucao_id` = grupo atual; `grupo_analista_id` = grupo atual do responsável, exceto com `agrupar_por=aberto_por`, onde é o grupo do criador.
- Visibilidade (spec 5.3): eventos visíveis se o chamado é visível hoje **ou** `grupo_solucao_id` do evento ∈ grupos visíveis **ou** `autor_grupo_solucao_id` ∈ grupos visíveis. Fontes de incidente: regra atual (`Incidente::visiveisPara`). Admin (`visibleGrupoSolucaoIds() === null`) vê tudo.
- Zero-fill: com `grupo_analista_id` e `agrupar_por` ∈ {`analista`, `responsavel`, `quem_encaminhou`}, usuários ativos com `users.grupo_solucao_id = X` sem linha entram com total 0 (todas as células 0).
- Totais (spec 6A.5): célula = chamados distintos da linha naquele período (ou eventos, em `encaminhamentos`); total da linha = soma das células; total da coluna = soma das linhas; total geral = soma; `percentual` = total da linha ÷ total geral × 100, uma casa decimal (0 quando total geral = 0). `chamados_distintos` = `count(distinct incidente_id)` das linhas-base, devolvido sempre; a UI/planilha mostram o rodapé "Chamados distintos no resultado: N" só quando N ≠ total geral.
- Ordenação das linhas: total desc, depois rótulo sem diferenciar maiúsculas/acentos (`Collator('pt_BR')` se a extensão `intl` existir, senão `strcasecmp`). Colunas em ordem cronológica. Chave nula vira `"__vazio__"` na resposta.
- Rótulos de linha vazia: `categoria`/`subcategoria`/`item` "(sem classificação)"; `grupo_chamado`/`grupo_analista`/`para_grupo` "(sem grupo)"; `responsavel`/`para_responsavel` "(sem responsável)"; `analista`/`quem_encaminhou` "(usuário desconhecido)"; `aberto_por` "(desconhecido)" + linha `portal` "Portal (cliente)" como na Fase 1; `cliente` "(sem cliente)". `tipo` usa `Incidente::TIPOS` → "Incidente"/"Solicitação"; `status_sla` usa os rótulos da Fase 1 ("Dentro do prazo", "Fora do prazo", "Sem SLA aplicável").
- Frase de título (spec 5.4) montada no backend: `"{Sujeito} {período} por {agrupamento}[, por {dia|semana|mês}][ — {filtros}]"`. Sujeito: `Chamados abertos (criados)`, `Chamados concluídos (resolvidos ou fechados)`, `Chamados resolvidos`, `Chamados fechados`, `Chamados cancelados`, `Encaminhamentos`. Período: `de dd/mm/aaaa a dd/mm/aaaa` ou `em todo o período`. Agrupamento: `analista`, `grupo do analista`, `grupo do chamado`, `categoria`, `subcategoria`, `item`, `cliente`, `tipo`, `status de SLA`, `responsável`, `quem abriu`, `quem encaminhou`, `grupo de destino`, `responsável de destino`. Filtros por extenso, separados por `; `, na ordem: `grupo do analista: X`, `grupo do chamado: X`, `cliente: X`, `usuário do cliente: X`, `categoria: X`, `subcategoria: X`, `item: X`, `tipo: X`, `responsável: X`, `status: X` (rótulos de status iguais ao front: Aberto, Em andamento, Pendente, Resolvido, Fechado, Cancelado).
- Resposta JSON do caminho novo: `{titulo, contar, agrupar_por, quebra, periodo: {inicio, fim}|null, colunas: [{chave, rotulo}], linhas: [{chave, rotulo, celulas: {<colchave>: int}, total, percentual}], totais: {celulas: {<colchave>: int}, total}, chamados_distintos}`. Sem quebra, `colunas` = `[{chave: "total", rotulo: "Total"}]` e cada `celulas` = `{total: n}`.
- Caminho legado (`GET /relatorios/incidentes` sem `contar`) e seus 60+ testes atuais não mudam.
- Sem dependências novas em nenhum dos dois repos. Push na `main` publica em produção: trabalhar em branch `feat/relatorios-fase-2` nos dois repos (in-place, o container monta a raiz do repo), nunca push.
- O working tree do backend tem alterações não relacionadas (`database/seeders/*`, `infra/terraform/...`): nunca adicioná-las aos commits.
- Testes backend: `docker compose exec -T app php artisan test --filter=<Classe>`; suíte inteira antes de cada commit. Front: `npm run build` com Node 22.
- Documentação: cada task de backend atualiza `BACKEND_SPECS.md` §3.4.9 (nova subseção "Caminho novo (`contar`)") e §3.1/§3.2 quando mexe em tabela/model, descrevendo o código como ficou.
- Commits: um por task, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Chamado criado 31/10 23:30 BRT (01/11 02:30 UTC) conta em "abertos" de outubro e na coluna `2026-10-01` de quebra por mês, não em novembro. (Task 2)
2. Quebra por semana com período iniciando numa quinta (01/10/2026): primeira coluna é `2026-09-28` "Sem 28/09" e só conta o que está dentro do período. (Task 1, Task 2)
3. Relatório salvo com `periodo=mes_passado` executado em 05/11 e em 05/12 cobre outubro e novembro respectivamente. (Task 6)
4. Para qualquer célula, o número de linhas da lista de chamados é igual ao valor da célula — inclusive resolvido+fechado pela mesma pessoa (1 linha) e 3 encaminhamentos em 2 chamados (3 linhas). (Task 5)
5. Gestor não-admin do N2: conclusão de analista do N2 num chamado hoje no N3 aparece no número e na lista, com `acessivel: false`. (Task 3, Task 5)

---

### Task 1: Atalhos de período e colunas de quebra

**Files:**
- Modify: `app/Support/PeriodoRelatorio.php`
- Test: `tests/Unit/PeriodoRelatorioTest.php` (novo)

**Interfaces:**
- Produces (todos `public static` em `PeriodoRelatorio`):
  - `const ATALHOS = ['este_mes', 'mes_passado', 'ultimos_7_dias']`, `const QUEBRAS = ['nenhuma', 'dia', 'semana', 'mes']`, `const LIMITE_COLUNAS = ['dia' => 62, 'semana' => 53, 'mes' => 24]`.
  - `resolverAtalho(string $atalho, ?CarbonInterface $agora = null): array{0: string, 1: string}` — datas `Y-m-d` em Brasília.
  - `colunas(string $inicio, string $fim, string $quebra): array<string, string>` — chave `Y-m-d` do início do balde → rótulo, cronológico. `quebra=nenhuma` → `['total' => 'Total']`.
  - `colunaDe(CarbonInterface $dataUtc, string $quebra): string` — chave do balde da data convertida para Brasília (`'total'` para `nenhuma`).

- [ ] **Step 1: Write the failing tests** (`Tests\Unit\PeriodoRelatorioTest`, estende `Tests\TestCase` para ter config):
  - `test_atalhos_resolve_in_brasilia`: agora = `2026-11-01 02:30 UTC` (31/10 23:30 BRT) → `este_mes` = `['2026-10-01','2026-10-31']`, `mes_passado` = `['2026-09-01','2026-09-30']`, `ultimos_7_dias` = `['2026-10-25','2026-10-31']`.
  - `test_colunas_por_semana_start_on_monday`: `colunas('2026-10-01','2026-10-14','semana')` = `['2026-09-28' => 'Sem 28/09', '2026-10-05' => 'Sem 05/10', '2026-10-12' => 'Sem 12/10']`. (01/10/2026 é quinta; segunda anterior = 28/09.)
  - `test_colunas_por_mes_and_dia`: `colunas('2026-09-15','2026-11-02','mes')` = `['2026-09-01'=>'Set/2026','2026-10-01'=>'Out/2026','2026-11-01'=>'Nov/2026']`; `colunas('2026-10-30','2026-11-01','dia')` = `['2026-10-30'=>'30/10','2026-10-31'=>'31/10','2026-11-01'=>'01/11']`.
  - `test_coluna_de_uses_brasilia_day`: `colunaDe(Carbon::parse('2026-11-01 02:30', 'UTC'), 'mes')` = `'2026-10-01'`; com `'dia'` = `'2026-10-31'`; com `'nenhuma'` = `'total'`.

- [ ] **Step 2: Run** `docker compose exec -T app php artisan test --filter=PeriodoRelatorioTest` — Expected: FAIL (métodos inexistentes).

- [ ] **Step 3: Implement** os métodos acima, sempre convertendo para `config('app.timezone_exibicao')` antes de calcular dia/semana/mês.

- [ ] **Step 4: Run** a classe e a suíte inteira — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(relatorios): period shortcuts and breakdown columns in Brasília time`

---

### Task 2: Caminho novo — consulta, fonte de incidentes e agregador

**Files:**
- Create: `app/Relatorios/ConsultaRelatorio.php`, `app/Relatorios/LinhaBase.php`, `app/Relatorios/FonteRelatorio.php`, `app/Relatorios/FonteIncidentes.php`, `app/Relatorios/AgregadorRelatorio.php`, `app/Relatorios/ResultadoRelatorio.php`, `app/Relatorios/RotulosRelatorio.php`
- Create: `app/Http/Controllers/Api/RelatorioConsultaController.php`
- Modify: `app/Http/Controllers/Api/RelatorioController.php` (`index()` delega quando `$request->has('contar')`)
- Modify: `BACKEND_SPECS.md` §3.4.9 (subseção nova: parâmetros, tabela `contar`→base/data/agrupamentos, formato da resposta, totais)
- Test: `tests/Feature/Relatorios/RelatorioConsultaTest.php` (novo)

**Interfaces:**
- Consumes: `PeriodoRelatorio::resolverAtalho/colunas/colunaDe/inicioUtc/fimExclusivoUtc` (Task 1).
- Produces:
  - `final readonly class LinhaBase { public function __construct(public int|string|null $chave, public int $incidenteId, public ?int $eventoId, public ?int $autorId, public CarbonImmutable $data) }`
  - `final readonly class ConsultaRelatorio` com `string $contar, string $agruparPor, string $quebra, ?string $inicio, ?string $fim, ?string $atalho, array $filtros`; consts `CONTAR`, `AGRUPAMENTOS_POR_CONTAR` (Global Constraints); `static regras(): array` (regras de validação, reutilizadas pelo relatório salvo na Task 6, com chaves sem prefixo); `static deDados(array $validado, ?CarbonInterface $agora = null): self` (resolve atalho em `inicio`/`fim`); `fonte(): FonteRelatorio`.
  - `interface FonteRelatorio { public function linhas(ConsultaRelatorio $c, User $u): Collection /* <LinhaBase> */; public function rotulos(ConsultaRelatorio $c, array $chaves): array /* chave => rótulo */; public function contaEventos(): bool; }`
  - `FonteIncidentes implements FonteRelatorio` (abertos, cancelados).
  - `AgregadorRelatorio::agregar(ConsultaRelatorio $c, Collection $linhas, FonteRelatorio $fonte, ?Collection $zerados = null): ResultadoRelatorio` — `$zerados` = `[chave => rótulo]` a acrescentar com 0 (usado na Task 3).
  - `ResultadoRelatorio` com `toArray(): array` no formato JSON das Global Constraints (sem `titulo` até a Task 4: devolva `titulo: ''` agora) e acessores `colunas()`, `linhas()`, `totais()`, `chamadosDistintos()`.
  - `RotulosRelatorio` — resolvedores compartilhados de id → nome (usuários `withTrashed`, grupos, categoria/subcategoria/item, clientes) e rótulos vazios das Global Constraints; reutilizado pela Task 3 e Task 4.
  - `RelatorioConsultaController::validado(Request $r): ConsultaRelatorio` e `responder(ConsultaRelatorio $c, string $formato, User $u)` (só `json` nesta task).
- `FonteIncidentes::linhas` seleciona do banco só `id`, a coluna de data e a coluna/expressão da `chave` (joins em `itens`/`subcategorias`/`customers`/`users` conforme o agrupamento) — nunca hidrata o model completo. `status = cancelado` para `cancelados`; nada para `abertos`. `aberto_por` mantém a linha `portal` da Fase 1 (chamados com `criado_por_id` nulo e `criado_por_customer_id` preenchido → chave `'portal'`).

- [ ] **Step 1: Write the failing tests** em `RelatorioConsultaTest` (token admin `staffToken(['relatorios.view'], asAdmin: true)` salvo indicação):
  - `test_abertos_counts_all_created_in_period_regardless_of_status`: em out/2026, chamados `aberto`, `fechado`, `cancelado` na mesma categoria; um criado em set → `contar=abertos&agrupar_por=categoria&data_inicio=2026-10-01&data_fim=2026-10-31` → uma linha, `total` 3, `percentual` 100.0, `chamados_distintos` 3.
  - `test_abertos_uses_brasilia_day_and_month_column`: chamado com `created_at` `2026-11-01 02:30:00` → `quebra=mes&data_inicio=2026-10-01&data_fim=2026-11-30` → `celulas['2026-10-01']` = 1, `celulas['2026-11-01']` = 0. (Review Focus 1)
  - `test_weekly_breakdown_first_column_is_monday_before_start`: período 2026-10-01..2026-10-14, `quebra=semana` → `colunas[0]` = `{chave: '2026-09-28', rotulo: 'Sem 28/09'}`; um chamado criado 2026-09-29 não conta. (Review Focus 2)
  - `test_cancelados_filters_by_concluido_em_and_status`: cancelado com `concluido_em` em out e `created_at` em set → conta em out; `fechado` em out não conta.
  - `test_totals_percent_and_sorting`: categorias A (3) e B (1) → linhas ordenadas A, B; `percentual` 75.0 e 25.0; `totais.total` 4.
  - `test_empty_key_is_vazio_with_label`: chamado sem item em `agrupar_por=item` → `chave` `'__vazio__'`, `rotulo` `'(sem classificação)'`.
  - `test_rejects_grouping_not_allowed_for_contar`: `contar=abertos&agrupar_por=analista` → 422 em `agrupar_por`.
  - `test_breakdown_requires_period_and_respects_column_limit`: `quebra=dia` sem período → 422 em `quebra`; `quebra=dia` de 2026-01-01 a 2026-03-31 → 422 em `quebra` com a mensagem das Global Constraints.
  - `test_periodo_and_dates_are_mutually_exclusive`: `periodo=este_mes&data_inicio=2026-10-01` → 422 em `periodo`.
  - `test_legacy_path_unchanged_without_contar`: `agrupar_por=categoria` (sem `contar`) → resposta tem `agrupado_por` e `data` (formato antigo).
  - `test_non_admin_only_counts_visible_incidents`: token com grupo N2; chamado em N3 não conta em `abertos`.

- [ ] **Step 2: Run** `--filter=RelatorioConsultaTest` — Expected: FAIL.

- [ ] **Step 3: Implement** as classes e a delegação. `AgregadorRelatorio`: para cada `LinhaBase`, coluna = `PeriodoRelatorio::colunaDe($l->data, quebra)`; célula conta `incidenteId` distintos (ou `eventoId` quando `fonte->contaEventos()`). Colunas vêm de `PeriodoRelatorio::colunas()` quando há período; sem período e sem quebra, `['total' => 'Total']`.

- [ ] **Step 4: Run** a classe e a suíte inteira (legado intacto) — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(relatorios): contar-based report path with incident source and aggregator`

---

### Task 3: Fonte de eventos, visibilidade por snapshot e zero-fill

**Files:**
- Create: `app/Relatorios/FonteEventos.php`
- Modify: `app/Models/IncidenteEvento.php` (novo scope `scopeVisiveisParaRelatorio(Builder $q, User $u)`; manter `scopeVisiveisPara` como está)
- Modify: `app/Relatorios/ConsultaRelatorio.php` (`fonte()` cobre os 4 `contar` de evento), `app/Http/Controllers/Api/RelatorioConsultaController.php` (zero-fill)
- Modify: `BACKEND_SPECS.md` §3.4.9 (fontes de evento, filtros por snapshot, visibilidade nova, zero-fill) e §3.2 linha de `IncidenteEvento`
- Test: `tests/Feature/Relatorios/RelatorioConsultaEventosTest.php` (novo)

**Interfaces:**
- Consumes: `LinhaBase`, `FonteRelatorio`, `AgregadorRelatorio::agregar(..., ?Collection $zerados)`, `RotulosRelatorio` (Task 2); colunas snapshot da Fase 1.
- Produces: `FonteEventos implements FonteRelatorio` (`contaEventos()` true só para `encaminhamentos`); `IncidenteEvento::scopeVisiveisParaRelatorio`. `LinhaBase.eventoId`/`autorId` preenchidos. `status_sla` como chave: busca `prazo_resolucao`/`concluido_em` dos incidentes envolvidos numa única query e usa `Incidente::statusSlaResolucao()` (chaves `dentro_prazo`/`estourado`/`sem_sla`).

- [ ] **Step 1: Write the failing tests**:
  - `test_concluidos_counts_resolved_and_closed_once_per_ticket_per_analyst`: A resolve e fecha o chamado 1, fecha o 2; B resolve o 3 → `contar=concluidos&agrupar_por=analista` → A 2, B 1, `chamados_distintos` 3.
  - `test_resolvidos_and_fechados_use_single_event_type`: mesmo cenário → `resolvidos` A 1, B 1; `fechados` A 2.
  - `test_encaminhamentos_count_events`: 3 eventos de encaminhamento de C em 2 chamados → `quem_encaminhou` C 3, `chamados_distintos` 2.
  - `test_weekly_breakdown_counts_ticket_in_each_week_it_was_concluded`: A resolve o chamado 1 na semana 1 e fecha na semana 2 → células 1 e 1, total da linha 2, `chamados_distintos` 1.
  - `test_event_filters_use_snapshots`: evento com `grupo_solucao_id` N2 e `autor_grupo_solucao_id` N2; chamado movido depois para N3 → `grupo_solucao_id={N2}` conta 1, `{N3}` conta 0; `grupo_analista_id={N2}` conta 1.
  - `test_non_admin_sees_own_team_events_on_tickets_moved_away`: token do grupo N2 (não admin); analista do N2 conclui chamado que depois vai para N3 → `concluidos&agrupar_por=analista` conta 1. Evento de analista do N4 em chamado do N4 → não conta. (Review Focus 5)
  - `test_zero_fill_for_analyst_group`: `grupo_analista_id=N2` com membros A (1 conclusão), C (0) e D (soft-deleted, 0) → linhas A=1, C=0; D ausente; com quebra mensal C tem todas as células 0.
  - `test_status_sla_grouping_for_concluidos`: chamados concluídos dentro e fora do prazo → linhas `dentro_prazo` 1 e `estourado` 1 com os rótulos da Fase 1.

- [ ] **Step 2: Run** `--filter=RelatorioConsultaEventosTest` — Expected: FAIL.

- [ ] **Step 3: Implement.** Visibilidade: `whereHas('incidente', fn ($q) => $q->visiveisPara($u))` OR `whereIn('grupo_solucao_id', $ids)` OR `whereIn('autor_grupo_solucao_id', $ids)`, agrupado num `where(fn …)`; admin sem restrição. Zero-fill: o controller passa `$zerados` (usuários ativos do grupo, `id => name`) quando `grupo_analista_id` e `agrupar_por ∈ {analista, responsavel, quem_encaminhou}` — se a Task 2 não cobriu `responsavel`, cubra aqui também.

- [ ] **Step 4: Run** a classe e a suíte inteira — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(relatorios): event sources with snapshot filters, team visibility and zero-fill`

---

### Task 4: Frase de título e planilha do caminho novo

**Files:**
- Create: `app/Relatorios/TituloRelatorio.php`, `app/Exports/RelatorioTabelaExport.php`
- Modify: `app/Http/Controllers/Api/RelatorioConsultaController.php` (`titulo` na resposta; `formato=xlsx`)
- Modify: `BACKEND_SPECS.md` §3.4.9 (título e layout da planilha)
- Test: `tests/Feature/Relatorios/RelatorioConsultaSaidaTest.php` (novo)

**Interfaces:**
- Consumes: `ConsultaRelatorio`, `ResultadoRelatorio`, `RotulosRelatorio`.
- Produces: `TituloRelatorio::montar(ConsultaRelatorio $c): string`; `RelatorioTabelaExport::__construct(string $titulo, ResultadoRelatorio $r, User $geradoPor, CarbonInterface $geradoEm)` implementando `FromArray` (sem fórmulas). Arquivo: `relatorio-{contar}-{agrupar_por}.xlsx`.
- Layout da planilha: linha 1 título; linha 2 `"Gerado em dd/mm/aaaa hh:mm (horário de Brasília) por {nome}"`; linha 3 vazia; linha 4 cabeçalho `[rótulo do agrupamento (capitalizado, ex. "Analista"), ...rótulos das colunas (omitidos sem quebra), "Total", "%"]`; linhas de dados com `%` como número com 1 casa; linha `"Total"`; e, se `chamados_distintos ≠ total geral`, linha `"Chamados distintos no resultado: N"`.

- [ ] **Step 1: Write the failing tests**:
  - `test_titulo_describes_query`: `contar=concluidos&agrupar_por=analista&data_inicio=2026-10-01&data_fim=2026-10-31&grupo_analista_id={N2 'N2'}` → `titulo` = `"Chamados concluídos (resolvidos ou fechados) de 01/10/2026 a 31/10/2026 por analista — grupo do analista: N2"`.
  - `test_titulo_with_breakdown_and_no_period_text`: `contar=abertos&agrupar_por=categoria&periodo=mes_passado&quebra=mes` com `travelTo('2026-11-05 12:00')` → `"Chamados abertos (criados) de 01/10/2026 a 31/10/2026 por categoria, por mês"`; sem período nem quebra → contém `"em todo o período"`.
  - `test_xlsx_layout`: `Excel::fake()`; `formato=xlsx` → `Excel::assertDownloaded('relatorio-concluidos-analista.xlsx', fn (RelatorioTabelaExport $e) => ...)` verificando linha 1 = título, linha 2 começa com `"Gerado em "`, linha 4 = `['Analista', 'Total', '%']`, penúltima `['Total', N, 100.0]` e a linha de distintos presente quando difere.

- [ ] **Step 2: Run** `--filter=RelatorioConsultaSaidaTest` — Expected: FAIL.

- [ ] **Step 3: Implement.** Nomes de filtros resolvidos com `RotulosRelatorio`; datas com `Carbon::createFromFormat('Y-m-d', ...)->format('d/m/Y')`.

- [ ] **Step 4: Run** a classe e a suíte inteira — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(relatorios): report title sentence and self-describing spreadsheet`

---

### Task 5: Lista de chamados por linha/célula

**Files:**
- Create: `app/Relatorios/ListaChamados.php`, `app/Exports/RelatorioChamadosExport.php`
- Modify: `app/Http/Controllers/Api/RelatorioConsultaController.php` (método `chamados(Request $r)`), `routes/api.php` (`GET /relatorios/chamados`, middleware `auth:web` + `can:relatorios.view`, mesmo padrão das rotas vizinhas)
- Modify: `BACKEND_SPECS.md` §3.4.9 (endpoint e regras de linha)
- Test: `tests/Feature/Relatorios/RelatorioChamadosTest.php` (novo)

**Interfaces:**
- Consumes: `ConsultaRelatorio::regras()/deDados()`, `fonte()->linhas()`, `PeriodoRelatorio::colunaDe`, `TituloRelatorio::montar`, `RotulosRelatorio`.
- Produces: `GET /api/relatorios/chamados` — mesmos parâmetros da consulta + `chave` (obrigatório, string; `"__vazio__"` = chave nula) + `coluna` (opcional, chave de coluna; ausente = todas) + `page`/`per_page` (padrão 50, máx 200) + `formato` (`json`|`xlsx`). Resposta JSON: `{titulo, linha: {chave, rotulo}, coluna: {chave, rotulo}|null, data: [{incidente_id, titulo, status, data, autor: {id, name}|null, grupo_chamado: {id, nome}|null, destino: string|null, acessivel: bool}], meta: {current_page, per_page, total, last_page}}`.
  - Uma linha por **chamado** (data/autor do evento mais recente no escopo) para todos os `contar` exceto `encaminhamentos`, que tem uma linha por **evento** com `destino` = nome do grupo/responsável de destino.
  - `acessivel` = chamado visível hoje pelo `Incidente::visiveisPara`.
  - Ordem: `data` desc.
  - xlsx: `relatorio-chamados.xlsx`; linha 1 título; linha 2 `"Linha: {rótulo}"` (+ `" · {rótulo da coluna}"`); linha 3 `"Gerado em …"`; linha 4 vazia; cabeçalho `["Nº", "Título", "Status", "Data", "Analista", "Grupo do chamado"]` (+ `"Destino"` em encaminhamentos); datas em Brasília `dd/mm/aaaa hh:mm`.

- [ ] **Step 1: Write the failing tests** (cada um compara com o número da consulta — Review Focus 4):
  - `test_list_matches_cell_for_concluidos`: A resolve e fecha o chamado 1 → consulta A = 1; lista `chave={A}` → 1 item, `data` = data do `fechado`.
  - `test_list_matches_event_count_for_encaminhamentos`: 3 encaminhamentos de C em 2 chamados → lista tem 3 itens, cada um com `destino`.
  - `test_list_filters_by_column`: quebra semanal, A resolve na semana 1 e fecha na semana 2 → `coluna={semana1}` 1 item; sem `coluna` 1 item (mesmo chamado).
  - `test_list_marks_tickets_outside_visible_groups`: cenário da Task 3 (gestor N2, chamado hoje no N3) → item com `acessivel: false`. (Review Focus 5)
  - `test_list_vazio_key`: chamado sem item, `agrupar_por=item&chave=__vazio__` → 1 item.
  - `test_list_xlsx`: `Excel::fake()` + `assertDownloaded('relatorio-chamados.xlsx', …)` com cabeçalho exato.

- [ ] **Step 2: Run** `--filter=RelatorioChamadosTest` — Expected: FAIL.

- [ ] **Step 3: Implement.** `ListaChamados` filtra as `LinhaBase` da fonte por `chave` (e `coluna`), agrupa por `incidenteId` (ou não agrupa em `encaminhamentos`), pagina com `LengthAwarePaginator` e carrega `incidentes` (`titulo`, `status`, `grupoSolucao`) e autores numa query cada.

- [ ] **Step 4: Run** a classe e a suíte inteira — Expected: PASS.

- [ ] **Step 5: Commit** — `feat(relatorios): ticket list behind each report row/cell, json and xlsx`

---

### Task 6: Relatórios salvos no formato novo

**Files:**
- Create: `database/migrations/2026_11_11_000000_add_contar_and_quebra_to_relatorios_salvos_table.php` (`contar` string nullable, `quebra` string nullable)
- Modify: `app/Models/RelatorioSalvo.php` (fillable; `const EQUIVALENTES_LEGADO`; `consultaEquivalente(): ?array`)
- Modify: `app/Http/Controllers/Api/RelatorioSalvoController.php` (`validated()`, `executar()`), `app/Http/Resources/RelatorioSalvoResource.php`
- Modify: `BACKEND_SPECS.md` §3.1 `relatorios_salvos` e §3.4.9 (salvos: atalhos, legado)
- Test: `tests/Feature/Relatorios/RelatorioSalvoCrudTest.php`

**Interfaces:**
- Consumes: `ConsultaRelatorio::regras()/deDados()`, `RelatorioConsultaController::responder()`.
- Produces:
  - Store/update: com `contar` presente, valida `contar`, `agrupar_por` (matriz nova), `quebra` e `filtros.*` com `ConsultaRelatorio::regras()` (prefixo `filtros.` para os filtros, incluindo `filtros.periodo`); sem `contar`, validação legada atual.
  - `RelatorioSalvo::EQUIVALENTES_LEGADO` = `resolvido_por → [resolvidos, analista]`, `fechado_por → [fechados, analista]`, `concluido_por → [concluidos, analista]`, `encaminhado_por → [encaminhamentos, quem_encaminhou]`, `encaminhado_para_grupo → [encaminhamentos, para_grupo]`, `encaminhado_para_responsavel → [encaminhamentos, para_responsavel]`, `aberto_por → [abertos, aberto_por]` **somente quando `filtros` não tem `data_inicio` nem `data_fim`** (com datas, o legado filtra por `concluido_em` e não é equivalente — fica legado).
  - Resource ganha `contar`, `quebra` (armazenados ou do equivalente) e `formato_antigo: bool` (`true` quando `contar` nulo e sem equivalente).
  - `executar()`: `contar` armazenado ou equivalente → caminho novo, com `periodo` re-resolvido na hora da execução; senão → legado como hoje.

- [ ] **Step 1: Write the failing tests** (em `RelatorioSalvoCrudTest`):
  - `test_saves_new_shape_with_period_shortcut`: POST `{nome, contar: 'abertos', agrupar_por: 'categoria', quebra: 'mes', filtros: {periodo: 'mes_passado'}}` → 201 com os campos; `agrupar_por: 'analista'` com `contar: 'abertos'` → 422.
  - `test_saved_shortcut_is_relative_to_execution_date`: salvo com `periodo=mes_passado`; `travelTo('2026-11-05')` → `executar` `titulo` contém `"de 01/10/2026 a 31/10/2026"`; `travelTo('2026-12-05')` → `"de 01/11/2026 a 30/11/2026"`. (Review Focus 3)
  - `test_legacy_equivalent_runs_new_path_and_is_not_old_format`: legado `fechado_por` sem `contar` → resource `contar: 'fechados'`, `formato_antigo: false`; `executar` devolve o formato novo.
  - `test_legacy_without_equivalent_stays_legacy`: legado `categoria` → `formato_antigo: true`; `executar` devolve `{agrupado_por, data}`. Legado `aberto_por` com `data_inicio` → `formato_antigo: true`.

- [ ] **Step 2: Run** `--filter=RelatorioSalvoCrudTest` — Expected: FAIL.

- [ ] **Step 3: Implement** migration, model, controller e resource.

- [ ] **Step 4: Run** a classe e a suíte inteira — Expected: PASS. Rode `docker compose exec -T app php artisan migrate` (dev) e confirme sucesso.

- [ ] **Step 5: Commit** — `feat(relatorios): saved reports keep contar, breakdown and relative periods`

---

### Task 7: Frontend — painel novo, tabela, gráfico e exportações

**Repo:** `/home/daniel/Workspace/gespriority_claude_front`

**Files:**
- Modify: `src/views/ReportsView.vue`, `src/services/reportService.js`
- Create: `src/components/reports/ReportResultTable.vue`

**Interfaces:**
- Consumes: resposta JSON do caminho novo (Global Constraints), `formato=xlsx` (Task 4).
- Produces: `ReportResultTable` com props `resultado` (objeto da API) e evento `abrir-chamados` com payload `{ chave, coluna }` (`coluna` `null` ao clicar no rótulo da linha) — consumido pela Task 8.

- [ ] **Step 1: Implement** (layout da spec 5.1, opção A):
  - Painel na ordem: "O que contar" (`v-btn-toggle` obrigatório, rótulos Abertos, Concluídos, Resolvidos, Fechados, Cancelados, Encaminhamentos); "Agrupar por" (opções da matriz das Global Constraints, com os rótulos de agrupamento do título capitalizados; trocar `contar` limpa `agrupar_por` se ficar inválido); "Quebrar por período" (Não, Dia, Semana, Mês); "Período" com atalhos (Este mês, Mês passado, Últimos 7 dias, Personalizado — este último mostra as duas datas) e rótulo `"Período — data da abertura"` / `"data da conclusão"` (concluidos/resolvidos/fechados → `"data da conclusão"`; cancelados → `"data do cancelamento"`; encaminhamentos → `"data do encaminhamento"`); demais filtros como hoje (grupo do analista, grupo do chamado, cliente, usuário do cliente, classificação, tipo, responsável, status).
  - Aviso local antes de enviar quando a quebra excede o limite (62/53/24 colunas), com a mesma mensagem do backend.
  - Resultado: `titulo` acima; gráfico (colunas/barras/pizza; com quebra: barras agrupadas, um dataset por coluna de período, pizza desabilitada); `ReportResultTable` abaixo com colunas de período (se houver), Total, %, linha Total e rodapé "Chamados distintos no resultado: N" quando `chamados_distintos ≠ totais.total`. Linhas e células clicáveis emitem `abrir-chamados`.
  - "Exportar imagem": título do gráfico (`plugins.title.text`) = `resultado.titulo` quebrado em linhas de até ~90 caracteres; mantém fundo branco da Fase 1.
  - "Baixar planilha": `formato=xlsx` com os mesmos parâmetros; nome do arquivo vindo do backend (`relatorio-{contar}-{agrupar_por}.xlsx`).
  - Remova o seletor "Agrupar por" legado e o toggle Gráfico/Planilha; o resultado mostra sempre gráfico + tabela.

- [ ] **Step 2: Verify** — `npm run build` (Node 22) sem erros. Checagem manual (anote no relatório o que foi feito e o que ficou para o humano): `Concluídos` + Analista + Mês passado + Grupo do analista → tabela com zerados e total; `Abertos` + Categoria + Mês + período de 3 meses → tabela cruzada e barras agrupadas; imagem e planilha com título.

- [ ] **Step 3: Commit** — `feat(relatorios): contar-based report screen with table, totals and self-describing exports`

---

### Task 8: Frontend — lista de chamados e relatórios salvos

**Repo:** `/home/daniel/Workspace/gespriority_claude_front`

**Files:**
- Create: `src/components/reports/ReportTicketListDialog.vue`
- Modify: `src/views/ReportsView.vue`, `src/services/reportService.js`

**Interfaces:**
- Consumes: evento `abrir-chamados` (Task 7); `GET /relatorios/chamados` (Task 5); resource de salvos com `contar`, `quebra`, `formato_antigo` (Task 6).

- [ ] **Step 1: Implement**:
  - `ReportTicketListDialog`: abre com os parâmetros da consulta atual + `chave`/`coluna`; mostra `titulo` e `"Linha: {rótulo}"` (+ coluna); tabela paginada (50 por página) com Nº (link `router-link` para `/incidents/:id` quando `acessivel`; sem link e com chip "fora dos seus grupos" quando não), Título, Status (rótulos de `STATUS_LABELS`), Data (pt-BR, `America/Sao_Paulo`), Analista, Grupo do chamado, e Destino em encaminhamentos; botão "Baixar lista" (`formato=xlsx`).
  - Salvar relatório: envia `contar`, `agrupar_por`, `quebra` e `filtros` (com `periodo` quando um atalho está selecionado, ou `data_inicio`/`data_fim` em Personalizado).
  - Clique num relatório salvo: carrega os filtros e **executa** (`/relatorios-salvos/{id}/executar`). Com `formato_antigo`, mostra chip "formato antigo" na lista e renderiza a resposta legada `{agrupado_por, data}` como tabela simples (rótulo, total) com o gráfico — sem lista de chamados, sem quebra; ao salvar de novo pela tela, grava no formato novo.

- [ ] **Step 2: Verify** — `npm run build` (Node 22). Checagem manual: clicar numa linha abre lista com o mesmo número de itens da célula; "Baixar lista" baixa xlsx; salvar "Mês passado" e reabrir executa; salvo legado aparece com o chip.

- [ ] **Step 3: Commit** — `feat(relatorios): ticket list dialog and saved reports in the new format`
