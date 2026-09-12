<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteVisibilidadeDetalheTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null, bool $asAdmin = false): array
    {
        $role = Role::factory()->create($asAdmin ? ['slug' => 'admin'] : []);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_cannot_view_a_single_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_can_view_a_single_incidente_with_no_grupo(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token))->assertOk();
    }

    public function test_admin_can_view_a_single_incidente_from_any_grupo(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view'], null, true);

        $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token))->assertOk();
    }

    public function test_cannot_update_an_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", ['titulo' => 'Novo'], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_can_update_an_incidente_within_the_visible_scope(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $meuGrupo->id]);
        [$token] = $this->staffToken(['tickets.manage'], $meuGrupo);

        $this->putJson("/api/incidentes/{$incidente->id}", ['titulo' => 'Novo'], $this->authHeader($token))
            ->assertOk();
    }
}
