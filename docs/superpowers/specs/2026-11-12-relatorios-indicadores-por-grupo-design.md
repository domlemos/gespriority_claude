# Relatórios — Indicadores por grupo de solução com rastreio exato de autoria

**Status:** rascunho para aprovação · **Data:** 2026-11-12 · **Repos:** `gespriority_claude` (backend) e `gespriority_claude_front` (frontend)

Antecedentes: Fase 1 (`docs/superpowers/plans/2026-11-09-relatorios-precisao-fase-1.md`) e Fase 2 (`docs/superpowers/specs/2026-11-09-relatorios-fase-2-design.md`), ambas na `main`.

## 1. Intenção

Os stakeholders precisam de um indicador simples e confiável: **quantos chamados cada grupo de solução abriu, resolveu, fechou e cancelou num período**.

A Fase 2 deu ao usuário um conjunto de escolhas ("o que contar", "agrupar por", filtro "grupo do chamado", filtro "grupo do analista", status...). Na prática, isso se mostrou impreciso:

- Muitas combinações são válidas, mas cada uma responde uma pergunta diferente, e algumas se contradizem (contar "Resolvidos" filtrando status = aberto).
- "Grupo" muda de significado conforme a contagem: nos eventos é o grupo no momento, em "Abertos" e "Cancelados" é o grupo **atual**.
- A abertura não guarda o grupo de quem abriu. Se o agente troca de time, as aberturas antigas vão junto. O cancelamento e a reabertura não geram evento.

Regra definida pelo usuário: **o grupo de um número é o grupo da pessoa que executou a ação, no momento em que ela executou**. O N1 e qualquer outro grupo podem abrir e resolver. Um chamado aberto pelo N1 e resolvido pelo N2 conta como aberto para o N1 e resolvido para o N2. O rastreio precisa ser exato desde a abertura.

## 2. Objetivos

1. **Rastreio de autoria em todo o ciclo de vida.** Abertura, resolução, fechamento, cancelamento e reabertura registram quem agiu e o grupo dessa pessoa naquele momento, sem nunca serem alterados depois.
2. **Painel "Indicadores por grupo"**, que passa a ser a tela padrão de Relatórios:
   - **Entradas:** grupos (um ou vários; vazio = todos os visíveis), período (atalhos atuais + personalizado) e quebra opcional por dia, semana ou mês.
   - **Saída:** uma linha por grupo e colunas fixas **Abertos | Resolvidos | Fechados | Cancelados**, com linha de total.
   - Uma linha extra **"Cliente (portal)"** só na coluna Abertos.
3. **Detalhar por integrante** quando um único grupo está selecionado: uma linha por pessoa, com as mesmas colunas.
4. **Cada número tem uma única definição**, impressa como legenda na tela, na planilha e na imagem.
5. **Clicar em uma célula** abre a lista dos chamados que formam aquele número. A lista sempre bate com o número.
6. **Planilha e imagem se explicam sozinhas**: título, filtros, data de geração e legenda, como na Fase 2.

## 3. Não-objetivos

- **Visão de acompanhamento**, por exemplo "dos chamados que o N1 abriu, quantos já foram resolvidos (por qualquer grupo)". O novo registro de eventos permite isso no futuro, mas fica fora desta entrega.
- Encaminhamentos como coluna do painel. Continuam no relatório avançado.
- Backlog histórico ("quantos estavam em aberto no dia X").
- Novas permissões: continua `relatorios.view` / `relatorios.manage`.

## 4. Anti-objetivos

- Um número cujo grupo depende de outra coisa que não seja o autor da ação no momento da ação.
- Um número que muda depois que o agente troca de grupo ou o chamado é encaminhado.
- Dados históricos aproximados apresentados como exatos (ver 6.3).
- Voltar a pedir ao usuário que combine flags para obter os quatro indicadores básicos.

## 5. Definições dos indicadores

Todas as datas são dias de Brasília, com extremos inclusivos (`App\Support\PeriodoRelatorio`). "Grupo" é sempre o **grupo do autor no momento do evento** (`autor_grupo_solucao_id`).

| Coluna | Conta | Data do período | Grupo da linha |
|---|---|---|---|
| **Abertos** | chamados com evento `aberto` no período | data da abertura | grupo do agente que abriu; "Cliente (portal)" quando aberto pelo cliente |
| **Resolvidos** | chamados distintos com ≥1 evento `resolvido` do grupo na célula | data do evento | grupo de quem resolveu |
| **Fechados** | chamados distintos com ≥1 evento `fechado` do grupo na célula | data do evento | grupo de quem fechou |
| **Cancelados** | chamados distintos com ≥1 evento `cancelado` do grupo na célula | data do evento | grupo de quem cancelou |

- Cada coluna é independente. Um chamado resolvido e depois fechado no período conta 1 em Resolvidos e 1 em Fechados. A legenda diz isso.
- **Chamado reaberto e resolvido de novo** pelo mesmo grupo no mesmo período conta 1 em Resolvidos. Se foi resolvido por grupos diferentes, conta 1 para cada grupo. Se as resoluções caíram em períodos diferentes da quebra, conta 1 em cada período.
- Abertos não depende do status atual: cancelados e reabertos entram.
- **Total:** o total da coluna é a soma das linhas. Quando um chamado aparece em mais de uma linha da mesma coluna (por exemplo, resolvido pelo N1, reaberto e resolvido pelo N2), o rodapé informa "Chamados distintos: N" por coluna. É a mesma regra 6A.5 da Fase 2.
- **Linhas zeradas:** todo grupo selecionado aparece, mesmo com zeros. Sem seleção, as linhas são os grupos visíveis **ativos** mais qualquer grupo (inativo ou excluído) que teve evento no período. Assim, o total sempre bate com a soma das linhas (escolha minha). No detalhamento por integrante, aparecem os membros ativos do grupo **e** quem agiu com esse grupo no período, inclusive usuários desativados ou excluídos (escolha minha).
- **"Cliente (portal)"** (decisão do usuário): chamados abertos pelo portal **nunca** entram na linha de um grupo. "Abertos do N1" significa estritamente "abertos por agentes do N1". A linha "Cliente (portal)" aparece sempre, separada, logo acima do Total, e soma no total da coluna Abertos. Escolha minha: com grupos selecionados (ou no detalhamento por integrante), ela conta só os chamados do portal cujo grupo de destino na abertura (`grupo_solucao_id` do evento `aberto`) está entre os selecionados. Sem seleção, conta todos os visíveis pela regra atual do chamado.
- **"Sem autor registrado"** (escolha minha): mostra eventos sem autor conhecido (backfill) cujo chamado é visível ao usuário. Só aparece sem seleção de grupos, fora do detalhamento por integrante, e só quando não está zerada.

## 6. Rastreio (backend)

### 6.1 Eventos novos em `incidente_eventos`

Novos tipos: `aberto`, `cancelado` e `reaberto`, além dos atuais `resolvido`, `fechado` e `encaminhado_*`. Valem as mesmas regras de hoje: só inserção, sem alteração, com snapshot de `grupo_solucao_id` (grupo do chamado) e `autor_grupo_solucao_id` (grupo do autor) no momento do evento.

- **`aberto`:** gravado na mesma transação do `store()` interno e do `store()` do portal.
  - Pelo portal, não há usuário. O evento precisa de `user_id` anulável e de uma nova coluna `autor_customer_id`. A regra é "exatamente um dos dois preenchido" para todo evento `aberto`. Os demais tipos continuam exigindo `user_id`.
  - `grupo_solucao_id` registra o grupo de destino na abertura. Hoje esse dado se perde quando o chamado é encaminhado.
- **`cancelado`:** gravado na transição de status para `cancelado`, no mesmo ponto em que `resolvido` e `fechado` já são gravados (`registrarEventoDeConclusaoSeAplicavel`).
- **`reaberto`:** gravado na transição de um status concluído (`resolvido`, `fechado` ou `cancelado`) para um status não concluído. Não é coluna do painel. Serve para auditoria e para a lista de chamados explicar por que um chamado conta duas vezes.

### 6.2 Coerência obrigatória

Nenhum caminho de código pode criar um chamado ou mudar seu status sem gravar o evento correspondente. Isso inclui seeders, jobs e o portal. Testes cobrem cada caminho.

### 6.3 Histórico (backfill)

Migration de preenchimento, só com query builder, como a da Fase 1:

| Evento | Fonte | Autor | Grupo do autor |
|---|---|---|---|
| `aberto` | `incidentes.created_at`, `criado_por_id` / `criado_por_customer_id` | exato | **aproximado**: grupo atual do usuário |
| `cancelado` / `reaberto` | entradas de `incidente_descricoes` "Campo 'status' alterado de X para Y" | exato (`user_id` da entrada) | **aproximado**: grupo atual |
| `cancelado` sem entrada de log | `incidentes.concluido_em` | desconhecido (null) | null |

- Chamados antigos sem `criado_por_id` e sem `criado_por_customer_id` ficam com autor desconhecido e aparecem na linha **"Sem autor registrado"**. Essa linha só é exibida quando não está zerada.
- **Datas de exatidão** (escolha minha), ambas em configuração:
  - `relatorios.rastreio_exato_desde`: data do deploy desta entrega. Vale para Abertos e Cancelados.
  - `relatorios.snapshot_conclusao_desde`: data do deploy da Fase 1, que o construtor confirma no histórico de migrations de produção. Vale para Resolvidos e Fechados, porque o backfill da Fase 1 também usou o grupo atual.
- **Aviso (escolha minha):** quando o período começa antes de uma das datas, aparece **um único aviso**, listando só as colunas afetadas. Por exemplo: *"Dados aproximados: antes de dd/mm/aaaa, o grupo usado em Abertos e Cancelados é o grupo atual da pessoa, não o da época; antes de dd/mm/aaaa, o mesmo vale para Resolvidos e Fechados."* O texto é igual na tela, na planilha e na imagem.

### 6.4 Visibilidade

O gestor vê as linhas dos grupos que já são visíveis para ele (regra atual de `grupo_solucao` visível). Um evento entra na contagem quando o **grupo do autor** está entre os visíveis. Isso responde "o que o meu time produziu", mesmo que o chamado esteja hoje com outro grupo. A linha "Cliente (portal)" usa a regra atual de visibilidade do chamado. A lista de chamados segue a regra 6A.4 da Fase 2: chamados que o usuário não pode abrir aparecem sem link e com a marcação "fora dos seus grupos".

## 7. Tela (frontend)

Na página de Relatórios, de cima para baixo:

1. Abas **Indicadores por grupo** (padrão) | **Avançado**. Avançado é o relatório da Fase 2, sem mudanças.
2. Filtros: Grupos (multisseleção), Período (atalhos), Quebra (Não | Dia | Semana | Mês) e "Detalhar por integrante" (habilitado só com um único grupo selecionado).
3. Frase de título, por exemplo: *"Indicadores por grupo de solução — 01/10/2026 a 31/10/2026 — grupos: N1, N2"*.
4. Gráfico de barras agrupadas: grupos no eixo, uma barra por indicador. Com quebra por período, a escolha da forma do gráfico fica com o construtor (ver 9).
5. Tabela: linhas × (Abertos, Resolvidos, Fechados, Cancelados). Com quebra, cada indicador vira um bloco de colunas por período. Há linha de Total e o rodapé de distintos.
6. Legenda com as definições da seção 5 e, quando aplicável, o aviso de 6.3.
7. Botões "Exportar imagem" e "Baixar planilha". Os relatórios salvos guardam grupos, atalho de período, quebra e detalhamento.

## 8. Restrições

- `GET /api/relatorios/incidentes` (legado e Fase 2) continua respondendo exatamente como hoje.
- Stack atual (Laravel + maatwebsite/excel; Vue 3 + Vuetify + Chart.js), sem dependências novas.
- Backend em TDD com PHPUnit no container (`docker compose exec -T app php artisan test`). O frontend é verificado por build (Node 22) e checagem manual.
- Push na `main` publica em produção. A entrega é por branch, com merge só depois de aprovado. A migration de backfill precisa rodar em produção sem travar a tabela por muito tempo (as bases são pequenas: centenas a poucos milhares de linhas).

## 9. Fica a cargo do construtor

- Formato da API do painel. Escolha minha: endpoint novo, por exemplo `GET /api/relatorios/indicadores`, mais um endpoint de lista por célula (ou reaproveitar `ListaChamados`).
- Como o caminho "Avançado" passa a usar os novos eventos (`aberto` e `cancelado`). Isso é desejável para que os dois caminhos concordem, mas não pode mudar os números dos relatórios salvos sem aviso. Se mudar, registre no plano quais casos mudam.
- Forma do gráfico com quebra por período.
- Organização do código (`FonteEventos` já cobre eventos com snapshot e é o ponto natural para reaproveitar).
- Visual exato, seguindo o padrão atual da tela.
