# Relatórios — Indicadores por grupo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Todo o ciclo de vida do chamado (abertura, resolução, fechamento, cancelamento, reabertura) registra quem agiu e o grupo dessa pessoa no momento, e uma tela nova "Indicadores por grupo" mostra Abertos | Resolvidos | Fechados | Cancelados por grupo (ou por integrante), com uma definição única por coluna.

**Architecture:** `incidente_eventos` ganha os tipos `aberto`, `cancelado` e `reaberto`, autor cliente (`autor_customer_id`) e a marca `retroativo` para dados reconstruídos. Um hook em `Incidente::saved` grava o `aberto` em qualquer caminho que defina o criador (controllers, portal, seeders). O `IncidenteController` grava `cancelado` e `reaberto` junto com `resolvido` e `fechado`. Uma migration reconstrói o histórico. O painel é um endpoint novo, `GET /api/relatorios/indicadores`, em `app/Relatorios/Indicadores/`, que lê **só eventos** e agrupa por `autor_grupo_solucao_id` (ou `user_id` no detalhamento). As células usam as mesmas `LinhaBase` da lista de chamados, então a lista sempre bate com o número. O relatório da Fase 2 (aba "Avançado") não muda.

**Tech Stack:** Laravel 12 / PHPUnit / maatwebsite/excel (backend `gespriority_claude`; testes em SQLite em memória, produção em PostgreSQL); Vue 3 + Vuetify 3 + Chart.js + chartjs-plugin-datalabels (frontend `gespriority_claude_front`, sem suíte de testes, build com Node 22).

**Spec:** `docs/superpowers/specs/2026-11-12-relatorios-indicadores-por-grupo-design.md` (aprovada em 12/11/2026). Leia-a inteira antes da Task 1. A spec da Fase 2 (`docs/superpowers/specs/2026-11-09-relatorios-fase-2-design.md`, seções 5.3, 6A.4 e 6A.5) define as regras reaproveitadas aqui.

**Decisão de implementação (desvio consciente da spec 6.3):** a spec previa duas datas em configuração para o aviso de "dados aproximados". O plano usa uma coluna `incidente_eventos.retroativo` (bool) no lugar delas. O aviso aparece quando **algum evento contado no resultado** é retroativo e informa as colunas afetadas e a data do último evento retroativo contado. É a mesma intenção da spec (nunca mostrar como exato o que é aproximado), sem depender de alguém acertar uma data em `.env`.

## Global Constraints

- **Tipos de evento:** `aberto`, `resolvido`, `fechado`, `cancelado`, `reaberto`, `encaminhado_grupo`, `encaminhado_responsavel`. Constantes em `IncidenteEvento` (`TIPO_ABERTO`, `TIPO_CANCELADO`, `TIPO_REABERTO`).
- **Indicadores e ordem fixa das colunas:** `abertos` → `aberto`, `resolvidos` → `resolvido`, `fechados` → `fechado`, `cancelados` → `cancelado`. Rótulos: "Abertos", "Resolvidos", "Fechados", "Cancelados".
- **Grupo de um número:** sempre `incidente_eventos.autor_grupo_solucao_id`. Nunca o grupo atual do chamado nem o grupo atual da pessoa.
- **Contagem por célula:** chamados distintos (`incidente_id`) por linha × indicador × coluna de período. Total da linha = soma das células; total da coluna = soma das linhas; rodapé `distintos[indicador]` = `count(distinct incidente_id)` do indicador no resultado.
- **Linhas especiais** (chave → rótulo):
  - `portal` → "Cliente (portal)": eventos `aberto` com `autor_customer_id` não nulo. Só tem valor em Abertos (as outras colunas ficam 0). Aparece sempre, logo acima do Total.
  - `__sem_autor__` → "Sem autor registrado": eventos com `user_id` e `autor_customer_id` nulos, **ou** com `user_id` não nulo e `autor_grupo_solucao_id` nulo (grupo excluído). Só aparece sem seleção de grupos, fora do detalhamento por integrante, e quando não está zerada.
- **Linhas de grupo:**
  - Com `grupos` informados: exatamente esses grupos, mesmo zerados.
  - Sem `grupos`: grupos visíveis com `ativo = true` + qualquer grupo visível que tenha evento contado no período.
  - Ordenação: total geral da linha desc, depois rótulo (mesmo collator do `AgregadorRelatorio::ordenar`). `portal` e `__sem_autor__` sempre por último, nessa ordem.
- **Detalhamento por integrante:** `por_integrante=1` exige exatamente um grupo (422 em `por_integrante`: `"Selecione um único grupo para detalhar por integrante."`). As linhas são os usuários (`withTrashed`) com evento cujo `autor_grupo_solucao_id` é o grupo, mais os usuários não excluídos com `users.grupo_solucao_id` = o grupo (zerados). Rótulo = nome; para excluídos, nome + " (excluído)". A linha `portal` continua presente.
- **Filtro da linha `portal` com grupos:** com `grupos` (ou `por_integrante`), conta só eventos `aberto` do portal cujo `incidente_eventos.grupo_solucao_id` ∈ grupos. Sem `grupos`, conta todos os eventos de portal visíveis.
- **Visibilidade:** admin (`visibleGrupoSolucaoIds() === null`) vê tudo. Para os demais:
  - eventos de agente contam se `autor_grupo_solucao_id` ∈ visíveis;
  - `portal` e `__sem_autor__` contam se o chamado é visível hoje (`Incidente::visiveisPara`);
  - um grupo em `grupos` fora dos visíveis → 422 em `grupos.N`: `"Grupo fora dos seus grupos visíveis."`.
- **Período obrigatório:** `periodo` (atalho) **ou** `data_inicio` + `data_fim`, com as mesmas regras, atalhos, limites de quebra e mensagens de `ConsultaRelatorio::regras()`. Datas em dias de Brasília via `PeriodoRelatorio::inicioUtc` / `fimExclusivoUtc`, nunca `whereDate`. Sem período → 422 em `periodo`: `"Informe um período."`.
- **Título:** `"Indicadores por grupo de solução — de dd/mm/aaaa a dd/mm/aaaa[, por {dia|semana|mês}][ — grupos: N1, N2][ — por integrante]"`. Por exemplo: `"Indicadores por grupo de solução — de 01/10/2026 a 31/10/2026 — grupos: N1, N2"`.
- **Legenda (texto fixo, na tela e na planilha):**
  1. `Abertos: chamados criados no período, contados no grupo de quem abriu no momento da abertura. Chamados abertos pelo portal ficam na linha "Cliente (portal)".`
  2. `Resolvidos: chamados com ao menos uma resolução no período, contados no grupo de quem resolveu no momento.`
  3. `Fechados: chamados com ao menos um fechamento no período, contados no grupo de quem fechou no momento.`
  4. `Cancelados: chamados cancelados no período, contados no grupo de quem cancelou no momento.`
  5. `As colunas são independentes: um chamado resolvido e fechado no período conta em Resolvidos e em Fechados. Um chamado resolvido por dois grupos conta uma vez em cada um.`
- **Aviso:** quando algum evento contado tem `retroativo = true`, o texto é `"Dados aproximados em {colunas, ex.: Abertos e Cancelados}: registros até dd/mm/aaaa foram reconstruídos e usam o grupo atual da pessoa, não o da época."`. A data é a do `created_at` (Brasília) mais recente entre os retroativos contados. Sem retroativos → `null`.
- `GET /api/relatorios/incidentes` (legado e caminho `contar`) e todos os testes atuais continuam passando sem mudança de comportamento. Exceção: testes que contam **todos** os eventos de um chamado passam a filtrar por tipo (Task 1).
- **Sem dependências novas.**
- **Branches:** backend na branch `feat/relatorios-indicadores`, in-place (o container monta a raiz do repo). Frontend num worktree novo em `../gespriority_claude_front-indicadores`, na branch de mesmo nome: o checkout principal do front é compartilhado com outras sessões. **Nunca dar push** (push na `main` = produção).
- O working tree do backend tem mudanças que não são desta entrega (`database/seeders/CategoriasSeeder.php`, `database/seeders/IncidentesSeeder.php`, `infra/terraform/...`). Nunca adicioná-las a um commit: use `git add <arquivos>` explícito.
- **Testes do backend:** `docker compose exec -T app php artisan test --filter=<Classe>`, e a suíte inteira antes de cada commit. **Front:** `npm run build` com Node 22.
- **Documentação:** cada task de backend atualiza o `BACKEND_SPECS.md` (§3.1 para tabelas e models; §3.4.9 para relatórios, numa subseção nova "Indicadores por grupo"), descrevendo o código como ficou.
- **Commits:** um por task, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Agente que troca de grupo depois de agir:** o agente do N1 abre o chamado em 05/10 e passa para o N2 em 20/10. O relatório de outubro conta a abertura no N1, e no detalhamento do N1 ele aparece com 1. (Task 1, Task 4)
2. **Portal com filtro de grupo:** chamado aberto pelo portal com destino N1, e outro sem grupo. Com `grupos=[N1]`, a linha "Cliente (portal)" mostra 1; sem `grupos`, mostra 2. (Task 4)
3. **Reabertura:** chamado resolvido pelo N1 (01/10), reaberto e resolvido pelo N2 (15/10). Em outubro: Resolvidos N1=1, N2=1, total 2, distintos 1. Com quebra por semana, cada um cai na sua semana. (Task 2, Task 4)
4. **Fronteira de dia:** chamado aberto em 31/10 23:30 BRT (01/11 02:30 UTC) conta em outubro, não em novembro. (Task 4)
5. **Gestor não-admin do N2:** a resolução feita por um agente do N2 num chamado que hoje está no N3 aparece no número e na lista com `acessivel: false`. Enviar `grupos=[N3]` devolve 422. (Task 4, Task 5)

---

### Task 1: Eventos `aberto`, autor cliente e marca `retroativo`

**Files:**
- Create: `database/migrations/2026_11_12_100000_add_autoria_completa_to_incidente_eventos_table.php`
- Modify: `app/Models/IncidenteEvento.php`, `app/Models/Incidente.php`, `BACKEND_SPECS.md`
- Modify (ajuste de contagem): `tests/Feature/Incidentes/IncidenteCrudTest.php` (asserções `IncidenteEvento::where('incidente_id', …)->count()`, por volta das linhas 672, 694 e 708; e qualquer outra que quebrar pelo mesmo motivo)
- Test: `tests/Feature/Relatorios/IncidenteEventoAberturaTest.php` (novo)

**Interfaces:**
- Produces:
  - Colunas em `incidente_eventos`: `user_id` passa a ser anulável (a FK continua); `autor_customer_id` (FK `customers`, anulável, `nullOnDelete`, com índice); `retroativo` (boolean, default `false`). Índice composto `(tipo, created_at)`.
  - `IncidenteEvento::TIPO_ABERTO = 'aberto'`, `TIPO_CANCELADO = 'cancelado'`, `TIPO_REABERTO = 'reaberto'`, incluídos em `TIPOS`. `autor_customer_id` e `retroativo` entram no `#[Fillable]`.
  - Hook `saved` em `Incidente`: quando `criado_por_id` ou `criado_por_customer_id` passa de nulo para preenchido (`wasChanged`) e o chamado ainda não tem evento `aberto`, cria um evento `aberto` com:
    - `user_id = criado_por_id`, `autor_customer_id = criado_por_customer_id`;
    - `grupo_solucao_id = $incidente->grupo_solucao_id`;
    - `created_at = $incidente->created_at`.
  - O `creating` existente em `IncidenteEvento` já preenche `autor_grupo_solucao_id` a partir de `user_id` (nulo para cliente). Isso cobre o `store()` interno, o `store()` do portal e o `IncidentesSeeder` sem tocar neles.

- [ ] **Step 1: Criar a branch `feat/relatorios-indicadores` a partir da `main`**, sem levar as mudanças locais não relacionadas para nenhum commit.

- [ ] **Step 2: Escrever os testes que falham** em `IncidenteEventoAberturaTest`:
  - `test_store_interno_grava_evento_aberto_com_grupo_do_autor`: agente do N1 faz `POST /api/incidentes` com `grupo_solucao_id` = N2. Existe 1 evento `aberto` com `user_id` = agente, `autor_grupo_solucao_id` = N1, `grupo_solucao_id` = N2, `autor_customer_id` null, `retroativo` false, e `created_at` igual ao do chamado.
  - `test_portal_grava_evento_aberto_com_autor_cliente`: `POST` no portal (mesma rota e autenticação de `PortalIncidenteTest`). O evento `aberto` tem `user_id` null, `autor_customer_id` = cliente e `autor_grupo_solucao_id` null.
  - `test_trocar_de_grupo_depois_nao_altera_o_evento`: depois do store, o agente passa para o N2. O evento continua com `autor_grupo_solucao_id` = N1.
  - `test_salvar_de_novo_nao_duplica_aberto`: update no chamado (título) mantém 1 evento `aberto`.
  - `test_incidente_sem_criador_nao_gera_aberto`: `Incidente::factory()->create()` não gera evento.

- [ ] **Step 3: Rodar e ver falhar**
  Run: `docker compose exec -T app php artisan test --filter=IncidenteEventoAberturaTest`
  Expected: FAIL (coluna ou tipo inexistente).

- [ ] **Step 4: Implementar** a migration (`->nullable()->change()` em `user_id`; `down()` desfaz na ordem inversa), as constantes e o hook. O hook usa `static::saved` em `Incidente::booted()`, com o `exists()` só quando um campo de criador mudou.

- [ ] **Step 5: Ajustar os testes antigos** que contam todos os eventos do chamado, filtrando por `whereIn('tipo', [...])` com os tipos que cada teste pretende medir.

- [ ] **Step 6: Rodar a suíte inteira**
  Run: `docker compose exec -T app php artisan test`
  Expected: PASS.

- [ ] **Step 7: Atualizar o `BACKEND_SPECS.md` §3.1 (`incidente_eventos`) e fazer o commit** `feat(eventos): record opening author and group on incidente_eventos`.

### Task 2: Eventos `cancelado` e `reaberto` nas transições de status

**Files:**
- Modify: `app/Http/Controllers/Api/IncidenteController.php` (`registrarEventoDeConclusaoSeAplicavel`, linhas ~338-350; renomear para `registrarEventoDeStatus` e ajustar a docblock), `BACKEND_SPECS.md`
- Test: `tests/Feature/Relatorios/IncidenteEventoStatusTest.php` (novo)

**Interfaces:**
- Consumes: as constantes da Task 1.
- Produces: na transição `$anterior → $novo`, com `$anterior !== $novo`:
  - `$novo` ∈ {`resolvido`, `fechado`, `cancelado`} → evento com `tipo = $novo`;
  - `$anterior` ∈ `Incidente::STATUS_CONCLUIDOS` e `$novo` ∉ `STATUS_CONCLUIDOS` → evento `reaberto`.
  - Autor = usuário autenticado. Uma mesma transição nunca gera dois eventos (`resolvido → fechado` gera só `fechado`).

- [ ] **Step 1: Escrever os testes que falham:**
  - `test_cancelar_grava_evento_cancelado_com_grupo_do_autor`: um agente do N2 cancela um chamado do N1. Gera o evento `cancelado` com `autor_grupo_solucao_id` = N2 e `grupo_solucao_id` = N1.
  - `test_reabrir_grava_reaberto`: `resolvido → em_andamento` gera `reaberto`; `cancelado → aberto` gera `reaberto`.
  - `test_resolvido_para_fechado_gera_so_fechado`.
  - `test_status_igual_nao_gera_evento`: update com o mesmo `cancelado` não gera nada.
  - `test_ciclo_completo`: `aberto → resolvido → em_andamento → resolvido → fechado` gera os tipos `[aberto, resolvido, reaberto, resolvido, fechado]`, nessa ordem de `id`.

- [ ] **Step 2: Rodar e ver falhar**
  Run: `docker compose exec -T app php artisan test --filter=IncidenteEventoStatusTest`
  Expected: FAIL.

- [ ] **Step 3: Implementar** a generalização no controller.

- [ ] **Step 4: Rodar a suíte inteira**
  Expected: PASS. Os relatórios legados e a Fase 2 filtram por tipo, então os novos tipos não vazam para eles. Se algum teste vazar, a correção é filtrar por tipo na fonte, nunca mudar a expectativa.

- [ ] **Step 5: Atualizar o `BACKEND_SPECS.md` e fazer o commit** `feat(eventos): record cancel and reopen events with author group`.

### Task 3: Reconstrução do histórico (backfill)

**Files:**
- Create: `database/migrations/2026_11_12_100001_backfill_eventos_abertura_cancelamento.php`
- Test: `tests/Feature/Relatorios/BackfillEventosTest.php` (novo; roda o `up()` da migration contra dados montados no teste, como `IncidenteEventoSnapshotTest` faz com a migration da Fase 1)

**Interfaces:**
- Consumes: as colunas da Task 1.
- Produces: eventos com `retroativo = true`, só com query builder (sem models nem constantes da aplicação), em chunks de 500. A migration é idempotente: não cria `aberto` se o chamado já tiver um.

| Gera | Fonte | `user_id` / `autor_customer_id` | `autor_grupo_solucao_id` | `grupo_solucao_id` | `created_at` |
|---|---|---|---|---|---|
| `aberto` | todo incidente sem `aberto` | `criado_por_id` / `criado_por_customer_id` (os dois nulos = desconhecido) | grupo atual de `users` (null para cliente/desconhecido) | grupo atual do incidente se nunca houve `encaminhado_grupo`; senão `null` (grupo inicial desconhecido) | `incidentes.created_at` |
| `cancelado` / `reaberto` | `incidente_descricoes` com `tipo` = `TIPO_ALTERACAO` e `descricao` casando com `/^Campo 'Status' alterado de '([a-z_]+)' para '([a-z_]+)'/` | `incidente_descricoes.user_id` | grupo atual do usuário | mesma regra do backfill da Fase 1 (destino do `encaminhado_grupo` mais recente até a data; senão o grupo atual, se não houve encaminhamento depois; senão null) | `incidente_descricoes.created_at` |
| `cancelado` sem log | incidente com `status = cancelado` sem `cancelado` gerado acima | null | null | grupo atual do incidente | `concluido_em`, ou `updated_at` se nulo |

  - Também marca `retroativo = true` nos eventos `resolvido`, `fechado`, `encaminhado_*` já existentes com `created_at` anterior ao deploy da Fase 1. Esse instante vira constante na migration e é obtido no Step 1.

- [ ] **Step 1: Obter o instante do deploy da Fase 1:** o `createdAt` do primeiro run do workflow de deploy na `main` cujo `headSha` contém o commit `619dad0`. Use `gh run list --branch main --json createdAt,headSha,workflowName --limit 100` e `git merge-base --is-ancestor 619dad0 <sha>`. Anote o valor UTC no plano (aqui) e na docblock da migration. Se não for possível obter, pare e pergunte.

- [ ] **Step 2: Escrever os testes que falham:**
  - `test_cria_aberto_para_incidente_de_agente_com_grupo_atual_e_retroativo`
  - `test_cria_aberto_do_portal_com_autor_cliente`
  - `test_incidente_sem_criador_gera_aberto_sem_autor`
  - `test_aberto_com_encaminhamento_posterior_fica_sem_grupo_inicial`: `grupo_solucao_id` null.
  - `test_cancelado_e_reaberto_vem_do_log_de_status_com_autor_do_log`
  - `test_cancelado_sem_log_usa_concluido_em_e_autor_nulo`
  - `test_marca_eventos_anteriores_a_fase_1_como_retroativos`: um evento 1s antes da constante fica `true`; um evento 1s depois fica `false`.
  - `test_rodar_duas_vezes_nao_duplica`

- [ ] **Step 3: Rodar e ver falhar; implementar; rodar a suíte inteira**
  Expected: PASS.

- [ ] **Step 4: Commit** `feat(eventos): backfill opening, cancel and reopen history as retroactive`.

### Task 4: Endpoint de indicadores (JSON)

**Files:**
- Create: `app/Relatorios/Indicadores/ConsultaIndicadores.php`, `app/Relatorios/Indicadores/FonteIndicadores.php`, `app/Relatorios/Indicadores/AgregadorIndicadores.php`, `app/Relatorios/Indicadores/TextosIndicadores.php` (título, legenda e aviso), `app/Http/Controllers/Api/IndicadoresController.php`
- Modify: `routes/api.php` (junto das rotas de relatório, com `can:relatorios.view`), `BACKEND_SPECS.md`
- Test: `tests/Feature/Relatorios/IndicadoresTest.php` (novo)

**Interfaces:**
- Consumes: `PeriodoRelatorio` (`resolverAtalho`, `colunas`, `colunaDe`, `inicioUtc`, `fimExclusivoUtc`, `ATALHOS`, `QUEBRAS`, `LIMITE_COLUNAS`); `LinhaBase`; `RotulosRelatorio::grupos()` e `::usuarios()`.
- Produces:
  - `ConsultaIndicadores` (`final readonly`):
    - props `array $grupos` (ids int, vazio = todos), `bool $porIntegrante`, `string $quebra`, `string $inicio`, `string $fim`, `?string $atalho`;
    - `static regras(): array`, com as chaves `grupos` (array), `grupos.*`, `por_integrante` (boolean), `quebra`, `periodo`, `data_inicio`, `data_fim`. Reaproveita as regras de período e quebra de `ConsultaRelatorio`; a regra cruzada da quebra precisa ser exposta como `public static` lá, sem mudar o comportamento;
    - `static deDados(array $validado, ?CarbonInterface $agora = null): self`;
    - `validarVisibilidade(User $u): void`, que lança `ValidationException` com as mensagens das Global Constraints.
  - `FonteIndicadores::linhas(ConsultaIndicadores $c, User $u): Collection<int, LinhaIndicador>`. `LinhaIndicador` (`final readonly`, no mesmo diretório) carrega `string $indicador` (`abertos|resolvidos|fechados|cancelados`), `int|string $chave` (id do grupo, id do usuário, `'portal'` ou `'__sem_autor__'`), `bool $retroativo` e `LinhaBase $base`. Aplica tipo, período, visibilidade e as regras de `portal` e `__sem_autor__` das Global Constraints, numa query por indicador ou numa única query com `whereIn('tipo')`, sem N+1.
  - `AgregadorIndicadores::agregar(ConsultaIndicadores $c, Collection $linhas, User $u): array`, que devolve o corpo JSON abaixo.
  - `GET /api/relatorios/indicadores`, resposta 200:
    ```json
    {
      "titulo": "...", "periodo": {"inicio": "Y-m-d", "fim": "Y-m-d"}, "quebra": "nenhuma",
      "grupos": [1, 2], "por_integrante": false,
      "indicadores": [{"chave": "abertos", "rotulo": "Abertos"}, ...],
      "colunas": [{"chave": "total", "rotulo": "Total"}],
      "linhas": [{"chave": "1", "rotulo": "N1", "tipo": "grupo|usuario|portal|sem_autor",
                  "valores": {"abertos": {"celulas": {"<col>": 0}, "total": 0}, "resolvidos": {...}, "fechados": {...}, "cancelados": {...}}}],
      "totais": {"abertos": {"celulas": {...}, "total": 0}, ...},
      "distintos": {"abertos": 0, "resolvidos": 0, "fechados": 0, "cancelados": 0},
      "legenda": ["...5 textos..."], "aviso": null
    }
    ```
    Sem quebra, `colunas` = `[{chave: "total", rotulo: "Total"}]`, como na Fase 2. Chaves de linha sempre string.

- [ ] **Step 1: Escrever os testes que falham.** Monte os eventos diretamente com `IncidenteEvento::query()->create([...])`, com datas e grupos explícitos, e um gestor não-admin do N2 com `relatorios.view`:
  - `test_exige_periodo`: 422 em `periodo` com `"Informe um período."`.
  - `test_conta_cada_indicador_pelo_grupo_do_autor_no_momento`: no período, N1 abre 2, N2 resolve 1 aberto pelo N1, N1 fecha 1 e N2 cancela 1. Esperado: N1 `{abertos: 2, resolvidos: 0, fechados: 1, cancelados: 0}` e N2 `{0, 1, 0, 1}`.
  - `test_abertura_na_fronteira_do_dia_conta_no_mes_de_brasilia` (Review Focus 4).
  - `test_resolucao_por_dois_grupos_conta_uma_vez_em_cada` (Review Focus 3): totais de resolvidos 2 e `distintos.resolvidos` 1. Com `quebra=semana`, as resoluções ficam nas colunas `2026-09-28` e `2026-10-12` (semanas começam na segunda; 01/10/2026 é quinta).
  - `test_mesmo_grupo_resolve_duas_vezes_conta_uma`.
  - `test_portal_aparece_separado_e_filtra_por_destino_com_grupos` (Review Focus 2): a linha `portal` tem `tipo` `portal`, aparece antes do total e tem 0 nas outras colunas.
  - `test_sem_grupos_lista_ativos_visiveis_mais_inativos_com_evento`: um grupo inativo sem evento não aparece; um grupo inativo com evento aparece.
  - `test_grupos_selecionados_aparecem_zerados`.
  - `test_sem_autor_so_aparece_sem_selecao_e_quando_nao_zerada`.
  - `test_por_integrante_exige_um_grupo`: 422 com a mensagem exata.
  - `test_por_integrante_inclui_quem_agiu_e_saiu_do_grupo_e_membros_zerados` (Review Focus 1): um usuário excluído aparece com o rótulo `"Nome (excluído)"`.
  - `test_gestor_nao_admin_conta_acao_do_seu_grupo_em_chamado_de_outro_grupo` (Review Focus 5, parte do número).
  - `test_grupo_fora_dos_visiveis_e_422`.
  - `test_aviso_lista_colunas_com_retroativos_e_data_mais_recente`: retroativos só em `aberto`, o mais recente em 10/10/2026, dão `"Dados aproximados em Abertos: registros até 10/10/2026 foram reconstruídos e usam o grupo atual da pessoa, não o da época."`; sem retroativos, `null`.
  - `test_titulo_e_legenda`: as strings exatas das Global Constraints.

- [ ] **Step 2: Rodar e ver falhar**
  Run: `docker compose exec -T app php artisan test --filter=IndicadoresTest`
  Expected: FAIL (rota inexistente).

- [ ] **Step 3: Implementar** as classes e a rota. As colunas vêm de `PeriodoRelatorio::colunas` e cada linha cai na sua coluna por `PeriodoRelatorio::colunaDe`. Ao juntar colunas no aviso, a lista segue a ordem fixa e usa vírgula e "e" ("Abertos, Fechados e Cancelados").

- [ ] **Step 4: Rodar a suíte inteira**
  Expected: PASS.

- [ ] **Step 5: Escrever a subseção "Indicadores por grupo" no `BACKEND_SPECS.md` §3.4.9 e fazer o commit** `feat(relatorios): indicadores por grupo endpoint`.

### Task 5: Lista de chamados por célula e planilhas

**Files:**
- Create: `app/Exports/IndicadoresExport.php`
- Modify: `app/Http/Controllers/Api/IndicadoresController.php`, `routes/api.php`, `app/Relatorios/ListaChamados.php` (extrair o `montar` para aceitar um booleano `contaEventos` em vez de depender da `ConsultaRelatorio`, se necessário, sem mudar a saída atual), `BACKEND_SPECS.md`
- Test: `tests/Feature/Relatorios/IndicadoresChamadosTest.php`, `tests/Feature/Relatorios/IndicadoresExportTest.php` (novos)

**Interfaces:**
- Consumes: `FonteIndicadores::linhas` e `AgregadorIndicadores::agregar` (Task 4), `ListaChamados::montar`, `RelatorioChamadosExport`.
- Produces:
  - `GET /api/relatorios/indicadores/chamados`: os mesmos parâmetros da consulta, mais `chave` (string, obrigatório), `indicador` (um dos 4, obrigatório), `coluna` (opcional; inexistente → 422 `"Coluna inexistente neste período."`), `page`, `per_page` (máx. 200) e `formato` (`json|xlsx`).
    - O JSON segue o formato de `RelatorioConsultaController::chamados` (`titulo`, `linha`, `coluna`, `data`, `meta`), com `indicador: {chave, rotulo}` a mais.
    - É uma linha por chamado: fica o evento mais recente no escopo. A lista sai das mesmas `LinhaIndicador` do número.
  - `GET /api/relatorios/indicadores?formato=xlsx` → `IndicadoresExport`. A planilha tem, nesta ordem:
    - título; `"Gerado em dd/mm/aaaa hh:mm (horário de Brasília) por {nome}"`; uma linha em branco;
    - o cabeçalho: `Grupo` (ou `Integrante`), depois, para cada indicador, uma coluna por período (só com quebra) e a coluna `{Indicador} — Total`;
    - as linhas, a linha `Total` e a linha `"Chamados distintos"` (valores de `distintos` sob as colunas de total);
    - uma linha em branco, `"Legenda"` e os 5 textos; o aviso, se houver.
    - Sem fórmulas: todo texto é gravado como string (mesmo `bindValue` de `RelatorioTabelaExport`).
  - As duas exportações registram auditoria `Auditoria::registrar('exportado', ...)`, como no `RelatorioConsultaController`.

- [ ] **Step 1: Escrever os testes que falham:**
  - `test_lista_bate_com_cada_celula`: para todas as linhas × indicadores de um cenário com reabertura e portal, `meta.total` == o valor da célula.
  - `test_lista_de_chamado_fora_dos_visiveis_vem_sem_acesso` (Review Focus 5): `acessivel: false`.
  - `test_coluna_inexistente_e_422`.
  - `test_xlsx_tem_titulo_legenda_aviso_e_distintos`: lê a planilha e confere as células da primeira coluna e os valores das linhas `Total` e `Chamados distintos`.
  - `test_xlsx_texto_com_igual_nao_vira_formula`: um grupo chamado `=1+1` sai como string.

- [ ] **Step 2: Rodar e ver falhar; implementar; rodar a suíte inteira**
  Expected: PASS.

- [ ] **Step 3: Atualizar o `BACKEND_SPECS.md` e fazer o commit** `feat(relatorios): indicadores ticket list and spreadsheet`.

### Task 6: Relatórios salvos do tipo indicadores

**Files:**
- Modify: `app/Models/RelatorioSalvo.php`, `app/Http/Controllers/Api/RelatorioSalvoController.php` (`validated`, `executar`), `app/Http/Resources/RelatorioSalvoResource.php` (se precisar expor algo novo), `BACKEND_SPECS.md`
- Test: `tests/Feature/Relatorios/RelatorioSalvoCrudTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: `ConsultaIndicadores::regras()` e `::deDados()`, e o `IndicadoresController` (expor um `public responder(ConsultaIndicadores $c, string $formato, User $u)`, no mesmo padrão do `RelatorioConsultaController::responder`).
- Produces:
  - Um relatório salvo com `contar = 'indicadores'` guarda `agrupar_por = 'grupo'` (ou `'integrante'` quando `por_integrante`), `quebra`, e em `filtros` guarda `grupos`, `periodo` ou `data_inicio`/`data_fim`.
  - `executar` resolve o atalho na hora e delega ao `IndicadoresController::responder`.
  - Os erros de validação voltam com o prefixo `filtros.` para os campos dentro de `filtros`.
  - Não precisa de migration: `contar` e `agrupar_por` são strings livres. Confirme na migration `2026_11_11_000000`; se houver CHECK ou enum, inclua a migration nesta task.

- [ ] **Step 1: Escrever os testes que falham:**
  - `test_salva_e_executa_indicadores_com_atalho_relativo`: salvo com `mes_passado`, executado com `Carbon::setTestNow` em 05/11 e em 05/12, cobre outubro e novembro.
  - `test_salvar_indicadores_valida_por_integrante_com_um_grupo`: 422 em `filtros.por_integrante`.
  - `test_listagem_mostra_salvo_de_indicadores_sem_etiqueta_de_legado`.

- [ ] **Step 2: Rodar e ver falhar; implementar; rodar a suíte inteira**
  Expected: PASS.

- [ ] **Step 3: Atualizar o `BACKEND_SPECS.md` e fazer o commit** `feat(relatorios): saved indicadores reports`.

### Task 7: Frontend — aba "Indicadores por grupo"

**Files:**
- Create: `src/components/reports/IndicadoresPanel.vue`, `src/components/reports/IndicadoresTable.vue`
- Modify: `src/views/ReportsView.vue` (abas `Indicadores por grupo` (padrão) | `Avançado`; todo o conteúdo atual vai para a aba Avançado sem mudar comportamento), `src/services/reportService.js`

**Interfaces:**
- Consumes: `GET /relatorios/indicadores` (Task 4, formatos json e xlsx); `GET /relatorios/indicadores/chamados` (Task 5).
- Produces:
  - Em `reportService`: `indicadores(params)` e `indicadoresChamados(params)`, no mesmo padrão de blob e erro de `gerar` e `chamados`.
  - `IndicadoresPanel` emite `abrir-chamados` com `{chave, indicador, coluna, rotulo}`.

- [ ] **Step 1: Criar o worktree do front:** `git -C ../gespriority_claude_front worktree add ../gespriority_claude_front-indicadores -b feat/relatorios-indicadores main`, e `npm ci` nele.

- [ ] **Step 2: Implementar o painel**, na ordem da spec §7:
  1. Os filtros:
     - grupos: `v-autocomplete` múltiplo, com as opções vindas do mesmo lookup de grupos já usado nos filtros da aba Avançado;
     - período: atalhos + personalizado, reaproveitando os controles atuais;
     - quebra;
     - o switch "Detalhar por integrante", desabilitado quando `grupos.length !== 1`.
  2. Abaixo dos filtros, na ordem:
     - o título vindo da API;
     - o gráfico de barras agrupadas: sem quebra, eixo = linhas e 4 datasets, um por indicador; com quebra, um gráfico por indicador com as linhas como datasets e os períodos no eixo (escolha do plano);
     - a tabela, com `IndicadoresTable` (cabeçalho de dois níveis indicador → períodos/Total, linha Total e linha "Chamados distintos" com valores só nos totais; células clicáveis);
     - a legenda (lista com os 5 textos);
     - o aviso, num `v-alert` do tipo `warning` quando não for nulo;
     - os botões "Exportar imagem" (reaproveitando a composição de fundo branco e título de `exportImage` em `ReportsView.vue`) e "Baixar planilha".
  3. A validação de limite de colunas da quebra no cliente reaproveita `contarColunas` / `validarQuebra`, movidos para `src/utils/` se for preciso compartilhar.

- [ ] **Step 3: Build**
  Run: `npm run build` (Node 22)
  Expected: sucesso, sem erros.

- [ ] **Step 4: Checagem manual** contra o backend local, com o usuário admin do seed:
  - o cenário da Review Focus 2;
  - a troca de aba preserva o estado da aba Avançado;
  - a imagem exportada traz o título.
  Registre o resultado no corpo do commit.

- [ ] **Step 5: Commit** `feat(relatorios): indicadores por grupo tab` no worktree do front.

### Task 8: Frontend — lista de chamados e relatórios salvos

**Files:**
- Modify: `src/components/reports/ReportTicketListDialog.vue` (aceitar uma prop `fonte: 'consulta' | 'indicadores'` e escolher o método do service; mostrar `indicador.rotulo` no cabeçalho), `src/components/reports/IndicadoresPanel.vue`, `src/views/ReportsView.vue` (salvar e abrir relatórios `contar = 'indicadores'`)

**Interfaces:**
- Consumes: `reportService.indicadoresChamados`, `createSaved` e `executarSaved` (Task 6), e o evento `abrir-chamados` (Task 7).
- Produces: clicar numa célula abre o diálogo com a lista e o botão "Baixar lista". Abrir um salvo de indicadores muda para a aba Indicadores, carrega os filtros e roda o relatório. "Salvar" na aba Indicadores envia `contar: 'indicadores'`.

- [ ] **Step 1: Implementar.**

- [ ] **Step 2: Build**
  Run: `npm run build`
  Expected: sucesso.

- [ ] **Step 3: Checagem manual:**
  - o número de linhas do diálogo bate com a célula clicada, em três células diferentes, incluindo a de "Cliente (portal)";
  - um relatório salvo com "Mês passado" reabre e roda.

- [ ] **Step 4: Commit** `feat(relatorios): indicadores ticket list and saved reports`.

---

## Encerramento

Depois da Task 8, fazer a revisão da branch inteira nos dois repos. O merge local na `main` acontece só com aprovação explícita do usuário. O deploy (push) é decisão do usuário e, quando houver, a migration de backfill (Task 3) roda em produção. Pela memória do projeto, o waiter de SSM do Actions pode estourar o tempo em rebuilds; nesse caso, verificar no servidor.
