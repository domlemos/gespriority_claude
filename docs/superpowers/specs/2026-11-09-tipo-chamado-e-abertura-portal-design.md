# Tipo de chamado (Incidente/Solicitação) e abertura de incidente pelo portal do cliente

**Data:** 2026-11-09
**Repos afetados:** `gespriority_claude` (API) e `gespriority_claude_front` (Vue/Vuetify)

## Intenção

Pedido original: "Na tela de Novo Incidente, já trazer o nome do usuário logado e a empresa a
que ele está vinculado, permitindo alterar, pois ele pode estar abrindo o chamado para outra pessoa."

A análise mostrou que hoje só o staff (`User`) abre chamados, e o staff não tem empresa — então o
pedido só faz sentido no portal do cliente (`Customer`), que ainda não abre chamados. Na conversa,
três regras de negócio surgiram e redesenharam o pedido:

1. **Todo chamado passa a ter um tipo: Incidente ou Solicitação.** É só categorização. Os grupos de
   solução (N1, N2, Sustentação, Produtos) atendem incidentes via de regra, mas pontualmente
   atendem solicitações de cliente interno (ex.: adequação de infraestrutura, um script, contatar
   um cliente para informar algo).
2. **Staff abre os dois tipos; o cliente (portal) só abre Incidente.**
3. **Por auditoria, o cliente só abre incidente em nome próprio.** Nome e empresa vêm preenchidos
   e travados. "Abrir para outra pessoa" é papel do staff.

O "cliente interno" não é uma entidade nova: a própria empresa já é cadastrada como um `Client`,
com seus funcionários como `Customer`. O usuário confirmou que **não é preciso marcar qual
empresa é a interna** — basta o staff buscar pela empresa ao abrir o chamado.

## Objetivos

- Campo `tipo` (`incidente` | `solicitacao`) em todo chamado, visível, filtrável e auditado.
- Formulário do staff com **Empresa → Solicitante** em selects separados (o solicitante é filtrado
  pela empresa escolhida).
- Cliente do portal consegue abrir um Incidente com nome/empresa já preenchidos e não editáveis.
- Fica registrado quando o chamado foi aberto pelo próprio cliente (auditoria).

## Não-objetivos

- Fluxo, status, SLA ou taxonomia próprios para Solicitação — os dois tipos compartilham tudo.
- Marcar empresa como "interna" ou restringir Solicitação a uma empresa específica.
- Pré-preencher solicitante/empresa na tela do staff (o staff não é `Customer`).
- Renomear a entidade `Incidente` no código/rotas/tabelas para "Chamado". O código continua
  `Incidente`; só rótulos de UI mudam onde indicado.
- Acompanhamento pelo portal (listar "meus chamados", ver detalhe, comentar, anexar) — fica para
  uma entrega futura. Ver Pendências.

## Anti-objetivos (seria falha mesmo funcionando)

- Cliente conseguir abrir chamado em nome de outra pessoa ou de outra empresa, por qualquer
  caminho (payload, troca de id) — o `customer_id` do portal vem **sempre** do token.
- Cliente conseguir ver a lista de pessoas/empresas de outros clientes.
- Cliente conseguir definir grupo, responsável, status, prioridade, origem ou tipo.
- Quebrar chamados existentes ou clientes da API que ainda não enviam `tipo`.

## Entrega 1 — Tipo do chamado + formulário do staff

### Backend

- **Migration** `add_tipo_to_incidentes_table`: coluna `tipo` `string`, `NOT NULL`,
  `default 'incidente'` (backfill implícito: todos os existentes viram `incidente`). Índice simples
  em `tipo` (filtro do dashboard/relatório).
- **`Incidente`**: `const TIPOS = ['incidente', 'solicitacao']`; `tipo` no `#[Fillable]`.
- **`IncidenteController::store()`**: `'tipo' => ['sometimes', 'string', Rule::in(Incidente::TIPOS)]`;
  ausente → `incidente`. *(Decisão minha: `sometimes` em vez de `required` para não quebrar quem
  chama a API sem o campo; o front sempre envia.)*
- **`update()`**: `tipo` editável (`sometimes`), e entra em
  `Incidente::CAMPOS_RESTRITOS_APOS_ABERTURA` (rótulo "Tipo") — só `tickets.edit_all` muda depois
  da abertura, mesmo tratamento de Cliente/Título/Origem. Mudança gera entrada no feed via
  `logAlteracaoSeMudou()` (rótulo "Tipo", valores exibidos "Incidente"/"Solicitação"), seguindo o
  padrão de auditoria dos demais campos.
- **Filtro `tipo`** (igualdade exata, validado com `Rule::in(Incidente::TIPOS)`) em:
  `IncidenteController::index()` (`scopeFiltros`), `DashboardController`,
  `Incidente::scopeFiltrosRelatorio`, **`IncidenteEvento::scopeFiltrosRelatorio`** (usado pelos
  agrupamentos `resolvido_por`/`fechado_por`/`encaminhado_*` — sem ele o filtro é ignorado
  silenciosamente nesses agrupamentos) e na validação `filtros.*` de
  `RelatorioSalvoController::validated()` (senão relatórios salvos descartam a chave).
- **`IncidenteResource`** e **`IncidenteDashboardResource`**: expõem `tipo`.
- O export .xlsx é de contagens agregadas, não de linhas — não ganha coluna; respeita o filtro.
- **`BACKEND_SPECS.md`**: documentar a coluna, a regra de restrição e o filtro.

### Frontend (`IncidentFormView.vue` e afins)

- Título da tela de criação: "Novo Chamado" (edição continua `Incidente #id` → passa a
  `Chamado #id`). *(Decisão minha; botão "Novo" no dashboard acompanha.)*
- Campo **Tipo** (Incidente/Solicitação), obrigatório no form, default Incidente; editável depois
  só com `tickets.edit_all` (mesma regra visual dos campos restritos já existente).
- Substituir o select único "Cliente" por dois:
  - **Empresa** — `GET /lookups/clients`.
  - **Solicitante** — `GET /lookups/customers` (já devolve `client`), filtrado no cliente pela
    empresa escolhida; desabilitado até escolher empresa; trocar a empresa limpa o solicitante.
  - Na edição/clone, a empresa é derivada do solicitante carregado.
  - `?customer_id=` na URL continua pré-selecionando (e deriva a empresa).
  - O payload continua mandando só `customer_id` — **não** existe `client_id` no incidente; a
    empresa é sempre derivada do solicitante (e é ela que resolve o SLA).
- Dashboard: coluna e filtro "Tipo". Relatórios: filtro "Tipo".

### Testes

Feature tests: `store` sem `tipo` → `incidente`; com `solicitacao`; valor inválido → 422; `update`
de `tipo` sem `edit_all` → 422, com `edit_all` → ok + entrada no feed; filtro `tipo` em index,
dashboard e relatório.

## Entrega 2 — Abertura de incidente pelo portal do cliente

### Backend

- **Rota** `POST /api/customer/incidentes`, `auth:customer`, com throttle próprio. Controller
  separado (ex.: `Portal\IncidenteController`) — não reaproveitar o `store()` do staff, que aceita
  campos que o cliente não pode tocar.
- **Payload aceito:** `titulo` (required, max 255), `descricao` (required), `item_id` (ver
  Pendências). Qualquer outro campo é ignorado.
- **Fixado no servidor:** `customer_id = $request->user()->id`; `tipo = 'incidente'`;
  `origem = 'portal'`; `status = 'aberto'`; `grupo_solucao_id`/`responsavel_id = null`;
  prioridade e prazos de SLA pelo mesmo caminho do staff (`prioridadeDoItem()` +
  `calcularPrazosSla()`, hoje privados no `IncidenteController` — extrair para um lugar
  compartilhado, sem duplicar; o que for compartilhado não pode exigir um `User` autor).
- **Autoria:**
  - Nova coluna `criado_por_customer_id` (FK `customers`, nullable, `restrictOnDelete`), fora do
    `#[Fillable]`, setada só por esse endpoint. `criado_por_id` (staff) fica `null` nesses chamados.
    Relação `criadoPorCustomer()`.
  - A descrição de abertura vira a primeira entrada do feed como hoje, mas
    `incidente_descricoes.user_id` é FK `users` `NOT NULL`. Adicionar `customer_id` nullable em
    `incidente_descricoes` e tornar `user_id` nullable, com check de "exatamente um dos dois".
    *(Decisão minha: colunas explícitas em vez de morph, seguindo o padrão `criado_por_*`.)* O feed
    do staff precisa exibir o autor cliente (ex.: "Fulano (cliente)") — `IncidenteDescricaoResource`
    e `IncidentFeed.vue` hoje só leem `user.name`. A entrada do cliente não é editável por ninguém
    (`ensureCanModify` compara com `user_id` de staff — comportamento desejado).
  - A migration precisa funcionar em Postgres 16 (produção) e SQLite em memória (testes),
    inclusive o check "exatamente um autor" e o `user_id` nullable.
- **Relatório "aberto por"** (agrupa por `criado_por_id`): chamados do portal entram num grupo
  próprio "Portal (cliente)", separado do "(desconhecido)" dos chamados legados.
  *(Decisão minha.)*
- **Tipo em chamados do portal:** continua campo restrito — se a triagem achar que era
  Solicitação, só `tickets.edit_all` corrige. *(Decisão minha, coerente com a Entrega 1.)*
- **Notificações:** nenhuma (o sistema não tem notificação de chamado hoje); o chamado aparece na
  fila sem grupo. *(Decisão minha — fora de escopo.)*
- **Triagem:** chamado do portal nasce sem grupo → já é visível a todo staff pela regra atual de
  `visiveisPara()` (grupo nulo é visível a todos). Nenhuma mudança necessária.
- **`/me`** (rota compartilhada `auth:web,customer`): quando o autenticado for `Customer`,
  `presentAuthenticatable()` passa a incluir `client: {id, name}` para o portal exibir a empresa.
- Resposta `201` com `{id, titulo, status, created_at}` — o mínimo, sem dados internos
  (grupo/responsável/SLA).

### Frontend

- Nova tela no portal "Abrir incidente" (rota `guard: 'customer'`), linkada do `PortalView`
  (substituindo o card "ainda não implementado").
- **Solicitante** e **Empresa** exibidos preenchidos com o cliente logado, somente leitura.
- Campos: Título, Descrição, Classificação (ver Pendências).
- Após criar: mensagem com o número do chamado.

### Testes

Feature tests: cliente cria incidente e `customer_id` é o dele mesmo se o payload mandar outro;
`tipo`/`origem`/`grupo`/`responsavel`/`status`/`prioridade` no payload são ignorados;
`criado_por_customer_id` setado e `criado_por_id` nulo; feed tem a descrição com autor cliente;
token de staff em `/customer/incidentes` → 401/403; token de cliente nas rotas de staff continua
401/403.

## Pendências (a decidir antes de implementar a Entrega 2)

- **Classificação no portal.** A prioridade e o SLA vêm do `item_id`, hoje obrigatório na abertura.
  Ou o cliente escolhe o item (expor a taxonomia ao portal — e na prática ele escolhe a
  prioridade), ou o portal abre sem item e o staff classifica na triagem (exige `item_id` nullable
  na abertura e SLA calculado quando o item for definido).
- **Acompanhamento pelo portal** (listar/ver/comentar os próprios chamados) — fora deste escopo,
  mas sem ele o cliente abre e não vê mais nada.

## Deixado para quem implementar

- Nomes exatos de classes/arquivos, texto das mensagens e layout fino das telas.
- Ordem: Entrega 1 é independente e vem primeiro; Entrega 2 depende só da extração do cálculo de
  SLA.
