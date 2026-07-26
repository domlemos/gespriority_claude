<?php

namespace Tests\Feature\Roles;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleIndexTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(string $permissionSlug = 'users.manage'): string
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['slug' => $permissionSlug]);
        $role->permissions()->attach($permission);

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    public function test_admin_can_list_roles(): void
    {
        Role::factory()->count(2)->create();
        $token = $this->staffToken();

        $response = $this->getJson('/api/roles', ['Authorization' => "Bearer {$token}"]);

        // +1 pela role criada pro próprio usuário de teste ter a permissão.
        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_staff_without_users_manage_permission_is_forbidden(): void
    {
        $token = $this->staffToken('some.other.permission');

        $this->getJson('/api/roles', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    }

    public function test_guests_cannot_list_roles(): void
    {
        $this->getJson('/api/roles')->assertStatus(401);
    }
}
