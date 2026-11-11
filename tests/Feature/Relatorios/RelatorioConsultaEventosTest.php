<?php

namespace Tests\Feature\Relatorios;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caminho novo de `GET /relatorios/incidentes` com `contar` baseado em
 * eventos: concluídos/resolvidos/fechados/encaminhamentos, filtros por
 * snapshot, visibilidade do time e zero-fill.
 */
class RelatorioConsultaEventosTest extends TestCase
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

    private ?array $headerAdmin = null;

    /** Um único admin por teste: o slug `admin` é único e vários GETs reutilizam o header. */
    private function adminHeader(): array
    {
        if ($this->headerAdmin === null) {
            [$token] = $this->staffToken(['relatorios.view'], asAdmin: true);
            $this->headerAdmin = $this->authHeader($token);
        }

        return $this->headerAdmin;
    }

    private function evento(Incidente $incidente, User $autor, string $tipo, string $quando = '2026-10-15 12:00:00', array $extra = []): IncidenteEvento
    {
        return IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $autor->id,
            'tipo' => $tipo,
            'created_at' => $quando,
            ...$extra,
        ]);
    }

    /** A resolve+fecha o 1, fecha o 2; B resolve o 3. */
    private function cenarioConclusoes(): array
    {
        $a = User::factory()->create(['name' => 'Ana']);
        $b = User::factory()->create(['name' => 'Beto']);
        [$i1, $i2, $i3] = Incidente::factory()->count(3)->create()->all();

        $this->evento($i1, $a, 'resolvido');
        $this->evento($i1, $a, 'fechado');
        $this->evento($i2, $a, 'fechado');
        $this->evento($i3, $b, 'resolvido');

        return [$a, $b];
    }

    private function porRotulo(array $linhas): array
    {
        return collect($linhas)->mapWithKeys(fn ($l) => [$l['rotulo'] => $l['total']])->all();
    }

    public function test_concluidos_counts_resolved_and_closed_once_per_ticket_per_analyst(): void
    {
        $this->cenarioConclusoes();

        $response = $this->getJson('/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista', $this->adminHeader());

        $response->assertOk()->assertJsonPath('chamados_distintos', 3);
        $this->assertSame(['Ana' => 2, 'Beto' => 1], $this->porRotulo($response->json('linhas')));
    }

    public function test_resolvidos_and_fechados_use_single_event_type(): void
    {
        $this->cenarioConclusoes();

        $resolvidos = $this->getJson('/api/relatorios/incidentes?contar=resolvidos&agrupar_por=analista', $this->adminHeader());
        $fechados = $this->getJson('/api/relatorios/incidentes?contar=fechados&agrupar_por=analista', $this->adminHeader());

        $this->assertSame(['Ana' => 1, 'Beto' => 1], $this->porRotulo($resolvidos->json('linhas')));
        $this->assertSame(['Ana' => 2], $this->porRotulo($fechados->json('linhas')));
    }

    public function test_encaminhamentos_count_events(): void
    {
        $c = User::factory()->create(['name' => 'Carla']);
        [$i1, $i2] = Incidente::factory()->count(2)->create()->all();
        $grupo = GrupoSolucao::factory()->create();

        $this->evento($i1, $c, 'encaminhado_grupo', extra: ['alvo_type' => GrupoSolucao::class, 'alvo_id' => $grupo->id]);
        $this->evento($i1, $c, 'encaminhado_responsavel', extra: ['alvo_type' => User::class, 'alvo_id' => $c->id]);
        $this->evento($i2, $c, 'encaminhado_grupo', extra: ['alvo_type' => GrupoSolucao::class, 'alvo_id' => $grupo->id]);

        $response = $this->getJson('/api/relatorios/incidentes?contar=encaminhamentos&agrupar_por=quem_encaminhou', $this->adminHeader());

        $response->assertOk()->assertJsonPath('chamados_distintos', 2);
        $this->assertSame(['Carla' => 3], $this->porRotulo($response->json('linhas')));

        $paraGrupo = $this->getJson('/api/relatorios/incidentes?contar=encaminhamentos&agrupar_por=para_grupo', $this->adminHeader());
        $this->assertSame([$grupo->nome => 2, '(sem grupo)' => 1], $this->porRotulo($paraGrupo->json('linhas')));
    }

    public function test_weekly_breakdown_counts_ticket_in_each_week_it_was_concluded(): void
    {
        $a = User::factory()->create(['name' => 'Ana']);
        $incidente = Incidente::factory()->create();
        $this->evento($incidente, $a, 'resolvido', '2026-10-06 15:00:00');
        $this->evento($incidente, $a, 'fechado', '2026-10-14 15:00:00');

        $response = $this->getJson(
            '/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista&quebra=semana&data_inicio=2026-10-05&data_fim=2026-10-18',
            $this->adminHeader()
        );

        $response->assertOk()
            ->assertJsonPath('linhas.0.celulas', ['2026-10-05' => 1, '2026-10-12' => 1])
            ->assertJsonPath('linhas.0.total', 2)
            ->assertJsonPath('chamados_distintos', 1);
    }

    public function test_event_filters_use_snapshots(): void
    {
        $n2 = GrupoSolucao::factory()->create();
        $n3 = GrupoSolucao::factory()->create();
        $analista = User::factory()->create(['grupo_solucao_id' => $n2->id]);
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $n3->id]);
        $this->evento($incidente, $analista, 'resolvido', extra: ['grupo_solucao_id' => $n2->id, 'autor_grupo_solucao_id' => $n2->id]);

        $total = fn (string $filtro) => $this->getJson(
            "/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista&{$filtro}",
            $this->adminHeader()
        )->assertOk()->json('totais.total');

        $this->assertSame(1, $total("grupo_solucao_id={$n2->id}"));
        $this->assertSame(0, $total("grupo_solucao_id={$n3->id}"));
        $this->assertSame(1, $total("grupo_analista_id={$n2->id}"));
    }

    public function test_non_admin_sees_own_team_events_on_tickets_moved_away(): void
    {
        $n2 = GrupoSolucao::factory()->create();
        $n3 = GrupoSolucao::factory()->create();
        $n4 = GrupoSolucao::factory()->create();
        [$token] = $this->staffToken(['relatorios.view'], $n2);

        $doN2 = User::factory()->create(['name' => 'Do N2', 'grupo_solucao_id' => $n2->id]);
        $doN4 = User::factory()->create(['name' => 'Do N4', 'grupo_solucao_id' => $n4->id]);

        $movido = Incidente::factory()->create(['grupo_solucao_id' => $n3->id]);
        $this->evento($movido, $doN2, 'resolvido', extra: ['grupo_solucao_id' => $n2->id, 'autor_grupo_solucao_id' => $n2->id]);
        $alheio = Incidente::factory()->create(['grupo_solucao_id' => $n4->id]);
        $this->evento($alheio, $doN4, 'resolvido', extra: ['grupo_solucao_id' => $n4->id, 'autor_grupo_solucao_id' => $n4->id]);

        $response = $this->getJson('/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista', $this->authHeader($token));

        $response->assertOk();
        $this->assertSame(['Do N2' => 1], $this->porRotulo($response->json('linhas')));
    }

    public function test_zero_fill_for_analyst_group(): void
    {
        $n2 = GrupoSolucao::factory()->create();
        $a = User::factory()->create(['name' => 'Ana', 'grupo_solucao_id' => $n2->id]);
        User::factory()->create(['name' => 'Carla', 'grupo_solucao_id' => $n2->id]);
        User::factory()->create(['name' => 'Davi', 'grupo_solucao_id' => $n2->id])->delete();
        $this->evento(Incidente::factory()->create(), $a, 'resolvido', extra: ['autor_grupo_solucao_id' => $n2->id]);

        $response = $this->getJson("/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista&grupo_analista_id={$n2->id}", $this->adminHeader());

        $response->assertOk();
        $this->assertSame(['Ana' => 1, 'Carla' => 0], $this->porRotulo($response->json('linhas')));

        $mensal = $this->getJson(
            "/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista&grupo_analista_id={$n2->id}&quebra=mes&data_inicio=2026-09-01&data_fim=2026-10-31",
            $this->adminHeader()
        );
        $carla = collect($mensal->json('linhas'))->firstWhere('rotulo', 'Carla');
        $this->assertSame(['2026-09-01' => 0, '2026-10-01' => 0], $carla['celulas']);
    }

    public function test_status_sla_grouping_for_concluidos(): void
    {
        $a = User::factory()->create();
        $dentro = Incidente::factory()->create(['prazo_resolucao' => '2026-10-20 12:00:00', 'concluido_em' => '2026-10-15 12:00:00']);
        $fora = Incidente::factory()->create(['prazo_resolucao' => '2026-10-10 12:00:00', 'concluido_em' => '2026-10-15 12:00:00']);
        $this->evento($dentro, $a, 'resolvido');
        $this->evento($fora, $a, 'resolvido');

        $response = $this->getJson('/api/relatorios/incidentes?contar=concluidos&agrupar_por=status_sla', $this->adminHeader());

        $response->assertOk();
        $this->assertSame(['Dentro do prazo' => 1, 'Fora do prazo' => 1], $this->porRotulo($response->json('linhas')));
    }
}
