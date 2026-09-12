<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrupoSolucaoPermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function userComRole(array $permissionSlugs, ?string $roleSlug = null): User
    {
        $role = Role::factory()->create($roleSlug ? ['slug' => $roleSlug] : []);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_role_grants_permission_when_grupo_has_no_override(): void
    {
        $user = $this->userComRole(['tickets.manage']);

        $this->assertTrue($user->hasPermission('tickets.manage'));
    }

    public function test_role_does_not_grant_permission_it_never_had_and_grupo_has_no_override(): void
    {
        $user = $this->userComRole(['tickets.view']);

        $this->assertFalse($user->hasPermission('tickets.manage'));
    }

    public function test_grupo_liberada_grants_a_permission_the_role_does_not_have(): void
    {
        $user = $this->userComRole(['tickets.view']);
        $extra = Permission::factory()->create(['slug' => 'tickets.assign']);
        $user->grupoSolucao->permissoesLiberadas()->attach($extra->id, ['tipo' => 'liberada']);

        $this->assertTrue($user->hasPermission('tickets.assign'));
    }

    public function test_grupo_bloqueada_revokes_a_permission_the_role_grants(): void
    {
        $user = $this->userComRole(['tickets.manage']);
        $permission = Permission::where('slug', 'tickets.manage')->sole();
        $user->grupoSolucao->permissoesBloqueadas()->attach($permission->id, ['tipo' => 'bloqueada']);

        $this->assertFalse($user->hasPermission('tickets.manage'));
    }

    public function test_admin_bypasses_grupo_bloqueada(): void
    {
        $admin = $this->userComRole(['tickets.manage'], 'admin');
        $permission = Permission::where('slug', 'tickets.manage')->sole();
        $admin->grupoSolucao->permissoesBloqueadas()->attach($permission->id, ['tipo' => 'bloqueada']);

        $this->assertTrue($admin->hasPermission('tickets.manage'));
    }

    public function test_admin_does_not_gain_permission_via_grupo_liberada(): void
    {
        // Bypass do admin corta os dois lados: grupo nunca acrescenta nem
        // tira permission de um admin, só o Role importa.
        $admin = $this->userComRole(['tickets.view'], 'admin');
        $extra = Permission::factory()->create(['slug' => 'tickets.assign']);
        $admin->grupoSolucao->permissoesLiberadas()->attach($extra->id, ['tipo' => 'liberada']);

        $this->assertFalse($admin->hasPermission('tickets.assign'));
    }

    public function test_isAdmin_is_true_only_for_a_role_with_the_admin_slug(): void
    {
        $admin = $this->userComRole([], 'admin');
        $agente = $this->userComRole([], 'agente');

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($agente->isAdmin());
    }
}
