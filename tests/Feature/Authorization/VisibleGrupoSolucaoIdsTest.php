<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisibleGrupoSolucaoIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_for_an_admin(): void
    {
        $role = Role::factory()->create(['slug' => 'admin']);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->assertNull($user->visibleGrupoSolucaoIds());
    }

    public function test_returns_only_the_own_grupo_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertSame([$user->grupo_solucao_id], $user->visibleGrupoSolucaoIds());
    }

    public function test_includes_extra_granted_grupos(): void
    {
        $user = User::factory()->create();
        $extra = GrupoSolucao::factory()->create();
        $user->gruposVisiveisExtra()->attach($extra->id);

        $ids = $user->visibleGrupoSolucaoIds();

        $this->assertEqualsCanonicalizing([$user->grupo_solucao_id, $extra->id], $ids);
    }
}
