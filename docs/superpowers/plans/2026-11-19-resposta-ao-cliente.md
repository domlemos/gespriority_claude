# Resposta ao cliente no chamado — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O staff responde ao cliente pelo chamado: a resposta entra no feed (staff e portal) e vai por e-mail ao cliente.

**Architecture:** Novo tipo `resposta` em `incidente_descricoes`, criado por uma rota própria (`POST /incidentes/{incidente}/respostas`). O portal inclui `resposta` no filtro do detalhe, como já faz com `encerramento`. O e-mail é uma notificação em fila no molde de `ChamadoFechado`. No front do staff, um segundo botão no `IncidentFeed.vue` abre o mesmo modal em modo "resposta".

**Tech Stack:** Laravel 13 (PHP), PHPUnit em SQLite via Docker; Vue 3 + Vuetify + vue-i18n.

**Spec:** `docs/superpowers/specs/2026-11-19-resposta-ao-cliente-design.md`

## Global Constraints

- Todo backend change documentado em `BACKEND_SPECS.md` **no mesmo commit** (regra do projeto).
- Testes do backend: `docker compose exec app php artisan test --filter <Classe>` (o container monta este checkout em `/app`; não usar worktree no backend).
- Frontend: o checkout `/home/daniel/Workspace/gespriority_claude_front` é compartilhado com outras sessões — trabalhar numa worktree `../gespriority_claude_front-resposta` (branch `feat/resposta-ao-cliente`, `node_modules` por symlink) e conferir `git branch --show-current` antes de cada commit.
- Textos visíveis em PT-BR com tradução EN (`lang/en.json` no backend; `src/locales/en.json` + `pt-BR.json` no front, validados por `npm run check:locales`).
- `descricao` da resposta: obrigatória, string, até 5000 caracteres.
- Resposta: qualquer status do chamado, não altera o status, imutável (sem edição/exclusão).
- E-mail só ao `customer` do chamado, no idioma dele (`Customer` já implementa `HasLocalePreference`).
- Não dar push em `main` sem pedir (push em `main` faz deploy de produção).

## Review Focus

- Resposta em branco (só espaços) → `422` e nenhum e-mail: o `TrimStrings` transforma em vazio; teste em Task 1/2.
- Texto com HTML/`<script>` → escapado no e-mail (`{{ }}`, nunca `{!! !!}`); teste em Task 2.
- Usuário só com `tickets.view` ou chamado de grupo que ele não enxerga → `403`, nada criado nem enviado; teste em Task 1.
- `PUT`/`DELETE` em `/incidentes/{id}/descricoes/{resposta}` (mesmo pelo autor) → `403`; teste em Task 1.
- Outro cliente nunca vê a resposta no portal (`404` no detalhe de chamado alheio já existe; a resposta aparece só no chamado certo); teste em Task 1.

---

### Task 1: Tipo `resposta`, rota de criação e visibilidade no portal

**Files:**
- Modify: `app/Models/IncidenteDescricao.php` (constantes `TIPO_*`, `TIPOS`, `deveAuditar()`)
- Create: `app/Http/Controllers/Api/IncidenteRespostaController.php`
- Modify: `routes/api.php` (junto do bloco de `incidentes.descricoes`, ~linha 188)
- Modify: `app/Http/Controllers/Api/Portal/IncidenteController.php:63-72` (filtro do `show()`)
- Modify: `BACKEND_SPECS.md` (tabela `incidente_descricoes` ~l.557, endpoints §3.4.7.1 ~l.1325, nota "feed do staff é interno" ~l.2122, auditoria §3.4.11, tabela de testes ~l.2464)
- Test: `tests/Feature/Incidentes/IncidenteRespostaTest.php`

**Interfaces:**
- Produces: `IncidenteDescricao::TIPO_RESPOSTA = 'resposta'`; `IncidenteRespostaController::store(Request $request, Incidente $incidente): JsonResponse` → `201` com `IncidenteDescricaoResource`; rota `POST /api/incidentes/{incidente}/respostas` (middleware `auth:web`, `can:tickets.manage`). Task 2 adiciona o envio do e-mail dentro desse `store()`.

- [ ] **Step 1: Escrever os testes que falham** em `IncidenteRespostaTest` (setUp no molde de `IncidenteEmailFechamentoTest`: staff admin com `tickets.view`/`tickets.manage`/`tickets.edit_all` e token `spa`):
  - `test_staff_cria_resposta`: `POST /api/incidentes/{id}/respostas` `{descricao: 'Já estamos verificando.'}` → `201`, `data.tipo === 'resposta'`, `data.user.id === staff->id`; banco tem a linha com `tipo='resposta'`, `user_id` do staff, `customer_id` null; ignora `tipo`/`user_id` enviados no payload.
  - `test_resposta_nao_muda_status`: para cada status em `['aberto','pendente','fechado','cancelado']` → `201` e `status` do chamado inalterado.
  - `test_validacao`: sem `descricao`, `'   '`, e `str_repeat('a', 5001)` → `422` em `descricao`; nada gravado.
  - `test_sem_tickets_manage_e_403`: usuário só com `tickets.view` → `403`.
  - `test_resposta_e_imutavel`: `PUT` e `DELETE` em `/api/incidentes/{id}/descricoes/{resposta_id}` pelo próprio autor → `403`.
  - `test_portal_mostra_resposta_ao_dono`: cliente dono faz `GET /api/customer/incidentes/{id}` (ver `PortalChamadoDetalheTest` para o header do token de customer) → `descricoes` contém a resposta com `tipo: 'resposta'`, `autor.equipe: true`; um comentário interno `comentario` do staff no mesmo chamado **não** aparece.
  - `test_resposta_entra_no_log_de_acoes`: criar resposta grava `AuditLog` `criado` para a entidade `IncidenteDescricao` (ver `AuditLogCapturaTest` para a asserção usada com comentários).

- [ ] **Step 2: Rodar e ver falhar**
  Run: `docker compose exec app php artisan test --filter IncidenteRespostaTest`
  Expected: FAIL (`404` na rota / constante inexistente).

- [ ] **Step 3: Implementar**
  - `TIPO_RESPOSTA` em `IncidenteDescricao`, incluído em `TIPOS`; `deveAuditar()` passa a aceitar `comentario` e `resposta` (atualizar o docblock).
  - `IncidenteRespostaController::store()`: `$this->authorize('view', $incidente)`, valida `['descricao' => ['required', 'string', 'max:5000']]`, cria via `$incidente->descricoes()->create([...])` com `user_id` autenticado e `tipo` forçado, devolve `IncidenteDescricaoResource` com `user`/`customer` carregados e `201`.
  - Rota: `Route::middleware(['auth:web', 'can:tickets.manage'])->post('incidentes/{incidente}/respostas', [IncidenteRespostaController::class, 'store']);` com comentário curto.
  - Portal: `->orWhere('tipo', IncidenteDescricao::TIPO_RESPOSTA)` ao lado de `TIPO_ENCERRAMENTO`; atualizar o comentário do método.
  - `ensureCanModify()` já bloqueia tudo que não é `comentario` — não mexer, só confirmar pelo teste.

- [ ] **Step 4: Rodar e ver passar**, mais as suítes vizinhas
  Run: `docker compose exec app php artisan test --filter 'IncidenteRespostaTest|IncidenteDescricao|PortalChamadoDetalheTest|AuditLogCapturaTest'`
  Expected: PASS.

- [ ] **Step 5: Atualizar `BACKEND_SPECS.md`** (tipo na tabela, linha da rota no §3.4.7.1 com as regras: qualquer status, não muda status, imutável; nota do portal passa a listar `resposta`; auditoria registra `comentario` e `resposta`; linha da nova classe de teste).

- [ ] **Step 6: Commit**
```bash
git add app/Models/IncidenteDescricao.php app/Http/Controllers/Api/IncidenteRespostaController.php routes/api.php app/Http/Controllers/Api/Portal/IncidenteController.php tests/Feature/Incidentes/IncidenteRespostaTest.php BACKEND_SPECS.md
git commit -m "feat(incidentes): resposta ao cliente no feed, visível no portal"
```

### Task 2: E-mail da resposta ao cliente

**Files:**
- Create: `app/Notifications/RespostaAoCliente.php`
- Create: `app/Mail/RespostaAoClienteMail.php`
- Create: `resources/views/emails/resposta-cliente.blade.php` (cópia estrutural de `chamado-fechado.blade.php`)
- Modify: `app/Http/Controllers/Api/IncidenteRespostaController.php` (disparo)
- Modify: `lang/en.json` (novas strings; `pt_BR.json` não lista as chaves PT — o texto PT é a própria chave)
- Modify: `BACKEND_SPECS.md` (nota de e-mail junto do §3.4.7.1 e a nota de idioma ~l.1013)
- Test: `tests/Feature/Incidentes/IncidenteRespostaTest.php` (e-mail), `tests/Feature/I18n/EmailsIdiomaTest.php` (idioma)

**Interfaces:**
- Consumes: `IncidenteRespostaController::store()` (Task 1).
- Produces: `RespostaAoCliente(public readonly IncidenteDescricao $resposta)` — `ShouldQueue`, `afterCommit()`, canal `mail`; `RespostaAoClienteMail(string $cliente, int $numero, string $titulo, string $agente, string $mensagem, string $url)`, `url = rtrim(config('app.frontend_url'), '/')."/portal/incidentes/{id}"`.

Textos fixos (PT → EN):
- Assunto: `"[#{numero}] ".__('Nova resposta ao seu chamado').": {titulo}"` → "New reply to your ticket"
- Rótulo do topo: `Nova resposta` → "New reply"
- Abertura: `Olá, :cliente. :agente, da equipe de suporte, respondeu ao seu chamado:` → "Hello, :cliente. :agente, from the support team, replied to your ticket:"
- Fecho: `Você pode acompanhar e comentar o chamado pelo portal.` → "You can follow and comment on the ticket in the portal."
- Botão/link/rodapé: reutilizar as chaves já existentes de `chamado-fechado` (`Ver o chamado`, `Se o botão não funcionar…`, `e-mail automático, não responda.`).

- [ ] **Step 1: Escrever os testes que falham**
  - `IncidenteRespostaTest::test_resposta_envia_email_ao_cliente`: `Notification::fake()`, criar resposta → `assertSentTo($customer, RespostaAoCliente::class, fn ($n) => $n->resposta->descricao === 'Já estamos verificando.')`, `assertCount(1)`; o responsável/grupo do chamado não recebe nada.
  - `test_resposta_recusada_nao_envia`: `422` (descrição em branco) → `Notification::assertNothingSent()`.
  - `test_conteudo_do_email`: `(new RespostaAoCliente($resposta))->toMail($customer)` → destinatário = e-mail do cliente; `render()` contém nome do agente, o texto, o link `/portal/incidentes/{id}`; com `descricao = '<script>alert(1)</script>'` o HTML contém `&lt;script&gt;` e não `<script>alert`.
  - `EmailsIdiomaTest::test_resposta_vai_no_locale_do_customer_mesmo_com_analista_em_pt` e `test_resposta_renderiza_em_en_e_pt` no molde dos testes de `ChamadoFechado` (assunto `New reply to your ticket` em `en`, `Nova resposta ao seu chamado` em `pt_BR`).

- [ ] **Step 2: Rodar e ver falhar**
  Run: `docker compose exec app php artisan test --filter 'IncidenteRespostaTest|EmailsIdiomaTest'`
  Expected: FAIL (classe `RespostaAoCliente` inexistente).

- [ ] **Step 3: Implementar** notificação, mailable e view nos moldes de `ChamadoFechado`/`ChamadoFechadoMail`/`chamado-fechado.blade.php` (sem o parágrafo de reabertura); no `store()`, depois do `create`, `$incidente->customer()->first()?->notify(new RespostaAoCliente($resposta))`; strings EN em `lang/en.json`.

- [ ] **Step 4: Rodar e ver passar**, mais a suíte inteira
  Run: `docker compose exec app php artisan test`
  Expected: PASS (toda a suíte).

- [ ] **Step 5: Atualizar `BACKEND_SPECS.md`** (nota "E-mail ao cliente a cada resposta": notificação, fila + afterCommit, destinatário, assunto, conteúdo, link; incluir `RespostaAoCliente` na nota de idioma; linha de testes).

- [ ] **Step 6: Commit**
```bash
git add app/Notifications/RespostaAoCliente.php app/Mail/RespostaAoClienteMail.php resources/views/emails/resposta-cliente.blade.php app/Http/Controllers/Api/IncidenteRespostaController.php lang/en.json tests/Feature/Incidentes/IncidenteRespostaTest.php tests/Feature/I18n/EmailsIdiomaTest.php BACKEND_SPECS.md
git commit -m "feat(incidentes): e-mail ao cliente a cada resposta do staff"
```

### Task 3: Botão "Responder ao usuário" no feed do staff

**Files (repo `gespriority_claude_front`, na worktree):**
- Modify: `src/services/incidentDescriptionService.js` (novo método)
- Modify: `src/components/IncidentFeed.vue` (botão, modo do modal, chip da entrada)
- Modify: `src/locales/pt-BR.json`, `src/locales/en.json` (`incidents.feed.*`)
- Sem mudança em `src/components/PortalIncidentModal.vue`: ele já renderiza qualquer entrada da equipe com nome + "(equipe de suporte)" — só conferir.

**Interfaces:**
- Consumes: `POST /incidentes/{id}/respostas` `{descricao}` → `201` `{data: entrada}` (Task 1).
- Produces: `incidentDescriptionService.reply(incidentId, payload)` no mesmo formato de retorno de `create()`.

Comportamento:
- Rodapé do feed (só com `tickets.manage`): dois botões lado a lado, mesma largura — "Adicionar comentário" (atual) e **"Responder ao usuário"** (`mdi-reply`, `variant="tonal"`).
- O modal ganha um modo `reply`: título "Responder ao usuário", `v-alert` `type="info"` `variant="tonal"` com "O cliente verá esta resposta no portal e a receberá por e-mail. Depois de enviada, não pode ser editada.", placeholder "Escreva a resposta ao cliente...", botão de confirmação "Enviar resposta". Erro: "Não foi possível enviar a resposta." Limite visual: `counter="5000"`.
- Entrada `tipo === 'resposta'` no feed: chip igual ao de `encerramento`, `color="info"`, `prepend-icon="mdi-email-fast-outline"`, texto "Resposta · enviada ao cliente". Sem lápis/lixeira (`isOwnComment` já restringe a `comentario`).
- Chaves EN: "Reply to user", "Reply to user" (título), "The customer will see this reply in the portal and receive it by e-mail. Once sent, it cannot be edited.", "Write your reply to the customer...", "Send reply", "Could not send the reply.", "Reply · sent to customer".

- [ ] **Step 1: Criar a worktree**
```bash
cd /home/daniel/Workspace/gespriority_claude_front && git worktree add ../gespriority_claude_front-resposta -b feat/resposta-ao-cliente main && ln -s ../gespriority_claude_front/node_modules ../gespriority_claude_front-resposta/node_modules
```

- [ ] **Step 2: Implementar** serviço, botão, modo `reply` do modal e chip conforme acima; após enviar, a entrada criada entra no fim de `entries` como em `create()`.

- [ ] **Step 3: Verificar**
  Run: `npm run check:locales && npm run build` (na worktree)
  Expected: sem chaves faltando; build ok.

- [ ] **Step 4: Verificar no app** (skill `run`): abrir um chamado como staff, enviar resposta → aparece com o chip, sem editar/excluir; no portal (cliente do chamado) a resposta aparece como "(equipe de suporte)"; o e-mail sai no Mailpit/log do ambiente de dev.

- [ ] **Step 5: Commit** (conferir `git branch --show-current` = `feat/resposta-ao-cliente`)
```bash
git add src/services/incidentDescriptionService.js src/components/IncidentFeed.vue src/locales/pt-BR.json src/locales/en.json
git commit -m "feat(incidentes): botão Responder ao usuário no feed do chamado"
```
