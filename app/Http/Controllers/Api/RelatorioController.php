<?php

namespace App\Http\Controllers\Api;

use App\Exports\RelatorioIncidentesExport;
use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Item;
use App\Models\RelatorioSalvo;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class RelatorioController extends Controller
{
    public function index(Request $request)
    {
        // Fase 2: `contar` liga o caminho novo; sem ele, a resposta legada não muda.
        if ($request->has('contar')) {
            return app(RelatorioConsultaController::class)->index($request);
        }

        [$filtros, $agruparPor, $formato] = $this->validado($request);

        return $this->responder($filtros, $agruparPor, $formato, $request->user());
    }

    /**
     * Validação compartilhada com RelatorioSalvoController::executar() —
     * os mesmos filtros aceitos aqui são os que ficam gravados em
     * `RelatorioSalvo.filtros`.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    public function validado(Request $request): array
    {
        $validado = $request->validate([
            'agrupar_por' => ['required', 'string', Rule::in(RelatorioSalvo::AGRUPAMENTOS)],
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
            'status' => ['sometimes', 'string', Rule::in(Incidente::STATUSES)],
            'tipo' => ['sometimes', 'string', Rule::in(Incidente::TIPOS)],
            'data_inicio' => ['sometimes', 'date'],
            'data_fim' => ['sometimes', 'date'],
            'categoria_id' => ['sometimes', 'integer', 'exists:categorias,id'],
            'subcategoria_id' => ['sometimes', 'integer', 'exists:subcategorias,id'],
            'item_id' => ['sometimes', 'integer', 'exists:itens,id'],
            'grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'grupo_analista_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'responsavel_id' => ['sometimes', 'integer', 'exists:users,id'],
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
        ]);

        $agruparPor = $validado['agrupar_por'];
        $formato = $validado['formato'] ?? 'json';
        $filtros = collect($validado)->except(['agrupar_por', 'formato'])->all();

        return [$filtros, $agruparPor, $formato];
    }

    public function responder(array $filtros, string $agruparPor, string $formato, User $user)
    {
        $linhas = $this->agregar($filtros, $agruparPor, $user);

        if ($formato === 'xlsx') {
            return Excel::download(new RelatorioIncidentesExport($linhas, self::ROTULOS_AGRUPAMENTO[$agruparPor]), "relatorio-{$agruparPor}.xlsx");
        }

        return response()->json(['agrupado_por' => $agruparPor, 'data' => $linhas]);
    }

    /**
     * Títulos dos agrupamentos — os mesmos de `AGRUPAMENTO_OPTIONS` no
     * frontend. Usados como cabeçalho da 1ª coluna da planilha.
     */
    public const ROTULOS_AGRUPAMENTO = [
        'status_sla' => 'Status de SLA',
        'responsavel' => 'Responsável',
        'aberto_por' => 'Aberto por',
        'resolvido_por' => 'Resolvido por',
        'fechado_por' => 'Fechado por',
        'concluido_por' => 'Concluído por',
        'encaminhado_por' => 'Encaminhado por',
        'encaminhado_para_grupo' => 'Encaminhado para (grupo)',
        'encaminhado_para_responsavel' => 'Encaminhado para (responsável)',
        'grupo_solucao' => 'Grupo de Solução',
        'categoria' => 'Categoria',
        'subcategoria' => 'Subcategoria',
        'item' => 'Item',
    ];

    /**
     * Agrupamentos cujas linhas são usuários: com `grupo_analista_id`, os
     * membros ativos do grupo sem nenhum registro entram com `total: 0`
     * (ver `comAnalistasZerados()`).
     */
    private const AGRUPAMENTOS_POR_USUARIO = ['resolvido_por', 'fechado_por', 'concluido_por', 'encaminhado_por', 'responsavel'];

    /**
     * Dimensões cuja base é `IncidenteEvento` (não `Incidente`), agrupadas
     * pelo autor do evento (`user_id`) — um `tipo` só (`resolvido_por`/
     * `fechado_por`) ou dois somados (`encaminhado_por` conta tanto
     * encaminhamento pra grupo quanto atribuição de responsável, já que o
     * pedido era só "quem encaminhou", sem distinguir o destino).
     */
    private const EVENTOS_POR_USUARIO = [
        'resolvido_por' => [IncidenteEvento::TIPO_RESOLVIDO],
        'fechado_por' => [IncidenteEvento::TIPO_FECHADO],
        'concluido_por' => [IncidenteEvento::TIPO_RESOLVIDO, IncidenteEvento::TIPO_FECHADO],
        'encaminhado_por' => [IncidenteEvento::TIPO_ENCAMINHADO_GRUPO, IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL],
    ];

    /**
     * Dimensões de evento que contam CHAMADOS distintos por usuário, não
     * eventos: "quantos chamados o agente resolveu/fechou" não deve inflar
     * porque o mesmo chamado foi resolvido de novo após reabertura, nem (em
     * `concluido_por`) porque o mesmo agente resolveu e depois fechou o
     * mesmo chamado. `encaminhado_*` continuam contando eventos (são ações).
     */
    private const CONTAGEM_POR_CHAMADO = ['resolvido_por', 'fechado_por', 'concluido_por'];

    /**
     * `status_sla`/`responsavel`/`grupo_solucao` respondem ao pedido original
     * ("incidentes FECHADOS..."): sem `status` explícito, restringe a
     * `STATUS_ATENDIDOS` (resolvido/fechado — cancelado não é atendimento e
     * distorceria SLA e desempenho). `categoria`/`subcategoria`/`item`/`aberto_por` é
     * volume geral — o pedido não mencionava "fechados" pra esses
     * indicadores, então não tem essa restrição implícita. `grupo_solucao`
     * segue o mesmo critério de `responsavel` (mede desempenho do grupo em
     * chamados concluídos, não volume geral).
     *
     * As dimensões baseadas em `IncidenteEvento` (`EVENTOS_POR_USUARIO` +
     * `encaminhado_para_grupo`/`encaminhado_para_responsavel`) são tratadas
     * à parte, ANTES de montar `$query` sobre `Incidente` — a base delas é
     * um evento por transição/encaminhamento, nunca sobrescrito nem limpo
     * na reabertura (ver `IncidenteController::registrarEventoDeConclusaoSeAplicavel()`/
     * `logEscalonamentoSeMudou()`), não uma coluna de `Incidente`, que só
     * guarda o estado *atual*. Por isso também não têm a restrição implícita
     * de `STATUS_ATENDIDOS`: um chamado resolvido e depois reaberto pra
     * 'em_andamento' ainda deve contar a resolução que já aconteceu.
     *
     * Contagem: `resolvido_por`/`fechado_por`/`concluido_por` contam chamados
     * distintos por usuário (`CONTAGEM_POR_CHAMADO`); as demais dimensões de
     * evento contam eventos. `concluido_por` une resolvido e fechado.
     */
    private function agregar(array $filtros, string $agruparPor, User $user): Collection
    {
        $linhas = $this->agregarLinhas($filtros, $agruparPor, $user);

        if (isset($filtros['grupo_analista_id']) && in_array($agruparPor, self::AGRUPAMENTOS_POR_USUARIO, true)) {
            return $this->comAnalistasZerados($linhas, (int) $filtros['grupo_analista_id']);
        }

        return $linhas;
    }

    /**
     * `grupo_analista_id` mede um GRUPO de analistas: quem está no grupo e
     * não apareceu no resultado precisa de uma linha explícita com 0 — é
     * justamente o dado que o gestor procura (analista ocioso). Só
     * usuários ativos (soft-deleted não entram). Ordena por total desc e
     * rotulo, já que as linhas zeradas vêm depois das agregadas.
     */
    private function comAnalistasZerados(Collection $linhas, int $grupoId): Collection
    {
        $presentes = $linhas->pluck('chave')->filter()->all();

        $zerados = User::query()
            ->where('grupo_solucao_id', $grupoId)
            ->whereNotIn('id', $presentes)
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['chave' => $u->id, 'rotulo' => $u->name, 'total' => 0]);

        return $linhas->concat($zerados)
            ->sortBy([['total', 'desc'], ['rotulo', 'asc']])
            ->values();
    }

    private function agregarLinhas(array $filtros, string $agruparPor, User $user): Collection
    {
        if (isset(self::EVENTOS_POR_USUARIO[$agruparPor])) {
            return $this->comRotulos(
                $this->contarAgrupado(
                    IncidenteEvento::query()->whereIn('tipo', self::EVENTOS_POR_USUARIO[$agruparPor])->visiveisPara($user)->filtrosRelatorio($filtros),
                    'user_id',
                    in_array($agruparPor, self::CONTAGEM_POR_CHAMADO, true) ? 'incidente_id' : null,
                ),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(usuário desconhecido)',
            );
        }

        if ($agruparPor === 'encaminhado_para_grupo') {
            return $this->comRotulos(
                $this->contarAgrupado(
                    IncidenteEvento::query()->where('tipo', IncidenteEvento::TIPO_ENCAMINHADO_GRUPO)->visiveisPara($user)->filtrosRelatorio($filtros),
                    'alvo_id'
                ),
                fn (array $ids) => GrupoSolucao::whereIn('id', $ids)->pluck('nome', 'id'),
                '(sem grupo)',
            );
        }

        if ($agruparPor === 'encaminhado_para_responsavel') {
            return $this->comRotulos(
                $this->contarAgrupado(
                    IncidenteEvento::query()->where('tipo', IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL)->visiveisPara($user)->filtrosRelatorio($filtros),
                    'alvo_id'
                ),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(sem responsável)',
            );
        }

        if ($agruparPor === 'aberto_por') {
            // Aqui o grupo do analista é o de quem ABRIU o chamado, não o do
            // responsável (semântica dos demais agrupamentos de Incidente).
            $grupoCriador = $filtros['grupo_analista_id'] ?? null;
            unset($filtros['grupo_analista_id']);

            $query = Incidente::query()->visiveisPara($user)->filtrosRelatorio($filtros)
                ->when($grupoCriador, fn (Builder $q, int $v) => $q->whereHas('criadoPor', fn (Builder $qc) => $qc->where('grupo_solucao_id', $v)));

            $linhas = $this->comRotulos(
                $this->contarAgrupado($query, 'criado_por_id'),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(desconhecido)',
            );

            // Chamado do portal não tem analista criador: some com o filtro.
            return $grupoCriador ? $linhas : $this->separarAbertosPeloPortal($linhas, $user, $filtros);
        }

        $query = Incidente::query()->visiveisPara($user)->filtrosRelatorio($filtros);

        if (in_array($agruparPor, ['status_sla', 'responsavel', 'grupo_solucao'], true) && empty($filtros['status'])) {
            $query->whereIn('status', Incidente::STATUS_ATENDIDOS);
        }

        return match ($agruparPor) {
            'status_sla' => $this->agruparPorStatusSla($query),
            'responsavel' => $this->comRotulos(
                $this->contarAgrupado($query, 'responsavel_id'),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(sem responsável)',
            ),
            'grupo_solucao' => $this->comRotulos(
                $this->contarAgrupado($query, 'grupo_solucao_id'),
                fn (array $ids) => GrupoSolucao::whereIn('id', $ids)->pluck('nome', 'id'),
                '(sem grupo de solução)',
            ),
            'item' => $this->comRotulos(
                $this->contarAgrupado($query, 'item_id'),
                fn (array $ids) => Item::whereIn('id', $ids)->pluck('nome', 'id'),
                '(sem item)',
            ),
            'subcategoria' => $this->comRotulos(
                $this->contarAgrupado($query->join('itens', 'itens.id', '=', 'incidentes.item_id'), 'itens.subcategoria_id'),
                fn (array $ids) => Subcategoria::whereIn('id', $ids)->pluck('nome', 'id'),
                '(sem subcategoria)',
            ),
            'categoria' => $this->comRotulos(
                $this->contarAgrupado(
                    $query->join('itens', 'itens.id', '=', 'incidentes.item_id')
                        ->join('subcategorias', 'subcategorias.id', '=', 'itens.subcategoria_id'),
                    'subcategorias.categoria_id'
                ),
                fn (array $ids) => Categoria::whereIn('id', $ids)->pluck('nome', 'id'),
                '(sem categoria)',
            ),
        };
    }

    /**
     * `statusSlaResolucao()` é calculado em PHP (compara `concluido_em`
     * congelado contra `prazo_resolucao`, ver Incidente model), não dá pra
     * fazer isso num `GROUP BY` puro do banco — busca as linhas filtradas e
     * tabula em memória. Reaproveita a mesma lógica de SLA usada no
     * dashboard, em vez de duplicar a comparação de datas aqui.
     */
    private function agruparPorStatusSla(Builder $query): Collection
    {
        $contagem = [Incidente::SLA_DENTRO_PRAZO => 0, Incidente::SLA_ESTOURADO => 0, Incidente::SLA_SEM_SLA => 0];

        foreach ($query->get(['id', 'prazo_resolucao', 'concluido_em']) as $incidente) {
            $contagem[$incidente->statusSlaResolucao()]++;
        }

        $rotulos = [
            Incidente::SLA_DENTRO_PRAZO => 'Dentro do prazo',
            Incidente::SLA_ESTOURADO => 'Fora do prazo',
            Incidente::SLA_SEM_SLA => 'Sem SLA aplicável',
        ];

        return collect($contagem)->map(fn (int $total, string $chave) => [
            'chave' => $chave,
            'rotulo' => $rotulos[$chave],
            'total' => $total,
        ])->values();
    }

    /**
     * Chamados abertos pelo portal também têm `criado_por_id` nulo (quem
     * abriu foi um Customer, não staff) — sem isto cairiam em
     * "(desconhecido)" junto dos legados de antes da coluna existir. Tira
     * essa parte da linha nula e a devolve como um grupo próprio.
     */
    private function separarAbertosPeloPortal(Collection $linhas, User $user, array $filtros): Collection
    {
        $doPortal = Incidente::query()->visiveisPara($user)->filtrosRelatorio($filtros)
            ->whereNull('criado_por_id')
            ->whereNotNull('criado_por_customer_id')
            ->count();

        if ($doPortal === 0) {
            return $linhas;
        }

        return $linhas
            ->map(fn (array $linha) => $linha['chave'] === null
                ? [...$linha, 'total' => $linha['total'] - $doPortal]
                : $linha)
            ->reject(fn (array $linha) => $linha['chave'] === null && $linha['total'] === 0)
            ->push(['chave' => 'portal', 'rotulo' => 'Portal (cliente)', 'total' => $doPortal])
            ->values();
    }

    /**
     * `count(*)` agrupado por uma coluna (ou expressão `tabela.coluna` já com
     * join aplicado). Com `$contarDistinto` usa `count(distinct coluna)`.
     */
    private function contarAgrupado(Builder $query, string $coluna, ?string $contarDistinto = null): Collection
    {
        $contagem = $contarDistinto !== null ? "count(distinct {$contarDistinto})" : 'count(*)';

        return $query->selectRaw("{$coluna} as chave, {$contagem} as total")
            ->groupBy($coluna)
            ->get()
            ->map(fn ($linha) => ['chave' => $linha->chave, 'total' => (int) $linha->total]);
    }

    /**
     * Resolve `chave` (id cru) pra `rotulo` (nome legível) via uma consulta
     * só com os ids que realmente apareceram no agrupamento — nunca N+1.
     * `chave` nula (ex. `item_id`/`responsavel_id` nunca setado) usa
     * `$rotuloVazio` em vez de tentar resolver um nome.
     */
    private function comRotulos(Collection $linhas, \Closure $resolverRotulos, string $rotuloVazio): Collection
    {
        $ids = $linhas->pluck('chave')->filter()->all();
        $rotulos = $resolverRotulos($ids);

        return $linhas->map(fn (array $linha) => [
            'chave' => $linha['chave'],
            'rotulo' => $linha['chave'] === null ? $rotuloVazio : ($rotulos[$linha['chave']] ?? "#{$linha['chave']}"),
            'total' => $linha['total'],
        ]);
    }
}
