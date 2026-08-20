<?php

namespace Tests\Feature\Users;

use App\Models\Anexo;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(string $permissionSlug = 'users.manage'): array
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['slug' => $permissionSlug]);
        $role->permissions()->attach($permission);

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_admin_can_list_users(): void
    {
        User::factory()->count(2)->create();
        [$token] = $this->staffToken();

        $response = $this->getJson('/api/users', $this->authHeader($token));

        // +1 porque o próprio usuário autenticado (criado em staffToken()) também é um User.
        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_admin_can_filter_users_by_partial_name(): void
    {
        [$token] = $this->staffToken();
        User::factory()->create(['name' => 'Ana Silva']);
        User::factory()->create(['name' => 'Bruno Costa']);

        $response = $this->getJson('/api/users?name=ana', $this->authHeader($token));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Ana Silva', $names);
        $this->assertNotContains('Bruno Costa', $names);
    }

    public function test_admin_can_filter_users_by_partial_email(): void
    {
        [$token] = $this->staffToken();
        User::factory()->create(['email' => 'ana@example.com']);
        User::factory()->create(['email' => 'bruno@example.com']);

        $response = $this->getJson('/api/users?email=ana@', $this->authHeader($token));

        $response->assertOk();
        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('ana@example.com', $emails);
        $this->assertNotContains('bruno@example.com', $emails);
    }

    public function test_filtering_users_by_name_with_no_match_returns_empty(): void
    {
        [$token] = $this->staffToken();
        User::factory()->create(['name' => 'Ana Silva']);

        $response = $this->getJson('/api/users?name=zzznomatch', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_filter_users_by_grupo_solucao_id(): void
    {
        [$token] = $this->staffToken();
        $grupoA = GrupoSolucao::factory()->create();
        $grupoB = GrupoSolucao::factory()->create();
        $userA = User::factory()->create(['grupo_solucao_id' => $grupoA->id]);
        User::factory()->create(['grupo_solucao_id' => $grupoB->id]);

        $response = $this->getJson("/api/users?grupo_solucao_id={$grupoA->id}", $this->authHeader($token));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($userA->id, $ids);
    }

    public function test_admin_can_filter_users_by_role_id(): void
    {
        [$token] = $this->staffToken();
        $role = Role::factory()->create();
        $userWithRole = User::factory()->create();
        $userWithRole->roles()->attach($role);
        User::factory()->create();

        $response = $this->getJson("/api/users?role_id={$role->id}", $this->authHeader($token));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($userWithRole->id, $ids);
    }

    public function test_filtering_users_by_nonexistent_grupo_solucao_id_returns_422(): void
    {
        [$token] = $this->staffToken();

        $response = $this->getJson('/api/users?grupo_solucao_id=999999', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('grupo_solucao_id');
    }

    public function test_filtering_users_by_nonexistent_role_id_returns_422(): void
    {
        [$token] = $this->staffToken();

        $response = $this->getJson('/api/users?role_id=999999', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_admin_can_create_a_user_with_roles(): void
    {
        [$token] = $this->staffToken();
        $role = Role::factory()->create();
        $grupoSolucao = GrupoSolucao::factory()->create();

        $response = $this->postJson('/api/users', [
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'grupo_solucao_id' => $grupoSolucao->id,
            'role_ids' => [$role->id],
        ], $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.grupo_solucao_id', $grupoSolucao->id)
            ->assertJsonPath('data.grupo_solucao.nome', $grupoSolucao->nome)
            ->assertJsonPath('data.roles.0', $role->slug);
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com', 'grupo_solucao_id' => $grupoSolucao->id]);
    }

    public function test_creating_user_requires_name_email_password_and_grupo_solucao_id(): void
    {
        [$token] = $this->staffToken();

        $response = $this->postJson('/api/users', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password', 'grupo_solucao_id']);
    }

    public function test_creating_user_requires_grupo_solucao_id(): void
    {
        [$token] = $this->staffToken();

        $response = $this->postJson('/api/users', [
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'password123',
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('grupo_solucao_id');
    }

    public function test_creating_user_rejects_nonexistent_grupo_solucao_id(): void
    {
        [$token] = $this->staffToken();

        $response = $this->postJson('/api/users', [
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'grupo_solucao_id' => 999999,
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('grupo_solucao_id');
    }

    public function test_creating_user_requires_unique_email(): void
    {
        [$token] = $this->staffToken();
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/users', [
            'name' => 'Novo',
            'email' => 'dup@example.com',
            'password' => 'password123',
            'grupo_solucao_id' => GrupoSolucao::factory()->create()->id,
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_admin_can_update_a_user_without_changing_password(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create(['name' => 'Old Name']);
        $originalPassword = $user->password;

        $response = $this->putJson("/api/users/{$user->id}", [
            'name' => 'New Name',
            'email' => $user->email,
            'grupo_solucao_id' => $user->grupo_solucao_id,
        ], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_updating_a_user_without_role_ids_key_preserves_existing_roles(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create(['name' => 'Old Name']);
        $role = Role::factory()->create();
        $user->roles()->attach($role);

        $response = $this->putJson("/api/users/{$user->id}", [
            'name' => 'New Name',
            'email' => $user->email,
            'grupo_solucao_id' => $user->grupo_solucao_id,
        ], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertSame([$role->id], $user->fresh()->roles->pluck('id')->all());
    }

    public function test_admin_can_delete_another_user(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$user->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        [$token, $admin] = $this->staffToken();

        $response = $this->deleteJson("/api/users/{$admin->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_deactivating_a_user_that_is_responsavel_for_an_incidente_still_succeeds(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create();
        Incidente::factory()->create(['responsavel_id' => $user->id]);

        $response = $this->deleteJson("/api/users/{$user->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_deactivating_a_user_that_uploaded_an_anexo_still_succeeds(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create();
        Anexo::factory()->create(['user_id' => $user->id]);

        $response = $this->deleteJson("/api/users/{$user->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_deactivated_users_do_not_appear_in_the_listing(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create();
        $user->delete();

        $response = $this->getJson('/api/users', $this->authHeader($token));

        $response->assertOk();
        $this->assertNotContains($user->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_active_user_is_marked_as_ativo_in_the_resource(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create();

        $response = $this->getJson("/api/users/{$user->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.ativo', true);
    }

    public function test_staff_without_users_manage_permission_is_forbidden(): void
    {
        [$token] = $this->staffToken('some.other.permission');

        $this->getJson('/api/users', $this->authHeader($token))->assertStatus(403);
    }

    public function test_guests_cannot_manage_users(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_customer_guard_cannot_manage_users(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/users', $this->authHeader($token))->assertStatus(401);
    }
}
