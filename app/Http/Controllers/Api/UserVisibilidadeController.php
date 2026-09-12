<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GrupoSolucao;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserVisibilidadeController extends Controller
{
    public function show(User $user)
    {
        return response()->json(['data' => $this->payload($user)]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'grupo_solucao_ids' => ['present', 'array'],
            'grupo_solucao_ids.*' => [
                'integer',
                'distinct',
                'exists:grupos_solucao,id',
                // O grupo próprio já é implícito (User::visibleGrupoSolucaoIds()
                // sempre inclui grupo_solucao_id) — incluir aqui de novo seria
                // redundante e confundiria a UI de admin.
                Rule::notIn([$user->grupo_solucao_id]),
            ],
        ]);

        $user->gruposVisiveisExtra()->sync($data['grupo_solucao_ids']);

        return response()->json(['data' => $this->payload($user->fresh())]);
    }

    private function payload(User $user): array
    {
        $user->loadMissing('grupoSolucao', 'gruposVisiveisExtra');

        return [
            'grupo_solucao_id' => $user->grupo_solucao_id,
            'grupo_solucao' => $user->grupoSolucao ? [
                'id' => $user->grupoSolucao->id,
                'nome' => $user->grupoSolucao->nome,
            ] : null,
            'grupos_extra' => $user->gruposVisiveisExtra->map(fn (GrupoSolucao $g) => [
                'id' => $g->id,
                'nome' => $g->nome,
            ])->values(),
        ];
    }
}
