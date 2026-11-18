# Idioma PT-BR / EN no backend — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A API responde em `pt_BR` ou `en` conforme o `Accept-Language`, cada pessoa tem um idioma salvo usado nos e-mails, e o SPA envia o idioma e sincroniza a preferência.

**Architecture:** Middleware no grupo `api` define o locale por requisição (sempre, inclusive o default — Octane reaproveita o processo). Textos do servidor usam `__()` com a frase em PT como chave e `lang/en.json` como tradução; constantes de rótulo mantêm o PT e são traduzidas na leitura. `User`/`Customer` ganham coluna `locale` e implementam `HasLocalePreference`, então notificações saem no idioma do destinatário; o reset de senha usa o idioma da requisição.

**Tech Stack:** Laravel 13 + Octane (FrankenPHP), PHPUnit 12 (SQLite em memória), Vue 3 + vue-i18n no SPA.

**Spec:** `docs/superpowers/specs/2026-11-18-idioma-backend-design.md` (neste repositório) — leia antes de qualquer tarefa.

## Global Constraints

- Backend em `/home/daniel/Workspace/gespriority_claude`, frontend em `/home/daniel/Workspace/gespriority_claude_front`. Trabalho direto em `main` nos dois. Sem push.
- **O backend tem alterações não commitadas do usuário** (`database/seeders/CategoriasSeeder.php`, `database/seeders/IncidentesSeeder.php`, `infra/terraform/modules/data/main.tf`) e a pasta `.superpowers/`. Nunca `git add -A`/`git add .`, nunca `git stash`/`checkout` nesses arquivos: adicione por caminho só o que a tarefa criou/alterou.
- Testes backend: `php artisan test --compact` (PHP 8.3 local, SQLite em memória). A suíte inteira (743 testes hoje) deve continuar verde **sem alterar nenhum assert de texto PT existente**.
- Estilo: `vendor/bin/pint <arquivos alterados>` antes de cada commit.
- Locales: backend `pt_BR` | `en`; front `pt-BR` | `en`. Default `config('app.locale')` = `pt_BR`.
- PT intocado: a frase PT vira a chave de `__()` byte a byte. `lang/en.json` contém só traduções para inglês. Interpolação com `:nome`.
- Não traduzir: histórico do chamado (`incidente_descricoes`, inclusive textos gerados ao gravar), dados cadastrados, nomes de arquivo de `Content-Disposition`, mensagens já em inglês do `AuthController`.
- Inglês: americano, curto, coerente com `gespriority_claude_front/src/locales/en.json` (ex.: "Customer Users", "Solution Group", "SLA breached" — consulte o arquivo).
- Commits: `feat(i18n): …` / `docs: …`, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Frontend: `source ~/.nvm/nvm.sh && nvm use 22.14.0` antes de `npm`; `npm run check:locales && npm run build` verdes.

## Review Focus

1. **Locale vazando entre requisições no Octane** — uma requisição `en` seguida de outra sem header deve responder em PT. Teste na Task 1.
2. **Variações do header** (`en-US,en;q=0.9`, `EN`, `pt-BR`, `pt`, `fr`, vazio) — `en*` → en, `pt*` → pt_BR, resto → pt_BR. Teste na Task 1.
3. **Texto PT do servidor usado como identificador** (front comparando `rotulo`, `Rule::in` sobre rótulos, chave de agrupamento) — traduzir quebraria a lógica. Checagem explícita na Task 3.
4. **E-mail em fila** sai no idioma do destinatário, não no de quem disparou. Teste na Task 5.
5. **Frase PT alterada sem atualizar `en.json`** — teste de cobertura (Task 1) falha.

---

### Task 1: Middleware de locale, arquivos de idioma e teste de cobertura

**Files:**
- Create: `app/Http/Middleware/SetLocaleFromRequest.php`
- Modify: `bootstrap/app.php` (registrar no grupo `api`: `$middleware->api(append: [SetLocaleFromRequest::class])`)
- Create: `lang/en.json` (`{}` + o que os testes desta tarefa precisarem)
- Create: `lang/en/validation.php` (via `php artisan lang:publish`, manter só `validation.php` em `lang/en/`; adicionar `attributes` em inglês com as mesmas chaves de `lang/pt_BR/validation.php`)
- Create: `tests/Feature/I18n/LocaleMiddlewareTest.php`
- Create: `tests/Feature/I18n/TraducoesCobertasTest.php`

**Interfaces:**
- Produces: `SetLocaleFromRequest::resolver(?string $header): string` (público estático, testável) — primeiro idioma da lista, case-insensitive: `en*` → `'en'`, `pt*` → `'pt_BR'`, resto/null → `config('app.locale')`. `handle()` chama `App::setLocale(...)` **sempre**.
- Produces: `TraducoesCobertasTest` com:
  - varredura de `app/**/*.php` e `resources/views/emails/**/*.blade.php` por literais em `__('…')`, `__("…")`, `trans('…')`, `@lang('…')` (ignora chaves com `.` e sem espaço, que são chaves de arquivo PHP como `validation.required`) → cada literal deve existir em `lang/en.json`;
  - `private static function constantesDeRotulo(): array` — lista de arrays (constantes) cujos **valores** também devem existir em `lang/en.json`; começa vazia, a Task 3 preenche;
  - falha se `lang/pt_BR.json` existir e alguma chave dele aparecer como literal de `__()` em `app/`.

- [ ] **Step 1: Escrever `LocaleMiddlewareTest`**

Casos (rota real que devolve 422: `POST /api/forgot-password` com corpo vazio):
- `test_sem_header_responde_em_portugues`: mensagem de `errors.email.0` igual à de `lang/pt_BR/validation.php` `required` com atributo PT (o valor exato que a suíte já espera — leia um teste existente que verifique isso, ou `__('validation.required', ['attribute' => 'e-mail'], 'pt_BR')`).
- `test_header_en_responde_em_ingles`: `Accept-Language: en-US,en;q=0.9` → `The email field is required.`
- `test_header_pt_br_responde_em_portugues`: `pt-BR` → PT.
- `test_idioma_desconhecido_cai_no_portugues`: `fr-FR` → PT.
- `test_locale_nao_vaza_para_a_proxima_requisicao`: requisição `en` e em seguida sem header → PT.
- `test_resolver`: data provider com `'en'→'en'`, `'EN'→'en'`, `'en-US,en;q=0.9'→'en'`, `'pt'→'pt_BR'`, `'pt-BR'→'pt_BR'`, `'fr'→'pt_BR'`, `''→'pt_BR'`, `null→'pt_BR'`.

- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --compact --filter=LocaleMiddlewareTest` → FAIL (classe inexistente / mensagens em PT).

- [ ] **Step 3: Implementar middleware, registro, `lang/en.json`, `lang/en/validation.php`**

- [ ] **Step 4: Escrever `TraducoesCobertasTest` e rodar** — passa (ainda não há `__()` com frase PT em `app/`).

- [ ] **Step 5: Suíte inteira** — `php artisan test --compact` → todos verdes (743 + novos).

- [ ] **Step 6: Pint e commit** (arquivos por caminho)

---

### Task 2: Mensagens dos controllers

**Files:**
- Modify: controllers em `app/Http/Controllers/Api/**` com frases PT em respostas (`message`, `abort(…, '…')`, `ValidationException::withMessages`, regras com mensagem customizada): `AnexoController`, `CategoriaController`, `ClientController`, `CustomerController`, `GrupoSolucaoController`, `GrupoSolucaoPermissaoController`, `IncidenteController`, `IncidenteDescricaoController`, `ItemController`, `PoliticaSlaController`, `Portal/IncidenteController`, `RelatorioConsultaController`, `RelatorioController`, `RelatorioSalvoController`, `SubcategoriaController`, `UserController`, `AuditLogController`, `IndicadoresController`, `FluxosController`, `AuthController` (só frases PT).
- Modify: `lang/en.json`
- Create: `tests/Feature/I18n/MensagensControllersTest.php`

**Interfaces:**
- Consumes: middleware e teste de cobertura da Task 1.

Regra: envolver em `__()` toda frase PT **devolvida ao cliente**. **Não** envolver texto gravado no histórico do chamado (`incidente_descricoes.descricao`, mensagem de encerramento, "Campo '…' alterado…") — ver Global Constraints. Frases com valores variáveis viram `__('… :campo …', ['campo' => $x])` (a chave PT continua produzindo o mesmo texto).

- [ ] **Step 1: Teste** — `MensagensControllersTest` com 3 casos representativos, cada um verificando PT sem header e EN com `Accept-Language: en`:
  - excluir categoria com subcategorias (PT: `Não é possível excluir uma categoria com subcategorias vinculadas.`);
  - reenviar convite de usuário (PT: `Convite enviado.`);
  - desativar a própria conta (PT: `Não é possível desativar a própria conta.`).
- [ ] **Step 2: Rodar e ver falhar** (EN ainda em PT).
- [ ] **Step 3: Envolver as frases e preencher `lang/en.json`**
- [ ] **Step 4: Cobertura + suíte** — `php artisan test --compact` → verde; `TraducoesCobertasTest` cobre as chaves novas.
- [ ] **Step 5: Pint e commit**

---

### Task 3: Rótulos de relatório, auditoria, incidente e planilhas

**Files:**
- Modify: `app/Relatorios/Fluxos/TextosFluxos.php`, `app/Relatorios/Indicadores/TextosIndicadores.php`, `app/Relatorios/RotulosRelatorio.php`, `app/Relatorios/TituloRelatorio.php`, `app/Relatorios/ConsultaRelatorio.php`, `app/Relatorios/Indicadores/ConsultaIndicadores.php`, `app/Models/AuditLog.php` (pontos de leitura), `app/Http/Controllers/Api/AuditLogController.php`, `app/Models/Incidente.php` (pontos de leitura de `TIPO_ROTULOS`/rótulos de campo **que vão para respostas**, não para o histórico), `app/Exports/*.php`
- Modify: `lang/en.json`, `tests/Feature/I18n/TraducoesCobertasTest.php` (preencher `constantesDeRotulo()`)
- Create: `tests/Feature/I18n/RotulosTraduzidosTest.php`

**Interfaces:**
- Constantes continuam com o texto PT (chave e `Rule::in`); tradução no ponto de leitura com `__(CONST[$codigo])` (ou `__($valor)` ao percorrer o array). Nenhuma constante muda de forma.

- [ ] **Step 1: Checagem de identificadores (Review Focus 3)** — antes de traduzir cada rótulo, confirmar que o texto não é usado como identificador: `grep` no backend (comparações, `Rule::in`, chaves de array montadas a partir do rótulo) e no front (`gespriority_claude_front/src`: comparações com `rotulo`/`titulo`, ex. `FluxosMapa.vue` tratando "Sem grupo"/"Outros grupos"). Registrar no report o que foi checado. Se algum for identificador, **não traduzir** esse ponto e reportar como concern.
- [ ] **Step 2: Teste** — `RotulosTraduzidosTest`, PT sem header e EN com `en`, um caso por área:
  - mapa de fluxos: nó "Sem grupo" (montar dados mínimos para ele aparecer; seguir o setup de um teste existente em `tests/Feature/Relatorios`);
  - auditoria: `acao_rotulo` de uma ação conhecida (ex. login) em `GET /api/audit-logs`;
  - planilha: cabeçalhos de uma exportação (`Excel::fake()` + `assertDownloaded` com callback inspecionando `headings()`, ou gerar o export e ler `headings()` com `App::setLocale('en')`).
- [ ] **Step 3: Rodar e ver falhar**
- [ ] **Step 4: Traduzir nos pontos de leitura, preencher `lang/en.json` e `constantesDeRotulo()`**
- [ ] **Step 5: Suíte inteira verde** (os testes existentes de relatório em PT não mudam)
- [ ] **Step 6: Pint e commit**

---

### Task 4: Preferência de idioma (coluna, models, `/me`, admin, PATCH)

**Files:**
- Create: migration `add_locale_to_users_and_customers` (`string('locale', 5)->default('pt_BR')` nas duas tabelas; `down` remove)
- Modify: `app/Models/User.php`, `app/Models/Customer.php` (fillable `locale`; `implements HasLocalePreference`; `preferredLocale(): string` → `$this->locale ?: config('app.locale')`)
- Modify: `app/Http/Resources/UserResource.php`, `app/Http/Resources/CustomerResource.php` (campo `locale`); `/me` de customer devolve o model (confirmar que `locale` aparece — não está em `Hidden`)
- Modify: `app/Http/Controllers/Api/UserController.php`, `CustomerController.php` (create/update aceitam `locale`: `['sometimes', 'string', Rule::in(['pt_BR', 'en'])]`)
- Modify: `app/Http/Controllers/Api/AuthController.php` (método `updateLocale(Request $request)`), `routes/api.php` (`Route::patch('/me/locale', …)` no grupo `auth:web`; idem no grupo `customer` → `/customer/me/locale`)
- Modify: factories de `User`/`Customer` se necessário (default do banco já cobre)
- Create: `tests/Feature/I18n/PreferenciaIdiomaTest.php`

**Interfaces:**
- Produces: `PATCH /api/me/locale` e `PATCH /api/customer/me/locale`, corpo `{"locale": "pt_BR"|"en"}` → 204; inválido → 422; sem auth → 401. Sem auditoria.
- Produces: `locale` em `/me` (staff e customer), em `UserResource` e `CustomerResource`.
- Produces: `User::preferredLocale()`, `Customer::preferredLocale()`.

- [ ] **Step 1: Testes** — `PreferenciaIdiomaTest`:
  - `/me` staff e customer trazem `locale = 'pt_BR'` por padrão;
  - `PATCH /me/locale` com `en` → 204 e `users.locale = 'en'`; idem customer;
  - `locale: 'fr'` → 422; sem auth → 401;
  - admin cria usuário com `locale: 'en'` → salvo; sem `locale` → `pt_BR`; update troca; idem customer;
  - `preferredLocale()` devolve o valor salvo.
- [ ] **Step 2: Rodar e ver falhar**
- [ ] **Step 3: Implementar**
- [ ] **Step 4: Suíte inteira verde** (testes existentes de `/me` e recursos que comparam JSON exato devem continuar passando — se algum falhar só por causa do campo novo, ajuste o teste para incluir `locale`, e reporte cada um)
- [ ] **Step 5: Pint e commit**

---

### Task 5: E-mails no idioma do destinatário e redefinição de senha

**Files:**
- Modify: `app/Mail/ConviteUsuarioMail.php`, `app/Mail/ChamadoFechadoMail.php`, `app/Mail/ComentarioDoClienteMail.php` (assuntos com `__()`), `resources/views/emails/*.blade.php` (textos com `__()`/`@lang`)
- Modify: `app/Models/User.php`, `app/Models/Customer.php`: `sendPasswordResetNotification($token)` → `$this->notify((new ResetPassword($token))->locale(App::getLocale()))` (locale da requisição de "esqueci a senha" vence a preferência salva)
- Create: `lang/pt_BR.json` — só as strings em inglês do Laravel usadas no e-mail de reset e no layout de e-mail (`Reset Password Notification`, `You are receiving this email because we received a password reset request for your account.`, `Reset Password`, `This password reset link will expire in :count minutes.`, `If you did not request a password reset, no further action is required.`, `Hello!`, `Regards,`, `Whoops!`, `If you're having trouble clicking the ":actionText" button, copy and paste the URL below\ninto your web browser:`, `All rights reserved.` — conferir as strings exatas em `vendor/laravel/framework/src/Illuminate/Auth/Notifications/ResetPassword.php` e `vendor/laravel/framework/src/Illuminate/Notifications/resources/views/email.blade.php`), traduzidas para PT
- Modify: `lang/en.json`
- Create: `tests/Feature/I18n/EmailsIdiomaTest.php`

- [ ] **Step 1: Testes** (`Notification::fake()`; o callback de `assertSentTo` recebe `$locale` como 4º argumento; para o conteúdo, renderizar o mailable com `->locale($l)->render()` ou `(new XMail(...))->locale('en')->envelope()->subject`):
  - convite para usuário com `locale = 'en'` → enviado com locale `en`, assunto em EN; com `pt_BR` → PT idêntico ao atual;
  - analista com header PT fecha chamado de customer `en` → `ChamadoFechado` com locale `en`;
  - comentário do cliente para 2 usuários (`pt_BR` e `en`) → cada um no seu locale;
  - `POST /api/forgot-password` com `Accept-Language: en` para usuário com `locale = 'pt_BR'` → `ResetPassword` com locale `en`; sem header → `pt_BR`, e o assunto renderizado em PT é a tradução de `Reset Password Notification`.
- [ ] **Step 2: Rodar e ver falhar**
- [ ] **Step 3: Implementar**
- [ ] **Step 4: Suíte inteira verde** (testes existentes de convite/chamado fechado/reset continuam passando; se algum verifica o assunto/texto em inglês do reset, ele muda por decisão aprovada — ajuste e reporte)
- [ ] **Step 5: Pint e commit**

---

### Task 6: Frontend — header, sincronia do seletor e campo Idioma

Repositório: `/home/daniel/Workspace/gespriority_claude_front`.

**Files:**
- Modify: `src/plugins/i18n.js` — `toBackendLocale(locale: 'pt-BR'|'en'): 'pt_BR'|'en'`, `fromBackendLocale(locale: string): 'pt-BR'|'en'` (desconhecido → `'pt-BR'`), `hasExplicitLocale(): boolean` (há valor válido salvo em `upITSM.locale`, try/catch), e um gancho para sincronizar: `setLocale(locale, { persist = true } = {})` continua igual por fora; a sincronia com o servidor fica no store de auth (abaixo) para não acoplar o plugin à API.
- Modify: `src/services/api.js` — interceptor de request: `config.headers['Accept-Language'] = currentLocale()`.
- Modify: `src/stores/auth.js` — ação `syncLocale()` chamada depois de carregar o usuário (login e `/me`): se `hasExplicitLocale()` e difere de `fromBackendLocale(user.locale)` → `PATCH` do locale atual; se não há escolha explícita → `setLocale(fromBackendLocale(user.locale))`. Ação `saveLocale(locale)`: se autenticado, `PATCH /me/locale` (guard `web`) ou `/customer/me/locale` (guard `customer`) com `toBackendLocale(locale)`; erro só `console.warn`.
- Modify: `src/components/LocaleSwitcher.vue` — após `setLocale`, chama `useAuthStore().saveLocale(locale)` (no-op se não autenticado).
- Modify: `src/components/admin/UserFormModal.vue`, `src/components/admin/CustomerFormModal.vue` — campo **Idioma** (`v-select`, itens computed de `LOCALE_OPTIONS` com `value` = código backend, título = nome no próprio idioma via `t('locale.ptBR')`/`t('locale.en')`), default `'pt_BR'`, preenchido com `locale` ao editar, enviado no payload.
- Modify: `src/locales/pt-BR.json`, `src/locales/en.json` — rótulo do campo (`admin.common.language`: "Idioma" / "Language").

- [ ] **Step 1:** Implementar.
- [ ] **Step 2:** `npm run check:locales && npm run build` → verde.
- [ ] **Step 3:** Conferência por código (sem navegador): header presente em toda requisição (inclusive login); `saveLocale` não roda deslogado; `syncLocale` não entra em loop (não chama `saveLocale` ao aplicar o locale do servidor).
- [ ] **Step 4:** Commit no repositório do front.

---

### Task 7: Documentação em `BACKEND_SPECS.md`

**Files:** Modify: `BACKEND_SPECS.md`

Conteúdo exigido (ver spec, seção "Documentação em `BACKEND_SPECS.md`"), no estilo do arquivo:
- §3.1 `users` e `customers`: coluna `locale`.
- Regra do `Accept-Language` (§3.4 introdução ou §3.5), o que é e não é traduzido, `lang/en.json` com chave PT, `lang/pt_BR.json` só para strings do Laravel.
- §3.4.1 e `/me` de customer: `locale`.
- §3.4.3: `locale` em create/update de `User`/`Customer`; `PATCH /api/me/locale` e `/api/customer/me/locale` (corpo, 204/401/422).
- §3.4.3.1: idioma dos e-mails (destinatário) e do reset (requisição).
- §3.8/§3.9: testes novos em `tests/Feature/I18n`.
- Nova §3.12 "Histórico de entregas — Idioma PT-BR/EN (2026-11-18)" no formato das §3.10/§3.11.

- [ ] **Step 1:** Escrever. Conferir cada afirmação contra o código das Tasks 1–6 (nomes de rota, códigos de status, nomes de classe).
- [ ] **Step 2:** Commit `docs: idioma PT-BR/EN no BACKEND_SPECS`.
