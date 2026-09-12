<?php

namespace Tests\Feature\GruposSolucao;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrupoSolucaoPermissaoOverrideRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissoes_liberadas_returns_only_pivot_rows_marked_as_liberada(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $liberada = Permission::factory()->create(['slug' => 'tickets.assign']);
        $bloqueada = Permission::factory()->create(['slug' => 'tickets.manage']);

        $grupo->permissoesLiberadas()->attach($liberada->id, ['tipo' => 'liberada']);
        $grupo->permissoesBloqueadas()->attach($bloqueada->id, ['tipo' => 'bloqueada']);

        $this->assertTrue($grupo->permissoesLiberadas->contains('id', $liberada->id));
        $this->assertFalse($grupo->permissoesLiberadas->contains('id', $bloqueada->id));
    }

    public function test_permissoes_bloqueadas_returns_only_pivot_rows_marked_as_bloqueada(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $liberada = Permission::factory()->create(['slug' => 'tickets.assign']);
        $bloqueada = Permission::factory()->create(['slug' => 'tickets.manage']);

        $grupo->permissoesLiberadas()->attach($liberada->id, ['tipo' => 'liberada']);
        $grupo->permissoesBloqueadas()->attach($bloqueada->id, ['tipo' => 'bloqueada']);

        $this->assertTrue($grupo->permissoesBloqueadas->contains('id', $bloqueada->id));
        $this->assertFalse($grupo->permissoesBloqueadas->contains('id', $liberada->id));
    }
}
