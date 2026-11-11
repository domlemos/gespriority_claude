<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Support\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrupoSolucaoPermissaoController extends Controller
{
    public function show(GrupoSolucao $grupo_solucao)
    {
        return response()->json(['data' => $this->payload($grupo_solucao)]);
    }

    public function update(Request $request, GrupoSolucao $grupo_solucao)
    {
        $data = $request->validate([
            'liberadas' => ['sometimes', 'array'],
            'liberadas.*' => ['integer', 'distinct', 'exists:permissions,id'],
            'bloqueadas' => ['sometimes', 'array'],
            'bloqueadas.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $liberadas = $data['liberadas'] ?? [];
        $bloqueadas = $data['bloqueadas'] ?? [];

        if (array_intersect($liberadas, $bloqueadas) !== []) {
            throw ValidationException::withMessages([
                'liberadas' => 'Uma permission não pode estar liberada e bloqueada ao mesmo tempo.',
            ]);
        }

        // Substitui a configuração inteira do grupo numa transação — mais
        // simples e sem risco de colidir com a PK composta
        // (grupo_solucao_id, permission_id) do que tentar sync() nas duas
        // relações (permissoesLiberadas/permissoesBloqueadas) separadamente,
        // que compartilham a mesma tabela física.
        $antes = $this->resumoPermissoes($grupo_solucao);

        DB::transaction(function () use ($grupo_solucao, $liberadas, $bloqueadas, $antes) {
            DB::table('grupo_solucao_permissoes')->where('grupo_solucao_id', $grupo_solucao->id)->delete();

            $linhas = collect($liberadas)->map(fn (int $id) => [
                'grupo_solucao_id' => $grupo_solucao->id,
                'permission_id' => $id,
                'tipo' => 'liberada',
            ])->concat(collect($bloqueadas)->map(fn (int $id) => [
                'grupo_solucao_id' => $grupo_solucao->id,
                'permission_id' => $id,
                'tipo' => 'bloqueada',
            ]));

            if ($linhas->isNotEmpty()) {
                DB::table('grupo_solucao_permissoes')->insert($linhas->all());
            }

            $depois = $this->resumoPermissoes($grupo_solucao);
            Auditoria::registrarRelacao($grupo_solucao, 'permissoes_liberadas', $antes['liberada'], $depois['liberada']);
            Auditoria::registrarRelacao($grupo_solucao, 'permissoes_bloqueadas', $antes['bloqueada'], $depois['bloqueada']);
        });

        return response()->json(['data' => $this->payload($grupo_solucao->fresh())]);
    }

    /** @return array{liberada: list<string>, bloqueada: list<string>} slugs por tipo, lidos direto da tabela (sem cache de relação). */
    private function resumoPermissoes(GrupoSolucao $grupo_solucao): array
    {
        $linhas = DB::table('grupo_solucao_permissoes')
            ->join('permissions', 'permissions.id', '=', 'grupo_solucao_permissoes.permission_id')
            ->where('grupo_solucao_id', $grupo_solucao->id)
            ->get(['grupo_solucao_permissoes.tipo', 'permissions.slug']);

        return [
            'liberada' => $linhas->where('tipo', 'liberada')->pluck('slug')->values()->all(),
            'bloqueada' => $linhas->where('tipo', 'bloqueada')->pluck('slug')->values()->all(),
        ];
    }

    private function payload(GrupoSolucao $grupo_solucao): array
    {
        $grupo_solucao->loadMissing('permissoesLiberadas', 'permissoesBloqueadas');

        $liberadasIds = $grupo_solucao->permissoesLiberadas->pluck('id');
        $bloqueadasIds = $grupo_solucao->permissoesBloqueadas->pluck('id');

        return Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'slug' => $permission->slug,
            'estado' => match (true) {
                $liberadasIds->contains($permission->id) => 'liberada',
                $bloqueadasIds->contains($permission->id) => 'bloqueada',
                default => 'padrao',
            },
        ])->values()->all();
    }
}
