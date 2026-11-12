# Relatórios — Mapa de fluxos

**Status:** aprovado em 12/11/2026 ("pode fazer da forma como foi recomendado; apenas acrescentando um filtro por período") · **Data:** 2026-11-12 · **Repos:** `gespriority_claude` (backend) e `gespriority_claude_front` (frontend)

Origem: o estudo de viabilidade publicado em https://claude.ai/artifact/HAjf6dnQcAjcH7Rt8CrV93, feito a partir do esboço do usuário. As recomendações das seções 4 e 5 do estudo foram aprovadas e estão transcritas aqui como decisões. Antecedente técnico: `docs/superpowers/specs/2026-11-12-relatorios-indicadores-por-grupo-design.md`, que definiu os eventos com autoria completa.

## 1. Intenção

Uma nova aba **"Mapa de fluxos"** em Relatórios, ao lado de "Avançado". Ela mostra, para um período, como os chamados entram em cada grupo de solução, para onde são encaminhados e quantos cada grupo resolve.

Forma visual, conforme o esboço do usuário:
- um nó **Cliente** à esquerda;
- uma seta para cada grupo que recebeu chamados do portal;
- cada grupo é uma caixa com **Abertos pelo grupo**, **Recebidos**, **Encaminhados** e **Resolvidos**;
- setas de grupo para grupo com a quantidade.

## 2. Objetivos

1. **Filtro de período**, obrigatório: os atalhos Este mês, Mês passado e Últimos 7 dias, ou Personalizado. Usa as mesmas regras e mensagens das outras abas, em dias de Brasília. Não há quebra por dia, semana ou mês.
2. **Filtro de grupos**, opcional, de seleção múltipla. Vazio significa todos os grupos visíveis.
3. **Nós:**
   - Cliente;
   - um nó por grupo;
   - os nós especiais "Sem grupo", "Origem desconhecida" e "Outros grupos" (seção 4.4), que só aparecem quando têm alguma contagem.
4. **Arestas** origem → destino, com contagem.
5. **Lista de chamados ao clicar** num nó (um dos quatro números) ou numa seta, com a mesma regra das outras abas: a lista bate com o número.
6. **Exportar imagem** do mapa (com título, legenda e aviso) e **baixar planilha**, com uma tabela de nós e uma tabela de arestas.
7. **Gravar a origem do encaminhamento** daqui em diante e **registrar quando um chamado fica sem grupo**.

## 3. Não-objetivos

- Saldo do nó, como recebidos menos encaminhados menos resolvidos (decisão 4.3 do estudo).
- Fechados e Cancelados como números do nó. Eles aparecem só na lista ao clicar no nó (decisão 4.4).
- Diagrama Sankey ou biblioteca nova de grafos.
- Mudar as abas Indicadores por grupo e Avançado.

## 4. Regras

### 4.1 Contagens do nó do grupo G no período

| Número | Conta | Unidade |
|---|---|---|
| **Abertos pelo grupo** | eventos `aberto` cujo autor estava em G (`autor_grupo_solucao_id = G`), igual aos Indicadores | chamados |
| **Recebidos** | entradas em G vindas de fora: (a) eventos `encaminhado_grupo` com destino G (`alvo_id = G`) cuja origem não é G; (b) eventos `aberto` com destino G (`grupo_solucao_id = G`) cujo autor não estava em G (outro grupo, ou o portal) | entradas (eventos) |
| **Encaminhados** | eventos `encaminhado_grupo` com origem G e destino diferente de G | encaminhamentos (eventos) |
| **Resolvidos** | chamados distintos com evento `resolvido` em que o **chamado estava em G** naquele momento (`incidente_eventos.grupo_solucao_id = G`), qualquer que seja o grupo de quem resolveu (decisão 4.1 do estudo) | chamados |

Um chamado aberto por um integrante de G diretamente para G conta só em "Abertos pelo grupo", não em "Recebidos". É o que o esboço mostra.

### 4.2 Arestas

Uma aresta X → Y conta **eventos** (decisão 4.2 do estudo). Um chamado que vai e volta duas vezes entre N1 e N2 soma 2 em N1 → N2. Para cada grupo, a soma das arestas que saem dele é igual ao seu "Encaminhados".

Uma aresta nasce de cada evento a seguir.

| Evento | Origem (X) | Destino (Y) |
|---|---|---|
| `encaminhado_grupo` | o grupo de origem (4.3) | `alvo_id` |
| `aberto` com destino Y, aberto pelo **portal** | **Cliente** | Y |
| `aberto` com destino Y, aberto por agente de outro grupo A ≠ Y | A | Y |
| `aberto` com destino Y, aberto por agente do próprio Y | não gera aresta (vale a regra da 4.1) | — |

Chamados do portal nascem sem grupo, e o grupo é definido na triagem por um `encaminhado_grupo`. Nesse caso o primeiro encaminhamento sai do nó **Cliente**: um chamado do portal sem grupo de destino na abertura gera a aresta Cliente → Y no seu primeiro `encaminhado_grupo`, e não "Sem grupo" → Y.

Arestas de um nó para ele mesmo não são desenhadas nem contadas.

### 4.3 Origem de um encaminhamento

- **Daqui em diante:** uma coluna nova `incidente_eventos.origem_grupo_id` (anulável) é preenchida no momento do encaminhamento com o grupo anterior do chamado, que o controller já tem em `$grupoAnteriorId`. Se o valor for nulo, o chamado estava sem grupo. Isso é um fato registrado, não uma informação desconhecida.
- **Chamado que fica sem grupo:** quando o grupo de um chamado é removido (de A para nenhum), grava-se um evento novo `removido_grupo`, com `origem_grupo_id = A`. Ele não gera aresta, mas faz o próximo encaminhamento sair de "Sem grupo", e não de A.
- **Eventos já existentes (migration):** a origem é derivada da sequência de eventos do chamado. É o destino do `encaminhado_grupo` anterior. Se não houver, é o grupo de destino do `aberto`.
  - Quando a sequência começa num `aberto` reconstruído (`retroativo = true`) sem grupo, a origem é **desconhecida**. Exceção: chamado do portal, que sai do Cliente (4.2).
  - Nesse caso, o encaminhamento é marcado com `retroativo = true` e `origem_grupo_id` fica nulo.
- **Leitura da origem:**

  | `origem_grupo_id` | `retroativo` | Origem |
  |---|---|---|
  | preenchido | qualquer | o grupo |
  | nulo | `false` | "Sem grupo" |
  | nulo | `true` | "Origem desconhecida" |

  Sempre com a exceção do primeiro encaminhamento de um chamado do portal, que sai do Cliente.

### 4.4 Nós especiais e visibilidade

| Nó | Quando aparece |
|---|---|
| **Cliente** | sempre (com 0 se não houver chamados do portal) |
| **Sem grupo** | quando há encaminhamento a partir de um chamado sem grupo, aberto por agente |
| **Origem desconhecida** | quando há encaminhamento com origem desconhecida (histórico) |
| **Outros grupos** | só para quem não é admin: agrega os grupos fora da visibilidade do usuário, sem mostrar nomes nem números internos, só as arestas de e para os grupos visíveis |

- Admin vê todos os grupos.
- Para quem não é admin, os nós de grupo são os grupos visíveis (`User::visibleGrupoSolucaoIds()`). Uma aresta entra se pelo menos uma ponta é um grupo visível ou o Cliente. A ponta invisível vira "Outros grupos".
- Com filtro de grupos, aparecem os grupos selecionados mais as pontas das arestas que tocam neles. Pontas fora da seleção aparecem como nós próprios, se forem visíveis; senão, como "Outros grupos". Um grupo selecionado fora da visibilidade dá 422, com a mesma mensagem dos Indicadores.
- Grupos visíveis, ativos e sem nenhuma contagem nem aresta no período não aparecem, para manter o mapa legível. Isso é diferente dos Indicadores.

### 4.5 Aviso de dados aproximados

Mesma regra dos Indicadores, calculada sobre o período inteiro sem filtro de grupo: se algum evento `aberto`, `encaminhado_grupo` ou `resolvido` do período tem `retroativo = true`, mostra-se um aviso.

Texto: `"Dados aproximados: registros até dd/mm/aaaa foram reconstruídos; nesses casos a origem de um encaminhamento pode aparecer como desconhecida e o grupo de quem abriu é o grupo atual da pessoa."`

A data é a do evento retroativo mais recente do período, em Brasília.

### 4.6 Textos fixos

- **Título:** `"Mapa de fluxos — de dd/mm/aaaa a dd/mm/aaaa[ — grupos: N1, N2]"`.
- **Legenda**, na tela, na imagem e na planilha:
  1. `Abertos pelo grupo: chamados criados no período por integrantes do grupo.`
  2. `Recebidos: chamados que entraram no grupo vindos do cliente ou de outro grupo, por encaminhamento ou abertos diretamente para ele.`
  3. `Encaminhados: encaminhamentos que saíram do grupo para outro grupo. Um chamado que vai e volta conta cada passagem.`
  4. `Resolvidos: chamados resolvidos enquanto estavam no grupo, por qualquer analista. A produção por analista está em Indicadores por grupo.`
  5. `As setas contam passagens no período. Recebidos menos encaminhados menos resolvidos não é o número de chamados em aberto.`

## 5. Tela

1. Na aba **Mapa de fluxos**, entre "Indicadores por grupo" e "Avançado" (ordem: Indicadores por grupo | Mapa de fluxos | Avançado), vêm de cima para baixo:
   - os filtros: grupos (múltiplo) e período (atalhos + personalizado, obrigatório);
   - o botão Gerar;
   - o título;
   - o mapa;
   - a legenda;
   - o aviso;
   - os botões "Exportar imagem" e "Baixar planilha".
2. **Mapa em SVG** feito no projeto, sem biblioteca nova:
   - Cliente na primeira coluna;
   - cada grupo na coluna da sua menor distância a partir do Cliente, ou dos nós "Sem grupo" / "Origem desconhecida" quando não há caminho a partir do Cliente;
   - os nós de uma coluna empilhados;
   - setas para a frente em curva suave;
   - setas para trás ou dentro da mesma coluna em arco, para que não cruzem as caixas;
   - o número de cada seta num rótulo sobre a curva.
   - Deve ficar legível com até 8 grupos e rolar na horizontal dentro do próprio contêiner quando não couber.
3. **Clique:**
   - em um dos quatro números de um nó: abre a lista de chamados desse número;
   - em uma seta: abre a lista das passagens daquela aresta;
   - reaproveita o diálogo de lista existente (`ReportTicketListDialog`), com um modo próprio.
4. **Imagem exportada:** o mapa com título, legenda e aviso, em fundo branco.
5. **Planilha:**
   - título;
   - linha "Gerado em … por …";
   - tabela "Nós" com Grupo, Abertos pelo grupo, Recebidos, Encaminhados e Resolvidos;
   - tabela "Fluxos" com Origem, Destino e Quantidade;
   - legenda e aviso.
   - Sem fórmulas.

Relatórios salvos não fazem parte desta entrega. Ficam para depois, se pedidos.

## 6. Restrições

- Mesmas restrições das entregas anteriores:
  - Laravel 12 + PHPUnit em TDD (testes em SQLite) e Vue 3 + Vuetify 3, sem dependências novas;
  - Node 22 no build;
  - push na `main` publica em produção, e o backend exige aprovação manual do ambiente `production`;
  - o checkout principal do front é compartilhado com outras sessões; trabalhar num worktree.
- As migrations precisam rodar no PostgreSQL de produção. Verificar num banco PostgreSQL temporário antes do merge.
- As abas Indicadores por grupo e Avançado, e os relatórios existentes, não mudam de comportamento.

## 7. Fica a cargo do construtor

- Formato exato da resposta da API (nós, arestas, título, legenda, aviso) e dos parâmetros da lista por nó ou aresta.
- Algoritmo de posicionamento dentro das regras da seção 5.2.
- Organização do código, reaproveitando `ConsultaIndicadores` (período, grupos, visibilidade) e `ListaChamados` quando fizer sentido.
