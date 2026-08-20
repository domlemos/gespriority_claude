<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidenteDashboardResource;
use App\Models\Incidente;
use App\Models\PoliticaSla;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function incidentes(Request $request)
    {
        // Mesmos filtros/validação de IncidenteController::index() — mesmo
        // scope (Incidente::scopeFiltros), duplicado aqui de propósito (sem
        // camada de serviço, ver BACKEND_SPECS.md §3.5).
        $filtros = $request->validate([
            'numero' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', Rule::in(Incidente::STATUSES)],
            // Ignora a restrição padrão de status (STATUSES_PADRAO_LISTAGEM) quando
            // truthy e nenhum `status` explícito é informado — ver Incidente::scopeFiltros().
            'todos_status' => ['sometimes', 'boolean'],
            'prioridade' => ['sometimes', 'string', Rule::in(PoliticaSla::PRIORIDADES)],
            'origem' => ['sometimes', 'string', Rule::in(Incidente::ORIGENS)],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'item_id' => ['sometimes', 'integer', 'exists:itens,id'],
            'grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'responsavel_id' => ['sometimes', 'integer', 'exists:users,id'],
            'sort_by' => ['sometimes', 'string', Rule::in(Incidente::SORTABLE_COLUMNS)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ]);

        return IncidenteDashboardResource::collection(
            Incidente::query()
                ->filtros($filtros)
                ->ordenarPor($filtros['sort_by'] ?? null, $filtros['sort_dir'] ?? 'asc')
                ->with(['customer.client', 'item.subcategoria.categoria', 'grupoSolucao', 'responsavel'])
                ->paginate($request->integer('per_page', 15))
        );
    }
}
