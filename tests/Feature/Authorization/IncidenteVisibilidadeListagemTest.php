<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteVisibilidadeListagemTest extends TestCase
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

    public function test_user_does_not_see_incidentes_from_a_grupo_it_has_no_access_to(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $outroGrupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $outroGrupo->id]);
        [$token] = $this->staffToken(['tickets.view'], $meuGrupo);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_sees_incidentes_from_its_own_grupo(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $meuGrupo->id]);
        [$token] = $this->staffToken(['tickets.view'], $meuGrupo);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_user_sees_incidentes_with_no_grupo_regardless_of_own_grupo(): void
    {
        Incidente::factory()->create(['grupo_solucao_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_user_sees_incidentes_from_an_extra_granted_grupo(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $extra = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $extra->id]);
        [$token, $user] = $this->staffToken(['tickets.view'], $meuGrupo);
        $user->gruposVisiveisExtra()->attach($extra->id);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_sees_incidentes_from_every_grupo(): void
    {
        Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view'], null, true);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }
}
