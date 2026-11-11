<?php

namespace Tests\Feature\Relatorios;

use App\Exports\RelatorioChamadosExport;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * `GET /relatorios/chamados`: a lista por trás de cada linha/célula tem que
 * bater com o número que a consulta mostra.
 */
class RelatorioChamadosTest extends TestCase
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

    public function test_list_matches_cell_for_concluidos(): void
    {
        $a = User::factory()->create(['name' => 'Ana']);
        $incidente = Incidente::factory()->create(['titulo' => 'Impressora']);
        $this->evento($incidente, $a, 'resolvido', '2026-10-14 12:00:00');
        $this->evento($incidente, $a, 'fechado', '2026-10-15 12:00:00');

        $consulta = $this->getJson('/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista', $this->adminHeader());
        $linha = $consulta->json('linhas.0');
        $this->assertSame(1, $linha['total']);

        $response = $this->getJson("/api/relatorios/chamados?contar=concluidos&agrupar_por=analista&chave={$linha['chave']}", $this->adminHeader());

        $response->assertOk()
            ->assertJsonPath('linha', ['chave' => (string) $a->id, 'rotulo' => 'Ana'])
            ->assertJsonPath('coluna', null)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.incidente_id', $incidente->id)
            ->assertJsonPath('data.0.titulo', 'Impressora')
            ->assertJsonPath('data.0.autor', ['id' => $a->id, 'name' => 'Ana'])
            ->assertJsonPath('data.0.destino', null)
            ->assertJsonPath('data.0.acessivel', true)
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1]);
        $this->assertStringStartsWith('Chamados concluídos', $response->json('titulo'));
        $this->assertSame('2026-10-15T12:00:00', substr($response->json('data.0.data'), 0, 19));
    }

    public function test_list_matches_event_count_for_encaminhamentos(): void
    {
        $c = User::factory()->create(['name' => 'Carla']);
        $alvo = User::factory()->create(['name' => 'Davi']);
        [$i1, $i2] = Incidente::factory()->count(2)->create()->all();
        $grupo = GrupoSolucao::factory()->create(['nome' => 'Suporte N3']);

        $this->evento($i1, $c, 'encaminhado_grupo', extra: ['alvo_type' => GrupoSolucao::class, 'alvo_id' => $grupo->id]);
        $this->evento($i1, $c, 'encaminhado_responsavel', extra: ['alvo_type' => User::class, 'alvo_id' => $alvo->id]);
        $this->evento($i2, $c, 'encaminhado_grupo', extra: ['alvo_type' => GrupoSolucao::class, 'alvo_id' => $grupo->id]);

        $response = $this->getJson("/api/relatorios/chamados?contar=encaminhamentos&agrupar_por=quem_encaminhou&chave={$c->id}", $this->adminHeader());

        $response->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $destinos = collect($response->json('data'))->pluck('destino')->sort()->values()->all();
        $this->assertSame(['Davi', 'Suporte N3', 'Suporte N3'], $destinos);
    }

    public function test_list_filters_by_column(): void
    {
        $a = User::factory()->create(['name' => 'Ana']);
        $incidente = Incidente::factory()->create();
        $this->evento($incidente, $a, 'resolvido', '2026-10-06 15:00:00');
        $this->evento($incidente, $a, 'fechado', '2026-10-14 15:00:00');
        $base = "/api/relatorios/chamados?contar=concluidos&agrupar_por=analista&quebra=semana&data_inicio=2026-10-05&data_fim=2026-10-18&chave={$a->id}";

        $semana1 = $this->getJson($base.'&coluna=2026-10-05', $this->adminHeader());
        $semana1->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('coluna', ['chave' => '2026-10-05', 'rotulo' => 'Sem 05/10']);
        $this->assertSame('2026-10-06', substr($semana1->json('data.0.data'), 0, 10));

        $todas = $this->getJson($base, $this->adminHeader());
        $todas->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('coluna', null);
        // Sem coluna, vale o evento mais recente do escopo.
        $this->assertSame('2026-10-14', substr($todas->json('data.0.data'), 0, 10));

        $this->getJson($base.'&coluna=2026-01-05', $this->adminHeader())->assertStatus(422)->assertJsonValidationErrors('coluna');
    }

    public function test_list_marks_tickets_outside_visible_groups(): void
    {
        $n2 = GrupoSolucao::factory()->create();
        $n3 = GrupoSolucao::factory()->create();
        [$token] = $this->staffToken(['relatorios.view'], $n2);
        $doN2 = User::factory()->create(['name' => 'Do N2', 'grupo_solucao_id' => $n2->id]);

        $movido = Incidente::factory()->create(['grupo_solucao_id' => $n3->id]);
        $this->evento($movido, $doN2, 'resolvido', extra: ['grupo_solucao_id' => $n2->id, 'autor_grupo_solucao_id' => $n2->id]);
        $proprio = Incidente::factory()->create(['grupo_solucao_id' => $n2->id]);
        $this->evento($proprio, $doN2, 'resolvido', extra: ['grupo_solucao_id' => $n2->id, 'autor_grupo_solucao_id' => $n2->id]);

        $response = $this->getJson("/api/relatorios/chamados?contar=concluidos&agrupar_por=analista&chave={$doN2->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
        $porId = collect($response->json('data'))->pluck('acessivel', 'incidente_id')->all();
        $this->assertFalse($porId[$movido->id]);
        $this->assertTrue($porId[$proprio->id]);
        $this->assertSame($n3->id, collect($response->json('data'))->firstWhere('incidente_id', $movido->id)['grupo_chamado']['id']);
    }

    public function test_list_vazio_key(): void
    {
        Incidente::factory()->create(['item_id' => null]);

        $response = $this->getJson('/api/relatorios/chamados?contar=abertos&agrupar_por=item&chave=__vazio__', $this->adminHeader());

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('linha.chave', '__vazio__')
            ->assertJsonPath('linha.rotulo', '(sem classificação)')
            ->assertJsonPath('data.0.autor', null);
    }

    public function test_list_paginates_ordered_by_date_desc(): void
    {
        $a = User::factory()->create();
        foreach ([1, 2, 3] as $dia) {
            $this->evento(Incidente::factory()->create(), $a, 'resolvido', "2026-10-0{$dia} 12:00:00");
        }

        $response = $this->getJson("/api/relatorios/chamados?contar=resolvidos&agrupar_por=analista&chave={$a->id}&per_page=2&page=2", $this->adminHeader());

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
        $this->assertSame('2026-10-01', substr($response->json('data.0.data'), 0, 10));

        $this->getJson("/api/relatorios/chamados?contar=resolvidos&agrupar_por=analista&chave={$a->id}&per_page=500", $this->adminHeader())
            ->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_list_requires_chave(): void
    {
        $this->getJson('/api/relatorios/chamados?contar=abertos&agrupar_por=item', $this->adminHeader())
            ->assertStatus(422)->assertJsonValidationErrors('chave');
    }

    public function test_list_xlsx(): void
    {
        Excel::fake();
        $a = User::factory()->create(['name' => 'Ana']);
        $incidente = Incidente::factory()->create(['titulo' => 'Impressora']);
        $this->evento($incidente, $a, 'resolvido', '2026-10-15 02:30:00');

        $this->get("/api/relatorios/chamados?contar=concluidos&agrupar_por=analista&chave={$a->id}&formato=xlsx", $this->adminHeader())->assertOk();

        Excel::assertDownloaded('relatorio-chamados.xlsx', function (RelatorioChamadosExport $e) use ($incidente) {
            $x = $e->array();
            $this->assertStringStartsWith('Chamados concluídos', $x[0][0]);
            $this->assertSame(['Linha: Ana'], $x[1]);
            $this->assertStringStartsWith('Gerado em ', $x[2][0]);
            $this->assertSame([''], $x[3]);
            $this->assertSame(['Nº', 'Título', 'Status', 'Data', 'Analista', 'Grupo do chamado'], $x[4]);
            $this->assertSame($incidente->id, $x[5][0]);
            $this->assertSame('Impressora', $x[5][1]);
            $this->assertSame('14/10/2026 23:30', $x[5][3]);
            $this->assertSame('Ana', $x[5][4]);

            return true;
        });
    }

    public function test_list_xlsx_encaminhamentos_has_destino_column(): void
    {
        Excel::fake();
        $c = User::factory()->create();
        $grupo = GrupoSolucao::factory()->create(['nome' => 'N3']);
        $this->evento(Incidente::factory()->create(), $c, 'encaminhado_grupo', extra: ['alvo_type' => GrupoSolucao::class, 'alvo_id' => $grupo->id]);

        $this->get("/api/relatorios/chamados?contar=encaminhamentos&agrupar_por=quem_encaminhou&chave={$c->id}&formato=xlsx", $this->adminHeader())->assertOk();

        Excel::assertDownloaded('relatorio-chamados.xlsx', function (RelatorioChamadosExport $e) {
            $x = $e->array();
            $this->assertSame(['Nº', 'Título', 'Status', 'Data', 'Analista', 'Grupo do chamado', 'Destino'], $x[4]);
            $this->assertSame('N3', $x[5][6]);

            return true;
        });
    }
}
