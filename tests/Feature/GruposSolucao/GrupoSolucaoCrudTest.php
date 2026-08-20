<?php

namespace Tests\Feature\GruposSolucao;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrupoSolucaoCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     */
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

    public function test_staff_with_view_permission_can_list_grupos_solucao(): void
    {
        GrupoSolucao::factory()->count(2)->create();
        $token = $this->staffToken(['grupos_solucao.view']);

        // +1 pelo grupo criado automaticamente pro usuário autenticado via factory.
        $response = $this->getJson('/api/grupos-solucao', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_staff_with_view_permission_can_view_a_single_grupo_solucao(): void
    {
        $grupo = GrupoSolucao::factory()->create(['nome' => 'Suporte N1']);
        $token = $this->staffToken(['grupos_solucao.view']);

        $response = $this->getJson("/api/grupos-solucao/{$grupo->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'Suporte N1');
    }

    public function test_staff_without_view_permission_cannot_list_grupos_solucao(): void
    {
        $token = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/grupos-solucao', $this->authHeader($token))->assertStatus(403);
    }

    public function test_admin_can_create_a_grupo_solucao(): void
    {
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->postJson('/api/grupos-solucao', ['nome' => 'Suporte N1'], $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.nome', 'Suporte N1')
            ->assertJsonPath('data.ativo', true);

        $this->assertDatabaseHas('grupos_solucao', ['nome' => 'Suporte N1', 'ativo' => true]);
    }

    public function test_view_only_permission_cannot_create_a_grupo_solucao(): void
    {
        $token = $this->staffToken(['grupos_solucao.view']);

        $this->postJson('/api/grupos-solucao', ['nome' => 'Suporte N1'], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_creating_grupo_solucao_requires_nome(): void
    {
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->postJson('/api/grupos-solucao', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_creating_grupo_solucao_rejects_duplicate_nome(): void
    {
        GrupoSolucao::factory()->create(['nome' => 'Suporte N1']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->postJson('/api/grupos-solucao', ['nome' => 'Suporte N1'], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_admin_can_update_a_grupo_solucao(): void
    {
        $grupo = GrupoSolucao::factory()->create(['nome' => 'Old Name']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->putJson("/api/grupos-solucao/{$grupo->id}", ['nome' => 'New Name'], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'New Name');
        $this->assertDatabaseHas('grupos_solucao', ['id' => $grupo->id, 'nome' => 'New Name']);
    }

    public function test_updating_a_grupo_solucao_does_not_collide_with_itself_on_uniqueness(): void
    {
        $grupo = GrupoSolucao::factory()->create(['nome' => 'Suporte N1']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->putJson("/api/grupos-solucao/{$grupo->id}", ['nome' => 'Suporte N1'], $this->authHeader($token));

        $response->assertOk();
    }

    public function test_view_only_permission_cannot_update_a_grupo_solucao(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $token = $this->staffToken(['grupos_solucao.view']);

        $this->putJson("/api/grupos-solucao/{$grupo->id}", ['nome' => 'New Name'], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_admin_can_delete_a_grupo_solucao_without_users(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->deleteJson("/api/grupos-solucao/{$grupo->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('grupos_solucao', ['id' => $grupo->id]);
    }

    public function test_cannot_delete_a_grupo_solucao_that_has_users(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->deleteJson("/api/grupos-solucao/{$grupo->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('grupos_solucao', ['id' => $grupo->id]);
    }

    public function test_cannot_delete_a_grupo_solucao_that_has_incidentes(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->deleteJson("/api/grupos-solucao/{$grupo->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('grupos_solucao', ['id' => $grupo->id]);
    }

    public function test_view_only_permission_cannot_delete_a_grupo_solucao(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $token = $this->staffToken(['grupos_solucao.view']);

        $this->deleteJson("/api/grupos-solucao/{$grupo->id}", [], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_can_filter_grupos_solucao_by_partial_nome(): void
    {
        GrupoSolucao::factory()->create(['nome' => 'Suporte N1']);
        GrupoSolucao::factory()->create(['nome' => 'Infraestrutura']);
        $token = $this->staffToken(['grupos_solucao.view']);

        $response = $this->getJson('/api/grupos-solucao?nome=Sup', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Suporte N1');
    }

    public function test_can_filter_grupos_solucao_by_ativo_true(): void
    {
        GrupoSolucao::factory()->create(['nome' => 'Ativo Grupo', 'ativo' => true]);
        GrupoSolucao::factory()->create(['nome' => 'Inativo Grupo', 'ativo' => false]);
        $token = $this->staffToken(['grupos_solucao.view']);

        // +1 pelo grupo criado automaticamente pro usuário autenticado via factory (ativo=true).
        $response = $this->getJson('/api/grupos-solucao?ativo=1', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_can_filter_grupos_solucao_by_ativo_false(): void
    {
        // Pegadinha do booleano: `ativo=false` precisa realmente filtrar, não devolver tudo
        // silenciosamente (ver `GrupoSolucao::scopeFiltros()` — `array_key_exists`, não `?? null`).
        GrupoSolucao::factory()->create(['nome' => 'Ativo Grupo', 'ativo' => true]);
        GrupoSolucao::factory()->create(['nome' => 'Inativo Grupo', 'ativo' => false]);
        $token = $this->staffToken(['grupos_solucao.view']);

        $response = $this->getJson('/api/grupos-solucao?ativo=0', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Inativo Grupo');
    }

    public function test_guests_cannot_access_grupos_solucao(): void
    {
        $this->getJson('/api/grupos-solucao')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_grupos_solucao(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/grupos-solucao', $this->authHeader($token))->assertStatus(401);
    }
}
