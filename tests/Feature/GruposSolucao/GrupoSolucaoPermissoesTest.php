<?php

namespace Tests\Feature\GruposSolucao;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrupoSolucaoPermissoesTest extends TestCase
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

    public function test_shows_padrao_for_every_permission_when_grupo_has_no_override(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        Permission::factory()->create(['slug' => 'tickets.view']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->getJson("/api/grupos-solucao/{$grupo->id}/permissoes", $this->authHeader($token));

        $response->assertOk();
        $estados = collect($response->json('data'))->pluck('estado')->unique();
        $this->assertEqualsCanonicalizing(['padrao'], $estados->all());
    }

    public function test_can_set_liberadas_and_bloqueadas(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $liberada = Permission::factory()->create(['slug' => 'tickets.assign']);
        $bloqueada = Permission::factory()->create(['slug' => 'tickets.manage']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->putJson(
            "/api/grupos-solucao/{$grupo->id}/permissoes",
            ['liberadas' => [$liberada->id], 'bloqueadas' => [$bloqueada->id]],
            $this->authHeader($token)
        );

        $response->assertOk();
        $porId = collect($response->json('data'))->keyBy('id');
        $this->assertSame('liberada', $porId[$liberada->id]['estado']);
        $this->assertSame('bloqueada', $porId[$bloqueada->id]['estado']);

        $this->assertDatabaseHas('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $liberada->id, 'tipo' => 'liberada',
        ]);
        $this->assertDatabaseHas('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $bloqueada->id, 'tipo' => 'bloqueada',
        ]);
    }

    public function test_updating_replaces_the_previous_configuration(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $permission = Permission::factory()->create(['slug' => 'tickets.assign']);
        $grupo->permissoesLiberadas()->attach($permission->id, ['tipo' => 'liberada']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $this->putJson(
            "/api/grupos-solucao/{$grupo->id}/permissoes",
            ['liberadas' => [], 'bloqueadas' => [$permission->id]],
            $this->authHeader($token)
        )->assertOk();

        $this->assertDatabaseMissing('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $permission->id, 'tipo' => 'liberada',
        ]);
        $this->assertDatabaseHas('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $permission->id, 'tipo' => 'bloqueada',
        ]);
    }

    public function test_rejects_a_permission_id_in_both_lists(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $permission = Permission::factory()->create();
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->putJson(
            "/api/grupos-solucao/{$grupo->id}/permissoes",
            ['liberadas' => [$permission->id], 'bloqueadas' => [$permission->id]],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('liberadas');
    }

    public function test_view_only_permission_cannot_update_grupo_permissoes(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $token = $this->staffToken(['grupos_solucao.view']);

        $this->putJson("/api/grupos-solucao/{$grupo->id}/permissoes", ['liberadas' => []], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_grupo_permissoes(): void
    {
        $grupo = GrupoSolucao::factory()->create();

        $this->getJson("/api/grupos-solucao/{$grupo->id}/permissoes")->assertStatus(401);
    }
}
