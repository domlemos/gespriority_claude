# Resposta ao cliente no chamado — design

## Por quê

O cliente interage pelo portal (cobranças, dúvidas) e o agente recebe e-mail a cada comentário,
mas não tem como responder **para o cliente**: todo comentário do staff é interno. Falta um canal
de volta, registrado no chamado.

## O que é

Botão **"Responder ao usuário"** ao lado de "Adicionar comentário" no detalhe do chamado (staff).
A resposta entra no feed do chamado, aparece no feed do portal do cliente e dispara um e-mail com
o mesmo texto para o cliente.

- Resposta **geral** ao chamado, sem vínculo com um comentário específico do cliente — a ordem
  cronológica do feed dá o contexto (decisão do Daniel).

## Abordagens

- **A. Novo tipo `resposta` em `incidente_descricoes` (escolhida).** Rota própria, portal filtra
  por tipo como já faz com `encerramento`, e-mail no molde de `ChamadoFechado`. Visibilidade fixada
  pelo tipo: uma nota interna nunca vira pública por engano, e o que já foi por e-mail não muda de
  status.
- B. `comentario` com coluna `publico`. Menos tipos, mas abre a pergunta de editar/excluir algo
  já enviado e põe o risco de vazamento num booleano. Descartada.
- C. Tabela separada de respostas. Duplicaria o feed. Descartada.

## Backend

- `IncidenteDescricao::TIPO_RESPOSTA = 'resposta'`, incluído em `TIPOS`.
- `POST /incidentes/{incidente}/respostas` — mesmo middleware/permissão de
  `POST /incidentes/{incidente}/descricoes` (`tickets.manage` + visibilidade do chamado).
  Corpo `{descricao}` (obrigatório, até 5000, em branco → `422`). `tipo`/`user_id` forçados no
  servidor. Resposta `201` com a entrada (`IncidenteDescricaoResource`).
- **Qualquer status**, inclusive `fechado`/`cancelado` (o staff pode precisar responder depois de
  fechar). **Não muda o status** do chamado.
- **Imutável:** `ensureCanModify()` já bloqueia tudo que não é `comentario` — `resposta` fica
  sem edição/exclusão (já saiu por e-mail), igual a `encerramento`.
- **E-mail:** `App\Notifications\RespostaAoCliente` (`mail`, `ShouldQueue` + `afterCommit()`)
  enviada a `$incidente->customer` — o solicitante do chamado, mesmo destinatário do
  `ChamadoFechado`. Cliente desativado/sem registro → ninguém recebe, a resposta fica no feed.
  `App\Mail\RespostaAoClienteMail` no idioma do cliente (mesma regra de i18n dos outros e-mails ao
  cliente): assunto `[#id] Nova resposta ao seu chamado: <título>`, nome do agente, texto
  (escapado) e botão para `{FRONTEND_URL}/portal/incidentes/{id}`.
- **Portal:** o filtro do detalhe passa a incluir `tipo = resposta` junto de `encerramento`;
  `PortalDescricaoResource` já expõe `autor.nome`/`autor.equipe = true`.
- **Auditoria:** `IncidenteDescricao` passa a registrar também `resposta` (hoje só `comentario`),
  sem o texto — é comunicação com o cliente, como o comentário.
- Testes de feature (criação, validação, imutabilidade, visibilidade no portal, e-mail enviado ao
  cliente certo/no idioma certo, nada enviado quando a criação falha) e `BACKEND_SPECS.md`
  atualizado no mesmo commit.

## Frontend

- Detalhe do chamado (staff): botão "Responder ao usuário" ao lado de "Adicionar comentário",
  abre o mesmo tipo de campo de texto, com um aviso de que o texto vai para o cliente por e-mail.
- Feed do staff: `resposta` com destaque visual próprio e o rótulo "Enviado ao cliente", sem
  ações de editar/excluir.
- Portal: `resposta` aparece no feed como mensagem da equipe.
- Trabalho em worktree separada do `gespriority_claude_front` (checkout compartilhado).

## Fora do escopo

- Vincular a resposta a um comentário específico / citação.
- Cliente responder pelo e-mail (resposta continua sendo pelo portal).
- Mudar status automaticamente ao responder (ex.: `pendente`/aguardando cliente).
- Anexos na resposta.

## Chamadas minhas (para conferir)

- Qualquer status aceita resposta; o status não muda.
- Resposta imutável.
- Destinatário = só o cliente solicitante do chamado.
- Qualquer agente com acesso ao chamado pode responder, não só o responsável.
