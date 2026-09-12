<?php

namespace Tests\Feature\Users;

use App\Models\GrupoSolucao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserGruposVisiveisExtraRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gruposVisiveisExtra_returns_attached_grupos(): void
    {
        $user = User::factory()->create();
        $grupoExtra = GrupoSolucao::factory()->create();

        $user->gruposVisiveisExtra()->attach($grupoExtra->id);

        $this->assertTrue($user->gruposVisiveisExtra->contains('id', $grupoExtra->id));
    }

    public function test_gruposVisiveisExtra_does_not_include_the_users_own_grupo_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->gruposVisiveisExtra);
    }
}
