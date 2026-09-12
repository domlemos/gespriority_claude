<?php

namespace Tests\Feature\Authorization;

use App\Models\Anexo;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteRecursosAninhadosVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_cannot_list_descricoes_of_an_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_cannot_list_anexos_of_an_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        Anexo::factory()->create(['incidente_id' => $incidente->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}/anexos", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_can_list_descricoes_of_an_incidente_with_no_grupo(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token))->assertOk();
    }
}
