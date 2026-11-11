# Relatórios — Fase 2: "o que contar", tabela com total, quebra por período e lista de chamados

**Status:** aprovado em 2026-11-11 · **Data:** 2026-11-09 · **Repos:** `gespriority_claude` (backend) e `gespriority_claude_front` (frontend)

Antecedente: a Fase 1 (plano `docs/superpowers/plans/2026-11-09-relatorios-precisao-fase-1.md`, já na `main`) corrigiu a precisão dos números: período em dias de Brasília, `concluido_por` com contagem por chamado, cancelados fora dos indicadores de desempenho, snapshot de grupo em `incidente_eventos`, filtro `grupo_analista_id` com analistas zerados e cabeçalho da planilha por agrupamento.

## 1. Intenção

Quem usa: **gestores de time**. Para quê: montar indicadores do time e **mandar como relatório para outras áreas**. Exemplos dados pelo usuário: quantos chamados cada integrante do time fechou ou resolveu num período, e chamados abertos por categoria, subcategoria ou item.

O que sai do sistema é **só a imagem do gráfico e a planilha**. O resto (e-mail, apresentação) é com o usuário. Por isso o que sai precisa se explicar sozinho para quem nunca viu os filtros.

Filosofia, nas palavras do usuário: **"Uno Mille com escadinha em cima"**. Simples, robusto e resolve, sem firula.

O problema central de hoje: o usuário só escolhe "agrupar por", e o sistema decide sozinho e sem avisar **o que conta** e **por qual data filtra**. Exemplo: "Categoria" com período filtra pela data de conclusão. Então "abertos em outubro por categoria" sai errado: descarta os chamados ainda abertos e usa a data errada.

## 2. Objetivos

1. O usuário escolhe explicitamente **o que contar** e **como agrupar**. A data do período segue o que se conta, e a tela diz qual data é.
2. O resultado aparece em **gráfico e tabela com total e percentual**, com uma **frase de título** que descreve a consulta por extenso.
3. **Quebra por período** opcional (dia, semana ou mês). A tabela vira linhas × períodos, com totais nas duas direções, e o gráfico vira barras agrupadas.
4. Clicar numa linha da tabela mostra a **lista dos chamados** daquela linha.
5. A **planilha** sai com o título, os filtros aplicados e a data de geração no topo, seguidos da mesma tabela da tela. A **imagem** do gráfico sai com o título.
6. **Atalhos de período** (este mês, mês passado, últimos 7 dias, personalizado). Um relatório salvo com atalho continua relativo à data em que é executado.
7. O gestor vê a **produção do próprio time** mesmo quando o chamado foi depois para um grupo que ele não acompanha.

## 3. Não-objetivos

- PDF, painéis/dashboards, envio agendado por e-mail, compartilhamento de link.
- Fotografia histórica de "quantos estavam em aberto no dia X" (backlog numa data passada).
- Novas permissões ou papéis: continua `relatorios.view` / `relatorios.manage`.
- Gráfico de pizza com quebra por período, ou outros tipos de gráfico além de colunas, barras e pizza.

## 4. Anti-objetivos (seria falha mesmo funcionando)

- Um número que muda de significado conforme outro campo sem a tela dizer. Toda regra implícita precisa aparecer no título ou no rótulo.
- Tela com mais passos ou opções do que o necessário para responder às perguntas acima.
- Planilha ou imagem que, fora do sistema, não diz o que foi contado, em que período e com quais filtros.

## 5. Decisões já tomadas

### 5.1 Tela (aprovada pelo usuário no rascunho)

Painel de filtros, nesta ordem:

1. **O que contar**: botões de escolha única.
2. **Agrupar por**: lista que muda conforme o item 1 (tabela 5.2).
3. **Quebrar por período**: Não (padrão) | Dia | Semana | Mês.
4. **Período**: atalhos Este mês | Mês passado | Últimos 7 dias | Personalizado (datas de início e fim). O rótulo diz a data usada, por exemplo "Período — data da abertura".
5. **Filtros**: grupo do analista, grupo do chamado, cliente, usuário do cliente, categoria/subcategoria/item, tipo, responsável, status.

Resultado: **opção A**, escolha minha porque o usuário aprovou o painel e não escolheu entre A e B. Tudo numa tela, de cima para baixo:

1. Frase de título.
2. Gráfico.
3. Tabela com total e %.
4. Botões "Exportar imagem" e "Baixar planilha".

As linhas da tabela são clicáveis e abrem a lista de chamados.

### 5.2 O que contar → qual data → agrupamentos

| O que contar | Base | Data do período | Contagem | Agrupamentos oferecidos |
|---|---|---|---|---|
| **Abertos** | `incidentes` | `created_at` | chamados | categoria, subcategoria, item, grupo do chamado (atual), responsável (atual), aberto por, cliente, tipo |
| **Concluídos** (resolvidos ou fechados) | eventos `resolvido`+`fechado` | data do evento | chamados distintos por linha | analista, grupo do analista (snapshot), grupo do chamado (snapshot), categoria, subcategoria, item, cliente, tipo, status de SLA |
| **Resolvidos** | eventos `resolvido` | data do evento | chamados distintos por linha | igual a Concluídos |
| **Fechados** | eventos `fechado` | data do evento | chamados distintos por linha | igual a Concluídos |
| **Cancelados** | `incidentes` com `status = cancelado` | `concluido_em` | chamados | iguais a Abertos |
| **Encaminhamentos** | eventos `encaminhado_*` | data do evento | eventos (ações) | quem encaminhou, para grupo, para responsável |

- Em todo agrupamento, "chamados distintos por linha" quer dizer que um mesmo chamado conta no máximo uma vez em cada linha. Por exemplo, resolvido e depois fechado pela mesma pessoa conta 1 para ela.
- A regra da Fase 1 continua valendo: dois analistas diferentes no mesmo chamado contam 1 cada. Por isso a soma das linhas pode passar do número de chamados distintos.
- Com isso, o **total da tabela** precisa deixar claro o que soma. Ver 6.2 e 6A.5.
- Com o filtro "Grupo do analista" e agrupamento por pessoa, os membros ativos do grupo sem resultado aparecem com 0, como na Fase 1.
- "Status de SLA" usa a regra de SLA de resolução de cada chamado concluído no período.

### 5.3 Visibilidade (decisão minha, autorizada pelo usuário com "pode prosseguir")

Nas contagens baseadas em evento (Concluídos, Resolvidos, Fechados, Encaminhamentos), um evento é visível ao usuário se **qualquer** uma destas condições valer:
- o chamado é visível hoje (regra atual);
- o `grupo_solucao_id` do evento (grupo do chamado no momento) está entre os grupos visíveis do usuário;
- o `autor_grupo_solucao_id` do evento (grupo do analista no momento) está entre os grupos visíveis do usuário.

Contagens sobre `incidentes` (Abertos, Cancelados) mantêm a regra atual. A lista de chamados do clique (5.5) obedece à mesma regra da contagem que a originou, para que **a lista sempre bata com o número**.

### 5.4 Saídas

- **Frase de título**: montada pelo backend e devolvida na resposta, para que tela, imagem e planilha usem o mesmo texto. Formato: *"Chamados {o que} de {dd/mm/aaaa} a {dd/mm/aaaa} por {agrupamento}[, por {mês|semana|dia}] — {filtros por extenso}"*. Exemplo: *"Chamados concluídos de 01/10/2026 a 31/10/2026 por analista — grupo do analista: N2"*.
- **Planilha (.xlsx)**:
  - linha 1: título;
  - linha 2: "Gerado em dd/mm/aaaa hh:mm (horário de Brasília) por {usuário}";
  - linha em branco;
  - a tabela: coluna do agrupamento, uma coluna por período (se houver quebra), Total e %, e a linha final de Total.
  - Sem fórmulas: valores prontos.
- **Imagem (PNG)**: o gráfico com a frase de título desenhada dentro da imagem, em fundo branco. A Fase 1 já compõe o fundo branco.

### 5.5 Lista de chamados (clique na linha)

- Abre num diálogo: número (link para o chamado), título, status atual, data relevante (a mesma do período), analista/autor quando a contagem é por evento, e grupo do chamado.
- Paginada. Tem um botão "Baixar lista" (.xlsx com o mesmo título e a linha clicada no cabeçalho). **Escolha minha**: é barato porque reusa a exportação, e atende a quem recebe o relatório e quer conferir.
- Com quebra por período, clicar numa célula abre só os chamados daquela linha naquele período. Clicar no nome da linha abre todos os períodos.

### 5.6 Relatórios salvos

- Guardam "o que contar", agrupamento, quebra, filtros e o período como **atalho** (`este_mes`, `mes_passado`, `ultimos_7_dias`) ou como datas fixas (personalizado).
- Ao clicar num relatório salvo, os filtros são carregados **e o relatório roda**. Hoje só carrega.
- Relatórios salvos antes da Fase 2 (só `agrupar_por`) precisam continuar abrindo com o mesmo significado de hoje. O mapeamento é com o construtor (seção 7).

## 6. Restrições

1. **Período** continua em dias de Brasília, com os dois extremos inclusivos (`App\Support\PeriodoRelatorio`). "Este mês", "Mês passado" e "Últimos 7 dias" também são calculados em Brasília, a partir de hoje (hoje entra nos 7 dias).
2. **Total da tabela**: os totais são sempre somas, e o % de cada linha é sobre o total geral. Quando um chamado aparece em mais de uma célula, um rodapé informa os chamados distintos (regra completa em 6A.5).
3. **Semana** começa na segunda-feira. O rótulo é a data da segunda ("Sem 06/10").
4. **Limite de colunas**: dia até 62 colunas, semana até 53, mês até 24. Acima disso, a API devolve 422 com mensagem clara, e a tela avisa antes de enviar.
5. **Compatibilidade**: `GET /api/relatorios/incidentes` sem os parâmetros novos continua respondendo exatamente como hoje (testes atuais intactos), para não quebrar relatórios salvos nem integrações.
6. **Stack**: segue o que já existe (Laravel + maatwebsite/excel no backend; Vue 3 + Vuetify + Chart.js e `chartjs-plugin-datalabels` no frontend). Nenhuma dependência nova.
7. **Frontend sem suíte de testes**: a verificação é por build (Node 22) e checagem manual. O backend segue com TDD em PHPUnit, que roda no container: `docker compose exec -T app php artisan test`.
8. Push na `main` publica em produção. A entrega é por branch, com merge local só depois de aprovado.

## 6A. Regras de detalhe (escolhas minhas, após a revisão do construtor)

1. **Abertos** = todo chamado criado no período, **qualquer que seja o status atual**, cancelados incluídos. Não há restrição implícita de status (a restrição a `STATUS_ATENDIDOS` só existe no caminho legado). O título diz "Chamados abertos (criados)".
2. **Filtros nas contagens por evento** (Concluídos, Resolvidos, Fechados, Encaminhamentos):
   - "Grupo do chamado" usa o snapshot `incidente_eventos.grupo_solucao_id`.
   - "Grupo do analista" usa o snapshot `autor_grupo_solucao_id`.
   - Categoria, subcategoria, item, cliente, usuário do cliente, tipo, responsável e status usam o estado **atual** do chamado, como hoje.
   - Assim, filtro e agrupamento de mesmo nome usam sempre a mesma coluna.
3. **Filtros nas contagens sobre `incidentes`** (Abertos, Cancelados):
   - "Grupo do chamado" = grupo atual.
   - "Grupo do analista" = grupo atual do responsável, mas no agrupamento "aberto por" é o grupo do criador (regras da Fase 1).
4. **Lista de chamados fora dos grupos visíveis.** Quando a regra 5.3 traz um chamado que o usuário não consegue abrir, a linha aparece com número, título, status, data e analista, **sem link** e com a marcação "fora dos seus grupos". A lista bate com o número sem levar a uma página de acesso negado.
5. **Totais com quebra por período:**
   - Cada célula conta chamados distintos daquela linha naquele período.
   - Total da linha = soma das células; total da coluna = soma das linhas; total geral = soma de tudo.
   - % = total da linha ÷ total geral.
   - Quando um mesmo chamado aparece em mais de uma célula (por exemplo, resolvido numa semana e fechado na outra, ou dois analistas), a tabela ganha uma linha de rodapé "Chamados distintos no resultado: N". Isso vale com ou sem quebra, e o rodapé também sai na planilha.
6. **Linhas da lista:**
   - Encaminhamentos: uma linha por **evento** (data, quem encaminhou, destino), batendo com a contagem de eventos.
   - Concluídos, Resolvidos e Fechados: uma linha por **chamado**, com a data e o autor do evento **mais recente** dentro do escopo clicado.
   - Abertos e Cancelados: uma linha por chamado.
7. **Relatórios salvos legados.** Os seis agrupamentos sem equivalente exato (`status_sla`, `responsavel`, `grupo_solucao`, `categoria`, `subcategoria`, `item`, que filtram por `concluido_em` e restringem a atendidos) **continuam no caminho legado**, sem conversão automática.
   - Na lista de salvos, aparecem com a etiqueta "formato antigo" e rodam como hoje.
   - Ao salvar de novo pela tela nova, passam a ter `contar`.
   - Os legados com equivalente exato (`resolvido_por`, `fechado_por`, `concluido_por`, `encaminhado_*`, `aberto_por`) são exibidos já no formato novo.
8. **Agrupamento "Cliente"** = empresa (`customers.client_id` → `clients`). "Usuário do cliente" continua só como filtro. Em "Aberto por", a linha separada "Portal (cliente)" da Fase 1 continua existindo.

## 7. Fica a cargo do construtor

- Formato dos parâmetros e da resposta da API. Por exemplo: `contar`, `agrupar_por`, `quebra`, `periodo` (atalho) ou `data_inicio`/`data_fim`; resposta com `titulo`, `colunas`, `linhas`, `totais`.
  - Restrição: a resposta antiga continua disponível quando `contar` não vem.
  - Escolha minha: um endpoint novo para a lista de chamados.
- Mapeamento dos `agrupar_por` antigos para (`contar`, `agrupar_por`) nos relatórios salvos, preservando o significado de hoje. Exemplos: `fechado_por` → Fechados por analista; `categoria` → mantém o comportamento legado.
  - Pode ser feito na leitura ou por migration. Se algum legado não tiver equivalente exato, mantém o caminho legado para ele.
- Como organizar o código do `RelatorioController`, que já está grande. Separar por "o que contar" é razoável.
- Desempenho: as bases atuais são pequenas (centenas de eventos). Basta não fazer N+1 nem carregar tudo em memória sem necessidade.
- Visual exato (cores, espaçamentos), seguindo o padrão atual da tela.
