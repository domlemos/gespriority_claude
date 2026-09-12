<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRelatorioVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null): array
    {
        $role = Role::factory()->create();

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

    public function test_dashboard_does_not_list_incidentes_from_an_invisible_grupo(): void
    {
        Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_relatorio_grupo_solucao_only_counts_visible_incidentes(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create(['nome' => 'Meu Grupo']);
        $outroGrupo = GrupoSolucao::factory()->create(['nome' => 'Outro Grupo']);
        Incidente::factory()->create(['status' => 'fechado', 'grupo_solucao_id' => $meuGrupo->id]);
        Incidente::factory()->create(['status' => 'fechado', 'grupo_solucao_id' => $outroGrupo->id]);
        [$token] = $this->staffToken(['relatorios.view'], $meuGrupo);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=grupo_solucao', $this->authHeader($token));

        $response->assertOk();
        $rotulos = collect($response->json('data'))->pluck('rotulo');
        $this->assertTrue($rotulos->contains('Meu Grupo'));
        $this->assertFalse($rotulos->contains('Outro Grupo'));
    }

    public function test_relatorio_resolvido_por_only_counts_events_from_visible_incidentes(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $outroGrupo = GrupoSolucao::factory()->create();
        $agente = User::factory()->create(['name' => 'Agente Visível']);
        $agenteFora = User::factory()->create(['name' => 'Agente Invisível']);
        $incidenteVisivel = Incidente::factory()->create(['status' => 'resolvido', 'grupo_solucao_id' => $meuGrupo->id]);
        $incidenteInvisivel = Incidente::factory()->create(['status' => 'resolvido', 'grupo_solucao_id' => $outroGrupo->id]);
        \App\Models\IncidenteEvento::factory()->create(['incidente_id' => $incidenteVisivel->id, 'user_id' => $agente->id]);
        \App\Models\IncidenteEvento::factory()->create(['incidente_id' => $incidenteInvisivel->id, 'user_id' => $agenteFora->id]);
        [$token] = $this->staffToken(['relatorios.view'], $meuGrupo);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=resolvido_por', $this->authHeader($token));

        $rotulos = collect($response->json('data'))->pluck('rotulo');
        $this->assertTrue($rotulos->contains('Agente Visível'));
        $this->assertFalse($rotulos->contains('Agente Invisível'));
    }
}
