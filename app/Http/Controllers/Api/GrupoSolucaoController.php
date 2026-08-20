<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GrupoSolucaoResource;
use App\Models\GrupoSolucao;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GrupoSolucaoController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'nome' => ['sometimes', 'string'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        return GrupoSolucaoResource::collection(
            GrupoSolucao::query()
                ->filtros($filtros)
                ->orderBy('nome')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $grupo = GrupoSolucao::query()->create($data)->refresh();

        return (new GrupoSolucaoResource($grupo))->response()->setStatusCode(201);
    }

    public function show(GrupoSolucao $grupo_solucao)
    {
        return new GrupoSolucaoResource($grupo_solucao);
    }

    public function update(Request $request, GrupoSolucao $grupo_solucao)
    {
        $data = $request->validate($this->rules($grupo_solucao));

        $grupo_solucao->update($data);

        return new GrupoSolucaoResource($grupo_solucao);
    }

    public function destroy(GrupoSolucao $grupo_solucao)
    {
        if ($grupo_solucao->users()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir um grupo de solução com usuários vinculados.',
            ], 409);
        }

        if ($grupo_solucao->incidentes()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir um grupo de solução com incidentes vinculados.',
            ], 409);
        }

        $grupo_solucao->delete();

        return response()->noContent();
    }

    private function rules(?GrupoSolucao $grupo = null): array
    {
        return [
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('grupos_solucao', 'nome')->ignore($grupo?->id),
            ],
            'ativo' => ['boolean'],
        ];
    }
}
