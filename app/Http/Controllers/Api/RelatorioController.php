<?php

namespace App\Http\Controllers\Api;

use App\Exports\RelatorioIncidentesExport;
use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteResolucao;
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
        [$filtros, $agruparPor, $formato] = $this->validado($request);

        return $this->responder($filtros, $agruparPor, $formato);
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
            'data_inicio' => ['sometimes', 'date'],
            'data_fim' => ['sometimes', 'date'],
            'categoria_id' => ['sometimes', 'integer', 'exists:categorias,id'],
            'subcategoria_id' => ['sometimes', 'integer', 'exists:subcategorias,id'],
            'item_id' => ['sometimes', 'integer', 'exists:itens,id'],
            'grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'responsavel_id' => ['sometimes', 'integer', 'exists:users,id'],
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
        ]);

        $agruparPor = $validado['agrupar_por'];
        $formato = $validado['formato'] ?? 'json';
        $filtros = collect($validado)->except(['agrupar_por', 'formato'])->all();

        return [$filtros, $agruparPor, $formato];
    }

    public function responder(array $filtros, string $agruparPor, string $formato)
    {
        $linhas = $this->agregar($filtros, $agruparPor);

        if ($formato === 'xlsx') {
            return Excel::download(new RelatorioIncidentesExport($linhas), "relatorio-{$agruparPor}.xlsx");
        }

        return response()->json(['agrupado_por' => $agruparPor, 'data' => $linhas]);
    }

    /**
     * `status_sla`/`responsavel`/`grupo_solucao` respondem ao pedido original
     * ("incidentes FECHADOS..."): sem `status` explícito, restringe a
     * `STATUS_CONCLUIDOS`. `categoria`/`subcategoria`/`item` é volume
     * geral — o pedido não mencionava "fechados" pra esse indicador, então
     * não tem essa restrição implícita. `grupo_solucao` segue o mesmo
     * critério de `responsavel` (mede desempenho do grupo em chamados
     * concluídos, não volume geral).
     *
     * `resolvido_por` é tratado à parte, ANTES de montar `$query` sobre
     * `Incidente` — a base dessa dimensão é `IncidenteResolucao` (um evento
     * por transição pra 'resolvido', nunca sobrescrito nem limpo na
     * reabertura — ver IncidenteController::registrarResolucaoSeAplicavel()),
     * não `Incidente.responsavel_id`/`concluido_em`, que só guardam o
     * estado *atual*. Por isso também não tem a restrição implícita de
     * `STATUS_CONCLUIDOS`: um chamado resolvido e depois reaberto pra
     * 'em_andamento' ainda deve contar a resolução que já aconteceu.
     */
    private function agregar(array $filtros, string $agruparPor): Collection
    {
        if ($agruparPor === 'resolvido_por') {
            return $this->comRotulos(
                $this->contarAgrupado(IncidenteResolucao::query()->filtrosRelatorio($filtros), 'user_id'),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(usuário desconhecido)',
            );
        }

        $query = Incidente::query()->filtrosRelatorio($filtros);

        if (in_array($agruparPor, ['status_sla', 'responsavel', 'grupo_solucao'], true) && empty($filtros['status'])) {
            $query->whereIn('status', Incidente::STATUS_CONCLUIDOS);
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

    /** `count(*)` agrupado por uma coluna (ou expressão `tabela.coluna` já com join aplicado). */
    private function contarAgrupado(Builder $query, string $coluna): Collection
    {
        return $query->selectRaw("{$coluna} as chave, count(*) as total")
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
