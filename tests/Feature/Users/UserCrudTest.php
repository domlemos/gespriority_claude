<?php

namespace Tests\Feature\Users;

use App\Models\Customer;
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

    public function test_admin_can_create_a_user_with_roles(): void
    {
        [$token] = $this->staffToken();
        $role = Role::factory()->create();

        $response = $this->postJson('/api/users', [
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'role_ids' => [$role->id],
        ], $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.roles.0', $role->slug);
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
    }

    public function test_creating_user_requires_name_email_and_password(): void
    {
        [$token] = $this->staffToken();

        $response = $this->postJson('/api/users', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_creating_user_requires_unique_email(): void
    {
        [$token] = $this->staffToken();
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/users', [
            'name' => 'Novo',
            'email' => 'dup@example.com',
            'password' => 'password123',
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
        ], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_admin_can_delete_another_user(): void
    {
        [$token] = $this->staffToken();
        $user = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$user->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        [$token, $admin] = $this->staffToken();

        $response = $this->deleteJson("/api/users/{$admin->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
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
