<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Listas enxutas, só leitura, pra preencher selects (Cliente, Responsável,
 * Empresa) no painel de incidentes, no formulário do chamado e nos
 * relatórios. Os CRUDs de clients/customers/users exigem permissões de
 * administração (`*.manage`) que Agente/Supervisor não têm — sem estas
 * rotas, essas telas recebiam 403 só pra montar os filtros. Expõem o mínimo
 * pra seleção (sem convite, papéis, permissões etc.) e sem paginação: o
 * select precisa da lista inteira.
 */
class LookupController extends Controller
{
    public function clients(Request $request): JsonResponse
    {
        $this->autorizar($request);

        return response()->json([
            'data' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $this->autorizar($request);

        $customers = Customer::query()
            ->with('client:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'client_id']);

        return response()->json(['data' => $customers]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->autorizar($request);

        return response()->json([
            'data' => User::query()->orderBy('name')->get(['id', 'name', 'grupo_solucao_id']),
        ]);
    }

    // Quem usa essas listas: painel/formulário de chamados (tickets.view) e
    // relatórios (relatorios.view) — basta uma das duas.
    private function autorizar(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->hasPermission('tickets.view') || $user->hasPermission('relatorios.view'), 403);
    }
}
