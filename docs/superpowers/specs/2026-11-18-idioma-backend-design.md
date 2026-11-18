# Idioma PT-BR / EN no backend — Design

Data: 2026-11-18
Repositórios: `gespriority_claude` (backend Laravel, maior parte) e
`gespriority_claude_front` (ajustes pequenos no SPA).
Etapa anterior (já em produção): seletor PT-BR/EN no frontend —
`gespriority_claude_front/docs/superpowers/specs/2026-11-18-idioma-pt-en-design.md`.

## Intenção

~95% dos usuários estão no Brasil, ~5% em outros continentes. O frontend já
troca a interface entre PT-BR e EN, mas tudo o que vem do servidor continua em
português: erros de validação, mensagens dos controllers, rótulos de
relatório/auditoria, planilhas e e-mails. Esta etapa faz o servidor responder
no idioma de quem usa, e enviar os e-mails no idioma de cada destinatário.

## Objetivos

1. Cada requisição da API responde no idioma do cliente: o SPA manda
   `Accept-Language`; um middleware define o locale (`pt_BR` ou `en`).
2. Traduzidos por requisição:
   - erros de validação (422) — mensagens e nomes de campo;
   - mensagens fixas dos controllers (`message` de 4xx, confirmações como
     "Convite enviado.");
   - rótulos montados no servidor e calculados na leitura: relatórios
     (`TextosFluxos`, `TextosIndicadores`, `RotulosRelatorio`,
     `TituloRelatorio`, `ConsultaRelatorio`, `ConsultaIndicadores`),
     auditoria (`AuditLog::ACOES`, rótulos de entidade), `Incidente`
     (`TIPO_ROTULOS`, rótulos de campo usados em respostas);
   - planilhas exportadas (`app/Exports/*`): cabeçalhos e rótulos.
3. Preferência de idioma por pessoa: coluna `locale` em `users` e `customers`
   (`pt_BR` | `en`, default `pt_BR`).
   - Formulários de admin (usuário e usuário de cliente) ganham o campo
     **Idioma** (default Português (BR)); define o idioma do convite.
   - Com a pessoa logada, o seletor 🌐 do SPA grava a escolha no servidor.
4. E-mails no idioma do destinatário: convite, chamado fechado, comentário do
   cliente (assunto e corpo), via `HasLocalePreference` em `User` e
   `Customer`.

## Não-objetivos

- **Histórico do chamado** (`incidente_descricoes`): textos gravados no banco
  já em português ("Campo 'x' alterado de…", "Atribuído a…", mensagem de
  encerramento) continuam em português, antigos e novos. Decisão do usuário.
- Dados cadastrados (nomes de categoria, item, grupo, SLA, títulos de chamado,
  nomes de relatórios salvos) nunca são traduzidos.
- Nomes de arquivo das planilhas (`Content-Disposition`) continuam em PT — o
  front espelha esses nomes (decisão já tomada na etapa do front).
- Idiomas além de `pt_BR` e `en`.

## Anti-objetivos

- Qualquer texto em PT-BR mudar para quem usa em português (exceto o e-mail
  de redefinição de senha, que passa de inglês para português — aprovado).
- Requisições sem `Accept-Language` (integrações, Bearer, testes existentes)
  mudarem de comportamento: o default continua `pt_BR`.
- Quebrar a suíte de testes existente, que verifica textos em PT.

## Decisões

### Onde ficam as traduções (escolhida: A — texto em PT como chave)

- **A. Strings em PT como chave + `lang/en.json`** — escolhida. O código
  fica `__('Não é possível excluir uma categoria com subcategorias
  vinculadas.')`; em `pt_BR` o Laravel devolve a própria string (não há
  `lang/pt_BR.json` para essas chaves), em `en` busca em `lang/en.json`.
  PT não pode mudar por acidente. Interpolação com `:nome`
  (`__('… :nome …', ['nome' => $x])`).
- B. Chaves curtas em `lang/{pt_BR,en}/*.php` — descartada: copiar ~90 textos
  para o arquivo PT à mão aumenta o risco de alterar o PT, e o código perde a
  frase legível.

Validação: `lang/pt_BR/validation.php` já existe; publicar
`lang/en/validation.php` (`php artisan lang:publish`) e adicionar em
`attributes` os nomes em inglês dos mesmos campos que o arquivo PT traduz.

### Resolução do locale por requisição

- Middleware `SetLocaleFromRequest` no grupo `api` (todas as rotas da API,
  inclusive login, esqueci/redefinir senha).
- Lê `Accept-Language`: começa com `en` → `en`; começa com `pt` → `pt_BR`;
  ausente ou outro → `config('app.locale')` (`pt_BR`). Usa só o primeiro
  idioma da lista (o SPA manda um só).
- O header vence a preferência salva: a tela que a pessoa está vendo é a
  referência para respostas da API. A preferência salva serve para o que
  acontece fora de uma requisição dela (e-mails).

### Preferência salva

- Migration: `locale` string(5) `default 'pt_BR'` em `users` e `customers`.
- `User` e `Customer` implementam
  `Illuminate\Contracts\Translation\HasLocalePreference`
  (`preferredLocale(): string` → `$this->locale ?? config('app.locale')`).
  O Laravel aplica esse locale ao montar notificações e mailables, inclusive
  em fila (`ShouldQueue`).
- `locale` aparece na resposta de `/me` (staff e customer) e nos recursos de
  usuário e customer do admin; aceito em create/update de usuário e de
  customer pelo admin (`in:pt_BR,en`, opcional, default `pt_BR`).
- Endpoint novo `PATCH /api/me/locale` e `PATCH /api/customer/me/locale`
  (mesmos guards de `/me`), corpo `{ "locale": "pt_BR" | "en" }`, 204.
  Auditado como as demais alterações de cadastro? Não — é preferência pessoal,
  sem auditoria (decisão do controller; ver "Deixado para quem implementar").

### E-mails

- Convite, chamado fechado e comentário do cliente: assunto (`Envelope`) e
  views `resources/views/emails/*.blade.php` passam a usar `__()`.
- O locale vem do destinatário (`HasLocalePreference`), não da requisição de
  quem disparou a ação. Ex.: analista em PT fecha o chamado de um cliente com
  `locale = en` → e-mail em inglês.
- Comentário do cliente vai para vários usuários: cada um recebe no seu
  idioma (o `Notification::send` já resolve por destinatário).

### E-mail de redefinição de senha (incluído — aprovado com o design)

Hoje o "esqueci a senha" usa a `ResetPassword` padrão do Laravel, cujas
strings são chaves em inglês e não há `lang/pt_BR.json` — então **esse e-mail
sai em inglês para todo mundo hoje**. Proposta: adicionar
`lang/pt_BR.json` com a tradução dessas strings do Laravel (e só delas), de
modo que o e-mail saia em PT para `pt_BR` e continue em EN para `en`. É uma
mudança visível para os 95% (para melhor).

### Frontend (`gespriority_claude_front`)

- `src/services/api.js`: header `Accept-Language` em toda requisição,
  `currentLocale()` (`pt-BR` / `en`).
- Mapeamento de códigos: front `pt-BR` ↔ backend `pt_BR`; `en` ↔ `en`. Um par
  de funções em `src/plugins/i18n.js`.
- Sincronia do seletor com o servidor:
  - logado e trocou no seletor → `PATCH /me/locale` (ou
    `/customer/me/locale`, conforme o guard); falha não desfaz a troca local
    (só loga).
  - ao carregar o usuário (`/me` após login ou reload): se o navegador tem
    escolha explícita salva (`upITSM.locale`), ela vence e é enviada ao
    servidor quando difere; se não tem, o app adota o `locale` do servidor.
- Formulários `UserFormModal` e `CustomerFormModal`: campo **Idioma**
  (`v-select` com "Português (BR)" / "English", nomes no próprio idioma),
  default `pt_BR`, enviado no create/update.
- IncidentFeed continua casando ícones por `startsWith('Atribuído'…)` — o
  histórico segue em PT, então nada muda ali.

## Verificação

- Backend: suíte existente (`php artisan test`) continua verde sem alterar
  asserts de texto PT. Testes novos:
  - middleware: mesma rota com `Accept-Language: en` devolve a mensagem em EN;
    sem header e com `pt-BR` devolve PT (um 422 de validação e um `message`
    de controller);
  - `PATCH /me/locale` e `/customer/me/locale` (sucesso, valor inválido 422,
    sem auth 401);
  - create/update de usuário e customer com `locale`;
  - e-mails: `Mail::fake()`/`Notification::fake()` verificando o locale do
    destinatário em convite, chamado fechado e comentário do cliente;
  - cobertura: todo literal passado a `__()` em `app/` e
    `resources/views/emails/` existe em `lang/en.json` (teste que varre os
    arquivos).
- Frontend: `npm run check:locales` e `npm run build`; conferência manual.

## Organização sugerida

1. Infra: middleware, `lang/en.json` vazio, `lang/en/validation.php`, teste de
   cobertura das chaves.
2. Mensagens dos controllers.
3. Rótulos de relatório, auditoria, `Incidente` e planilhas.
4. Preferência: migration, models, `/me`, endpoints PATCH, admin create/update.
5. E-mails (+ redefinição de senha em PT via `lang/pt_BR.json`).
6. Frontend: header, sincronia do seletor, campo Idioma nos formulários.
7. Documentação: `BACKEND_SPECS.md` (pedido do usuário) — ver seção abaixo.

## Documentação em `BACKEND_SPECS.md` (entregável obrigatório)

O `BACKEND_SPECS.md` descreve o que está implementado, então é atualizado
junto com o código (última tarefa), seguindo o estilo do arquivo:

- §3.1 `users` e `customers`: coluna `locale` (`pt_BR`|`en`, default `pt_BR`).
- §3.4 (introdução de endpoints) ou §3.5: header `Accept-Language` e regra de
  resolução do locale por requisição; o que é traduzido e o que não é
  (histórico do chamado, dados cadastrados, nomes de arquivo).
- §3.4.1 formato de `user` e o `/me` de customer: campo `locale`.
- §3.4.3: `locale` no create/update de `User` e `Customer`; novos
  `PATCH /api/me/locale` e `PATCH /api/customer/me/locale` (corpo, respostas
  204/401/422).
- §3.4.3.1 convite: idioma do e-mail vem do `locale` do destinatário; mesma
  regra para chamado fechado e comentário do cliente; regra do e-mail de
  redefinição de senha.
- §3.8/§3.9: testes novos.
- Nova seção "3.12. Histórico de entregas — Idioma PT-BR/EN (data da
  entrega)", no formato das §3.10/§3.11.

## Decisões do controller (após a revisão do builder)

- **Locale do e-mail de redefinição de senha:** segue o `Accept-Language` da
  requisição de "esqueci a senha" (pessoa não está logada; a tela é o sinal
  mais confiável), não o `locale` salvo. Implementar aplicando o locale da
  requisição ao enviar (ex.: `Notification::locale(app()->getLocale())` /
  `->locale()` na notificação), com teste.
- **Mensagens já em inglês no `AuthController`** (`'Logged out from all
  devices.'`, `'If that email exists, a reset link has been sent.'`,
  `'Password reset successfully.'`): o front não as exibe; ficam como estão,
  fora do `__()`.
- **Rótulos em constantes** (`AuditLog::ACOES`, rótulos de entidade,
  `Incidente::TIPO_ROTULOS`, `RelatorioController::ROTULOS_AGRUPAMENTO` e
  similares): as constantes continuam com o texto PT (servem de chave e de
  `Rule::in`); a tradução é aplicada no ponto de leitura com
  `__(CONST[$codigo])`. O teste de cobertura também percorre os valores
  dessas constantes e exige cada um em `lang/en.json`.
- **Endpoint do seletor:** o front usa o guard já guardado no store
  (`auth.guard`: `web` → `/me/locale`, `customer` → `/customer/me/locale`);
  só o perfil ativo é atualizado.
- **Usuários existentes:** recebem `pt_BR` pela default da migration, sem
  backfill.
- **`lang/pt_BR.json`**: só
  contém as strings do Laravel em inglês desse e-mail; o teste de cobertura
  falha se alguma chave dele também for usada como texto PT em `app/`.

## Deixado para quem implementar

- Texto exato em inglês (tom curto, inglês americano, coerente com o
  `en.json` do front).
- Nome do middleware e local do teste de cobertura.
- Se `PATCH /me/locale` passa pelo `Auditavel` — padrão: não auditar.

## Restrições do ambiente

- O repositório do backend tem alterações não commitadas do usuário
  (`database/seeders/*`, `infra/terraform/...`): não tocar, não incluir em
  commits.
