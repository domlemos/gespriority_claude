# Relatórios — Mapa de fluxos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Uma aba "Mapa de fluxos" em Relatórios que mostra, para um período, as passagens de chamados entre Cliente e grupos de solução, com Abertos pelo grupo, Recebidos, Encaminhados e Resolvidos em cada nó.

**Architecture:**
- **Registro da origem:** o encaminhamento passa a gravar a origem (`incidente_eventos.origem_grupo_id`), e a remoção de grupo vira um evento `removido_grupo`. Uma migration deriva a origem dos encaminhamentos já existentes.
- **Backend:** um endpoint novo `GET /api/relatorios/fluxos` (com `/chamados` e xlsx), em `app/Relatorios/Fluxos/`. Ele transforma eventos em **passagens** (origem → destino), e nós e arestas saem de uma única coleção de passagens. Por isso a lista ao clicar bate com o número.
- **Frontend:** desenha o mapa em SVG próprio, numa aba nova entre "Indicadores por grupo" e "Avançado".

**Tech Stack:**
- Backend `gespriority_claude`: Laravel 12 / PHPUnit (SQLite em memória) / maatwebsite/excel.
- Frontend `gespriority_claude_front`: Vue 3 + Vuetify 3, sem suíte de testes, build com Node 22.

**Spec:** `docs/superpowers/specs/2026-11-12-relatorios-mapa-de-fluxos-design.md` (aprovada). Ler as seções 4 e 5 antes de qualquer task.

## Global Constraints

- **Chaves de nó (string):** id do grupo como string (`"12"`), `cliente`, `sem_grupo`, `origem_desconhecida` e `outros`. Rótulos: o nome do grupo, `Cliente`, `Sem grupo`, `Origem desconhecida` e `Outros grupos`. Valores de `tipo` do nó: `cliente`, `grupo`, `sem_grupo`, `origem_desconhecida` e `outros`.
- **Passagem:** cada evento no período que move um chamado para um grupo de destino Y vindo de uma origem X ≠ Y (spec 4.2).
  - `encaminhado_grupo`: X = a origem (spec 4.3), Y = `alvo_id`.
  - `aberto` com `grupo_solucao_id` = Y:
    - aberto pelo portal (`autor_customer_id` não nulo): X = `cliente`;
    - aberto por agente com `autor_grupo_solucao_id` = A ≠ Y: X = A;
    - aberto por agente do próprio Y (A = Y), ou com A nulo: não é passagem.
  - Uma passagem com X = Y é descartada.
- **Origem de um `encaminhado_grupo`, na leitura:**
  - `origem_grupo_id` preenchido: esse grupo;
  - senão, se o chamado foi aberto pelo portal com `grupo_solucao_id` nulo e não há nenhum `encaminhado_grupo` anterior a este evento (por `created_at`, depois `id`): `cliente`;
  - senão, se `retroativo = true`: `origem_desconhecida`;
  - senão: `sem_grupo`.
- **Métricas do nó de grupo G:**
  - `abertos` = eventos `aberto` com `autor_grupo_solucao_id = G`;
  - `recebidos` = passagens com destino G;
  - `encaminhados` = passagens com origem G. É a regra da plan-ruling P1: a soma das setas que saem é igual a Encaminhados, então inclui as aberturas de G direto para outro grupo;
  - `resolvidos` = chamados distintos com evento `resolvido` e `grupo_solucao_id = G`.
  - Nó `cliente`: `abertos` = eventos `aberto` do portal no período; as outras três métricas são `null`. Nós `sem_grupo`, `origem_desconhecida` e `outros`: as quatro métricas são `null`.
- **Unidades:** passagens e Recebidos/Encaminhados contam eventos; Abertos conta eventos `aberto` (1 por chamado); Resolvidos conta chamados distintos.
- **Visibilidade** (`User::visibleGrupoSolucaoIds()`; `null` = admin vê tudo):
  - a ponta de grupo de uma passagem que não é visível vira `outros`;
  - a passagem entra só se pelo menos uma ponta for um grupo visível (plan-ruling P2: Cliente sozinho não basta, para não revelar volume de outros grupos);
  - `cliente.abertos` para quem não é admin conta só chamados que ele vê hoje (`Incidente::visiveisPara`);
  - um grupo pedido fora da visibilidade dá 422 em `grupos.N` com `"Grupo fora dos seus grupos visíveis."`, por `ConsultaIndicadores::validarVisibilidade`.
- **Filtro `grupos`:**
  - entram as passagens com pelo menos uma ponta em `grupos`;
  - os nós de grupo são os de `grupos` (sempre, mesmo zerados) mais as outras pontas dessas passagens, quando são grupos visíveis;
  - `cliente.abertos` conta só os chamados do portal cujo primeiro grupo atribuído está em `grupos`, com a mesma regra de `FonteIndicadores::primeiroGrupoEm`.
- **Sem filtro:** os nós de grupo são os grupos visíveis com alguma métrica > 0 ou alguma passagem. Os nós especiais aparecem quando têm passagem; `cliente` aparece sempre.
- **Período** obrigatório, por `periodo` (atalho) ou `data_inicio` + `data_fim`, com as regras e mensagens de `ConsultaIndicadores::regras()`. Sem `quebra` e sem `por_integrante`: reutilize `ConsultaIndicadores` com `porIntegrante = false` e `quebra = 'nenhuma'`. Datas via `PeriodoRelatorio::inicioUtc` / `fimExclusivoUtc` sobre `incidente_eventos.created_at`.
- **Título:** `"Mapa de fluxos — de dd/mm/aaaa a dd/mm/aaaa[ — grupos: N1, N2]"`.
- **Legenda**, nesta ordem, texto exato:
  1. `Abertos pelo grupo: chamados criados no período por integrantes do grupo.`
  2. `Recebidos: chamados que entraram no grupo vindos do cliente ou de outro grupo, por encaminhamento ou abertos diretamente para ele.`
  3. `Encaminhados: encaminhamentos que saíram do grupo para outro grupo. Um chamado que vai e volta conta cada passagem.`
  4. `Resolvidos: chamados resolvidos enquanto estavam no grupo, por qualquer analista. A produção por analista está em Indicadores por grupo.`
  5. `As setas contam passagens no período. Recebidos menos encaminhados menos resolvidos não é o número de chamados em aberto.`
- **Aviso:** quando existe evento `aberto`, `encaminhado_grupo` ou `resolvido` com `retroativo = true` no período, sem filtro de grupo nem visibilidade, o texto é `"Dados aproximados: registros até dd/mm/aaaa foram reconstruídos; nesses casos a origem de um encaminhamento pode aparecer como desconhecida e o grupo de quem abriu é o grupo atual da pessoa."`. A data é a do `created_at` mais recente entre esses eventos, em Brasília. Sem retroativos, o aviso é `null`.
- **Branches e repositórios:**
  - backend na branch `feat/relatorios-mapa-fluxos`, in-place (já criada, com spec e plano);
  - frontend num worktree `../gespriority_claude_front-fluxos`, na branch de mesmo nome, criado a partir da `main`;
  - nunca dar push;
  - o working tree do backend tem mudanças do usuário fora desta entrega (`database/seeders/CategoriasSeeder.php`, `database/seeders/IncidentesSeeder.php`, `infra/terraform/modules/data/main.tf`). Nunca adicioná-las a um commit; use `git add <arquivos>` explícito;
  - nunca rodar migrations no banco do app de desenvolvimento.
- **Testes:**
  - backend: `docker compose exec -T app php artisan test --filter=<Classe>`, e a suíte inteira antes de cada commit;
  - front: `export PATH=$HOME/.nvm/versions/node/v22.14.0/bin:$PATH; npm run build`.
- **Sem dependências novas.** Abas e endpoints existentes não mudam de comportamento.
- **Documentação:** cada task de backend atualiza o `BACKEND_SPECS.md`: §3.1 para tabela e model; §3.4.9 numa subseção nova "Mapa de fluxos".
- **Commits:** terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Chamado do portal com triagem e reencaminhamento:** chamado do portal triado para N1 e depois encaminhado para N2, no período. O mapa mostra `cliente → N1` = 1 e `N1 → N2` = 1. N1 fica com recebidos 1 e encaminhados 1; N2 com recebidos 1. (Task 2)
2. **Ida e volta:** N1 → N2 → N1 no período dá a seta `N1 → N2` = 1 e a seta `N2 → N1` = 1; N1 com recebidos 1 e encaminhados 1. (Task 2)
3. **Resolução fora do grupo:** chamado no N2 resolvido por analista do N1 conta em `resolvidos` do N2, não do N1. (Task 2)
4. **Gestor de N1 sem visibilidade de N3:** a passagem N1 → N3 aparece como `N1 → outros`, e nenhum nome ou número de N3 aparece na resposta, nem na planilha. (Task 2, Task 3)
5. **Remoção de grupo:** chamado com grupo removido (N1 → nenhum) e depois encaminhado para N2 gera `sem_grupo → N2`, não `N1 → N2`. (Task 1, Task 2)

---

### Task 1: Origem do encaminhamento e evento `removido_grupo`

**Files:**
- Create: `database/migrations/2026_11_13_000000_add_origem_grupo_to_incidente_eventos_table.php`
- Modify: `app/Models/IncidenteEvento.php`, `app/Http/Controllers/Api/IncidenteController.php` (`logEscalonamentoSeMudou` e a chamada para o grupo, linhas ~207-215 e ~430-460), `BACKEND_SPECS.md`
- Test: `tests/Feature/Relatorios/IncidenteEventoOrigemTest.php` (novo), `tests/Feature/Relatorios/BackfillOrigemEncaminhamentoTest.php` (novo; roda o `up()` da migration contra dados montados, como `BackfillEventosTest`)

**Interfaces:**
- Produces:
  - Coluna `incidente_eventos.origem_grupo_id` (FK `grupos_solucao`, anulável, `nullOnDelete`, indexada); entra no `#[Fillable]`.
  - `IncidenteEvento::TIPO_REMOVIDO_GRUPO = 'removido_grupo'`, incluída em `TIPOS`.
  - Todo `encaminhado_grupo` novo grava `origem_grupo_id` = o grupo anterior do chamado (`$grupoAnteriorId`, que pode ser nulo).
  - Quando o grupo vai de A (não nulo) para nulo, grava-se um evento `removido_grupo` com `origem_grupo_id = A`, `alvo_type`/`alvo_id` nulos e autor = usuário autenticado. Sem entrada nova no feed.
  - O comportamento de `encaminhado_responsavel` não muda.
- Migration (só query builder, por chamado, em ordem de `created_at`, depois `id`, para os `encaminhado_grupo` com `origem_grupo_id` nulo):
  - **Há `encaminhado_grupo` anterior:** origem = o `alvo_id` dele. Se o alvo foi um grupo excluído, origem nula e o evento é marcado `retroativo = true`.
  - **Senão, há `aberto`:**
    - `grupo_solucao_id` não nulo: origem = esse grupo;
    - nulo e `autor_customer_id` não nulo (portal): origem nula, sem marcar;
    - nulo e o `aberto` com `retroativo = true`: origem nula e o encaminhamento marcado `retroativo = true` (origem desconhecida);
    - nulo e o `aberto` com `retroativo = false`: origem nula, sem marcar (sem grupo, de fato).
  - **Senão (sem `aberto`):** origem nula, marcado `retroativo = true`.
  - Idempotente: só toca eventos com `origem_grupo_id` nulo, e reaplicar não muda nada.
  - `down()` remove a coluna e apaga os eventos `removido_grupo`.

- [ ] **Step 1: Escrever os testes que falham.**

  Em `IncidenteEventoOrigemTest`, via `PUT /api/incidentes/{id}`:
  - `test_encaminhar_grava_grupo_de_origem`: N1 → N2 gera `encaminhado_grupo` com `origem_grupo_id` = N1 e `alvo_id` = N2.
  - `test_encaminhar_chamado_sem_grupo_grava_origem_nula`.
  - `test_remover_grupo_gera_removido_grupo_com_origem`: N1 → `null` gera exatamente 1 evento `removido_grupo` com `origem_grupo_id` = N1 e nenhum `encaminhado_grupo`.
  - `test_relatorios_existentes_ignoram_removido_grupo`: `GET /api/relatorios/incidentes?contar=encaminhamentos&agrupar_por=para_grupo` tem o mesmo total antes e depois de uma remoção.

  Em `BackfillOrigemEncaminhamentoTest`:
  - `test_origem_vem_do_encaminhamento_anterior`;
  - `test_origem_vem_do_grupo_da_abertura`;
  - `test_portal_fica_nulo_sem_retroativo`;
  - `test_abertura_reconstruida_sem_grupo_marca_retroativo`;
  - `test_sem_abertura_marca_retroativo`;
  - `test_rodar_duas_vezes_nao_muda_nada`.

- [ ] **Step 2: Rodar e ver falhar**
  Run: `docker compose exec -T app php artisan test --filter='IncidenteEventoOrigemTest|BackfillOrigemEncaminhamentoTest'`
  Expected: FAIL.

- [ ] **Step 3: Implementar a migration, o model e o controller.** No controller, passe `origem_grupo_id` só no evento de grupo; o caminho do responsável continua igual.

- [ ] **Step 4: Rodar a suíte inteira**
  Expected: PASS.

- [ ] **Step 5: Atualizar o `BACKEND_SPECS.md` §3.1 e fazer o commit** `feat(eventos): record forward origin group and group removal`.

### Task 2: Endpoint do mapa (JSON)

**Files:**
- Create:
  - `app/Relatorios/Fluxos/Passagem.php`
  - `app/Relatorios/Fluxos/FonteFluxos.php`
  - `app/Relatorios/Fluxos/AgregadorFluxos.php`
  - `app/Relatorios/Fluxos/TextosFluxos.php`
  - `app/Http/Controllers/Api/FluxosController.php`
- Modify:
  - `app/Relatorios/Indicadores/FonteIndicadores.php`: tornar `primeiroGrupoEm` `public static`, sem mudar o comportamento;
  - `routes/api.php`: `GET /relatorios/fluxos` com `can:relatorios.view`, junto das rotas de indicadores;
  - `BACKEND_SPECS.md`.
- Test: `tests/Feature/Relatorios/FluxosTest.php`

**Interfaces:**
- Consumes:
  - de `ConsultaIndicadores`: `regras()`, `deDados()`, `validarVisibilidade()`, e as props `grupos`, `inicio`, `fim`, `atalho`;
  - `FonteIndicadores::primeiroGrupoEm(Builder $q, array $grupos): Builder`;
  - `LinhaBase`, `RotulosRelatorio::grupos()`, `PeriodoRelatorio`;
  - a origem gravada na Task 1.
- Produces:
  - `Passagem` (`final readonly`):
    - `string $origem` e `string $destino` (chaves de nó, já com a visibilidade aplicada);
    - `string $via`, com `encaminhamento` ou `abertura`;
    - `LinhaBase $base` (chave = `"{origem}>{destino}"`; `eventoId`; `autorId`; `data`).
  - `FonteFluxos::passagens(ConsultaIndicadores $c, User $u): Collection<int, Passagem>`, com período, regra de origem, visibilidade e filtro de grupos das Global Constraints. É uma query para `encaminhado_grupo` e outra para `aberto`. A regra do "primeiro do portal" fica em SQL (subconsulta correlacionada), não em PHP por linha.
  - `FonteFluxos::abertos(ConsultaIndicadores $c, User $u): Collection<int, LinhaBase>`, onde a chave é o id do grupo do autor (string) ou `cliente`.
  - `FonteFluxos::resolvidos(ConsultaIndicadores $c, User $u): Collection<int, LinhaBase>`, onde a chave é o `grupo_solucao_id` do evento (string).
  - `FonteFluxos::retroativos(ConsultaIndicadores $c): ?CarbonInterface`.
  - `AgregadorFluxos::agregar(ConsultaIndicadores $c, User $u): array`, que devolve:
    ```json
    {
      "titulo": "...", "periodo": {"inicio": "Y-m-d", "fim": "Y-m-d"}, "grupos": [1],
      "nos": [{"chave": "cliente", "rotulo": "Cliente", "tipo": "cliente",
               "abertos": 15, "recebidos": null, "encaminhados": null, "resolvidos": null}, ...],
      "arestas": [{"origem": "cliente", "destino": "3", "quantidade": 15}, ...],
      "legenda": ["...5 textos..."], "aviso": null
    }
    ```
    - Ordem dos nós: `cliente`, `sem_grupo`, `origem_desconhecida`, os grupos por nome (collator de `AgregadorRelatorio::ordenar`), `outros`.
    - Ordem das arestas: `quantidade` desc, depois `origem`, depois `destino`.
  - `FluxosController::index(Request)` e `public responder(ConsultaIndicadores $c, string $formato, User $u)`. O `responder` chama `validarVisibilidade` (por enquanto só json); a Task 3 acrescenta o xlsx dentro dele. As regras são `array_diff_key(ConsultaIndicadores::regras(), array_flip(['quebra', 'por_integrante']))` mais `formato`.

- [ ] **Step 1: Escrever os testes que falham** em `FluxosTest`. Monte os eventos com `IncidenteEvento::query()->create([...])`, com datas, grupos, `origem_grupo_id`, `autor_customer_id` e `retroativo` explícitos. Use um admin e um gestor não-admin do N1 sem visibilidade de N3.
  - `test_exige_periodo`: 422 em `periodo` com `"Informe um período."`.
  - `test_portal_triado_e_reencaminhado` (Review Focus 1).
  - `test_ida_e_volta_conta_cada_passagem` (Review Focus 2).
  - `test_resolvido_conta_no_grupo_do_chamado` (Review Focus 3).
  - `test_gestor_ve_outros_grupos_agregados` (Review Focus 4): no JSON inteiro (`json_encode`), nem o nome nem o id de N3 aparecem.
  - `test_remocao_de_grupo_gera_sem_grupo` (Review Focus 5).
  - `test_abertura_direta_para_outro_grupo_e_passagem_e_conta_como_encaminhado_da_origem`: agente de N1 abre para N2. Resulta em `N1 → N2` = 1; N1 com abertos 1 e encaminhados 1; N2 com recebidos 1.
  - `test_abertura_para_o_proprio_grupo_nao_e_passagem`.
  - `test_origem_desconhecida_para_encaminhamento_retroativo_sem_origem`.
  - `test_soma_das_setas_bate_com_recebidos_e_encaminhados`: invariante sobre um cenário misto.
  - `test_filtro_de_grupos_mostra_grupos_selecionados_zerados_e_vizinhos`.
  - `test_cliente_abertos_com_filtro_usa_primeiro_grupo_atribuido`.
  - `test_grupo_invisivel_no_filtro_e_422`.
  - `test_aviso_ignora_filtro_e_usa_data_mais_recente` e `test_aviso_nulo_sem_retroativos`.
  - `test_titulo_e_legenda`: as strings exatas das Global Constraints.

- [ ] **Step 2: Rodar e ver falhar**
  Run: `docker compose exec -T app php artisan test --filter=FluxosTest`
  Expected: FAIL (rota inexistente).

- [ ] **Step 3: Implementar** as classes e a rota.

- [ ] **Step 4: Rodar a suíte inteira**
  Expected: PASS. `IndicadoresTest` precisa continuar igual.

- [ ] **Step 5: Escrever a subseção "Mapa de fluxos" no `BACKEND_SPECS.md` §3.4.9 e fazer o commit** `feat(relatorios): mapa de fluxos endpoint`.

### Task 3: Lista por nó ou seta e planilha

**Files:**
- Create: `app/Exports/FluxosExport.php`
- Modify: `app/Http/Controllers/Api/FluxosController.php`, `routes/api.php`, `BACKEND_SPECS.md`
- Test: `tests/Feature/Relatorios/FluxosChamadosTest.php`, `tests/Feature/Relatorios/FluxosExportTest.php`

**Interfaces:**
- Consumes: `FonteFluxos::*` e `AgregadorFluxos::agregar` (Task 2); `ListaChamados::montar(User $u, Collection $linhas, bool $comDestino)`; `RelatorioChamadosExport`; `Auditoria::registrar`.
- Produces:
  - `GET /api/relatorios/fluxos/chamados`: os parâmetros da consulta mais **um** dos dois modos.
    - Modo nó: `no` (chave) + `metrica` (`abertos`, `recebidos`, `encaminhados` ou `resolvidos`).
    - Modo seta: `origem` + `destino`.
    - Mais `page`, `per_page` (máx. 200) e `formato` (`json` ou `xlsx`).
    - Os dois modos juntos, ou nenhum, dão 422 em `no`: `"Informe um nó e uma métrica, ou uma origem e um destino."`.
    - Métrica `null` para aquele nó (por exemplo `recebidos` do `cliente`) dá 422 em `metrica`: `"Este número não existe para este nó."`.
  - Unidade das linhas:
    - `abertos` e `resolvidos`: uma linha por chamado (o evento mais recente);
    - `recebidos`, `encaminhados` e seta: uma linha por passagem, com `comDestino = true` para mostrar o grupo de destino.
    - O total de linhas é igual ao número clicado.
  - JSON no mesmo formato de `IndicadoresController::chamados` (`titulo`, `data`, `meta`), com `filtro: {rotulo}`, por exemplo `"Suporte · Recebidos"` ou `"Cliente → Suporte"`.
  - Visibilidade validada no início (o mesmo 422). A ponta `outros` nunca mostra chamados de grupos invisíveis de forma acessível: as linhas trazem `acessivel` pela regra de `ListaChamados::montar`.
  - `responder` com `formato=xlsx` gera `FluxosExport`, com nome `mapa-de-fluxos.xlsx`. A planilha tem, nesta ordem:
    - o título;
    - `"Gerado em dd/mm/aaaa hh:mm (horário de Brasília) por {nome}"`;
    - uma linha em branco;
    - `Nós`, depois o cabeçalho `Grupo | Abertos pelo grupo | Recebidos | Encaminhados | Resolvidos` e as linhas (vazio onde a métrica é `null`);
    - uma linha em branco;
    - `Fluxos`, depois o cabeçalho `Origem | Destino | Quantidade` e as linhas, com os rótulos dos nós;
    - uma linha em branco;
    - `Legenda` e os 5 textos;
    - o aviso, se houver.
    - Todo texto é gravado como string (mesmo `bindValue` de `IndicadoresExport`).
  - Auditoria `exportado` nas duas exportações, com `parametros` montados da `ConsultaIndicadores` (padrão de `IndicadoresController::parametros`).

- [ ] **Step 1: Escrever os testes que falham:**
  - `test_lista_bate_com_cada_numero_e_cada_seta`: percorre todos os nós × métricas não nulas e todas as setas de um cenário misto; `meta.total` é igual ao número.
  - `test_modos_invalidos_sao_422`;
  - `test_metrica_inexistente_no_no_e_422`;
  - `test_lista_de_seta_para_outros_nao_revela_grupo` (gestor não-admin): nenhuma linha traz o nome de N3 em `grupo_chamado` ou `destino` com `acessivel = true`;
  - `test_xlsx_tem_nos_fluxos_legenda_e_aviso`;
  - `test_xlsx_texto_com_igual_nao_vira_formula`;
  - `test_exportacoes_registram_auditoria`.

- [ ] **Step 2: Rodar e ver falhar; implementar; rodar a suíte inteira**
  Expected: PASS.

- [ ] **Step 3: Atualizar o `BACKEND_SPECS.md` e fazer o commit** `feat(relatorios): mapa de fluxos ticket list and spreadsheet`.

### Task 4: Frontend — aba "Mapa de fluxos"

**Files:**
- Create:
  - `src/components/reports/FluxosPanel.vue` (filtros, gerar, título, mapa, legenda, aviso, exportações);
  - `src/components/reports/FluxosMapa.vue` (SVG);
  - `src/utils/fluxosLayout.js` (posicionamento puro);
  - `src/utils/svgImagem.js` (SVG → canvas).
- Modify:
  - `src/views/ReportsView.vue`: abas `Indicadores por grupo | Mapa de fluxos | Avançado`, todas montadas com `v-show`;
  - `src/services/reportService.js`: `fluxos(params)` e `fluxosChamados(params)`, no padrão json/blob de `indicadores`.

**Interfaces:**
- Consumes: `GET /relatorios/fluxos` (Task 2, Task 3); `ReportPeriodControls` (sem quebra; mesma prop `obrigatorio` usada no `IndicadoresPanel`); `paramsPeriodo`; `downloadBlob`; `baixarImagemGraficos(canvases, nomeArquivo, { titulo, rodape })`.
- Produces:
  - `layoutFluxos(nos, arestas): { colunas: Array<Array<chave>>, posicoes: Record<chave, {x, y, largura, altura}>, curvas: Array<{origem, destino, quantidade, d: string, rotuloX, rotuloY, retorno: boolean}>, largura, altura }`, função pura com o algoritmo da spec §5.2:
    - a coluna de cada nó é a menor distância por BFS a partir de `cliente`;
    - os nós sem caminho a partir do Cliente começam na coluna 1, a partir de `sem_grupo` ou `origem_desconhecida`;
    - `outros` fica na última coluna;
    - uma seta para a frente é uma curva cúbica entre a borda direita da origem e a borda esquerda do destino;
    - uma seta para trás ou na mesma coluna é um arco por baixo das caixas (`retorno: true`).
  - `svgParaCanvas(svgElement): Promise<HTMLCanvasElement>`: copia os estilos computados (fill, stroke, font) para atributos inline antes de serializar, para que o PNG respeite o tema; usa fundo branco.
  - `FluxosMapa` emite `abrir-chamados` com `{ modo: 'no', no, metrica, rotulo }` ou `{ modo: 'seta', origem, destino, rotulo }`.

- [ ] **Step 1: Criar o worktree:**
  ```bash
  git -C ../gespriority_claude_front worktree add ../gespriority_claude_front-fluxos -b feat/relatorios-mapa-fluxos main
  ```
  Depois, `npm ci` nele.

- [ ] **Step 2: Implementar o layout, o SVG e o painel.**
  - Visual da caixa como no esboço: cabeçalho com o nome e quatro linhas rótulo/valor.
  - Valores clicáveis quando > 0 e não nulos.
  - O nó Cliente é um círculo com "Abertos".
  - `outros`, `sem_grupo` e `origem_desconhecida` são caixas tracejadas, só com o nome.
  - Cada seta mostra a quantidade num rótulo com fundo e é clicável.
  - As cores vêm dos tokens do Vuetify (tema claro e escuro).
  - O contêiner tem `overflow-x: auto`.
  - "Exportar imagem" usa `svgParaCanvas` + `baixarImagemGraficos`, com o título e um rodapé com a legenda e o aviso.
  - "Baixar planilha" chama `fluxos({...params, formato: 'xlsx'})` e salva como `mapa-de-fluxos.xlsx`.

- [ ] **Step 3: Build**
  Run: `npm run build` (Node 22)
  Expected: sucesso, sem warnings novos.

- [ ] **Step 4: Commit** `feat(relatorios): mapa de fluxos tab` no worktree.

### Task 5: Frontend — lista de chamados por nó ou seta

**Files:**
- Modify: `src/components/reports/ReportTicketListDialog.vue` (nova `fonte: 'fluxos'`), `src/components/reports/FluxosPanel.vue`

**Interfaces:**
- Consumes: `reportService.fluxosChamados`; o evento `abrir-chamados` de `FluxosMapa` (Task 4); o contrato da lista da Task 3.
- Produces:
  - com `fonte = 'fluxos'`, o diálogo envia `no` + `metrica`, ou `origem` + `destino`, em vez de `chave`/`coluna`/`indicador`;
  - o cabeçalho mostra `filtro.rotulo`;
  - a coluna Destino aparece nas listas de recebidos, encaminhados e seta;
  - "Baixar lista" salva como `mapa-de-fluxos-chamados.xlsx`;
  - as fontes `consulta` e `indicadores` não mudam.

- [ ] **Step 1: Implementar.**

- [ ] **Step 2: Build**
  Expected: sucesso.

- [ ] **Step 3: Commit** `feat(relatorios): mapa de fluxos ticket list`.

---

## Encerramento

1. Revisão da branch inteira nos dois repos.
2. Verificação das migrations num banco PostgreSQL temporário: criar, rodar o migrate e o seed, conferir e descartar. Nunca no banco de desenvolvimento nem em produção.
3. Merge local na `main` e push só com pedido explícito do usuário. O deploy do backend exige aprovação manual do ambiente `production`. Faça o push do backend antes do frontend, e segure o frontend até o backend estar no ar, porque o front depende das rotas novas.

**Plan-rulings** (decididas aqui; a spec as deixa implícitas):
- **P1:** "Encaminhados" inclui as aberturas diretas para outro grupo, para que a soma das setas que saem seja igual a Encaminhados (spec 4.2).
- **P2:** uma passagem só entra para quem não é admin se uma ponta for um grupo visível. A spec dizia "ou o Cliente", mas isso exporia o volume do portal para grupos invisíveis.
