<?php

namespace Tests\Feature\Users;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_shows_no_grupos_extra_by_default(): void
    {
        $user = User::factory()->create();
        $token = $this->staffToken(['users.manage']);

        $response = $this->getJson("/api/users/{$user->id}/grupos-visiveis", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.grupo_solucao_id', $user->grupo_solucao_id)
            ->assertJsonCount(0, 'data.grupos_extra');
    }

    public function test_can_grant_extra_grupos(): void
    {
        $user = User::factory()->create();
        $extra = GrupoSolucao::factory()->create(['nome' => 'Suporte N2']);
        $token = $this->staffToken(['users.manage']);

        $response = $this->putJson(
            "/api/users/{$user->id}/grupos-visiveis",
            ['grupo_solucao_ids' => [$extra->id]],
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonCount(1, 'data.grupos_extra')
            ->assertJsonPath('data.grupos_extra.0.nome', 'Suporte N2');

        $this->assertDatabaseHas('user_grupo_solucao_visibilidade', [
            'user_id' => $user->id, 'grupo_solucao_id' => $extra->id,
        ]);
    }

    public function test_updating_replaces_the_previous_grant_list(): void
    {
        $user = User::factory()->create();
        $antigo = GrupoSolucao::factory()->create();
        $novo = GrupoSolucao::factory()->create();
        $user->gruposVisiveisExtra()->attach($antigo->id);
        $token = $this->staffToken(['users.manage']);

        $this->putJson(
            "/api/users/{$user->id}/grupos-visiveis",
            ['grupo_solucao_ids' => [$novo->id]],
            $this->authHeader($token)
        )->assertOk();

        $this->assertDatabaseMissing('user_grupo_solucao_visibilidade', ['user_id' => $user->id, 'grupo_solucao_id' => $antigo->id]);
        $this->assertDatabaseHas('user_grupo_solucao_visibilidade', ['user_id' => $user->id, 'grupo_solucao_id' => $novo->id]);
    }

    public function test_rejects_granting_the_users_own_grupo_as_extra(): void
    {
        $user = User::factory()->create();
        $token = $this->staffToken(['users.manage']);

        $response = $this->putJson(
            "/api/users/{$user->id}/grupos-visiveis",
            ['grupo_solucao_ids' => [$user->grupo_solucao_id]],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('grupo_solucao_ids.0');
    }

    public function test_view_permission_alone_cannot_update_grupos_visiveis(): void
    {
        $user = User::factory()->create();
        $token = $this->staffToken(['some.other.permission']);

        $this->putJson("/api/users/{$user->id}/grupos-visiveis", ['grupo_solucao_ids' => []], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_grupos_visiveis(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/users/{$user->id}/grupos-visiveis")->assertStatus(401);
    }
}
