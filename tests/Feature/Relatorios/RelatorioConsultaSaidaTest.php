<?php

namespace Tests\Feature\Relatorios;

use App\Exports\RelatorioTabelaExport;
use App\Models\Customer;
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
 * Saídas do caminho novo: frase de título (JSON) e planilha autoexplicativa.
 */
class RelatorioConsultaSaidaTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeader(): array
    {
        $role = Role::factory()->create(['slug' => 'admin']);
        $role->permissions()->attach(Permission::factory()->create(['slug' => 'relatorios.view']));
        $user = User::factory()->create(['name' => 'Gestor']);
        $user->roles()->attach($role);

        return ['Authorization' => 'Bearer '.$user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken];
    }

    private function evento(Incidente $incidente, User $autor, string $tipo): void
    {
        IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $autor->id,
            'tipo' => $tipo,
            'created_at' => '2026-10-15 12:00:00',
        ]);
    }

    public function test_titulo_describes_query(): void
    {
        $n2 = GrupoSolucao::factory()->create(['nome' => 'N2']);

        $this->getJson(
            "/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista&data_inicio=2026-10-01&data_fim=2026-10-31&grupo_analista_id={$n2->id}",
            $this->adminHeader()
        )->assertOk()->assertJsonPath(
            'titulo',
            'Chamados concluídos (resolvidos ou fechados) de 01/10/2026 a 31/10/2026 por analista — grupo do analista: N2'
        );
    }

    public function test_titulo_with_breakdown_and_no_period_text(): void
    {
        $this->travelTo('2026-11-05 12:00:00');
        $header = $this->adminHeader();

        $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=categoria&periodo=mes_passado&quebra=mes', $header)
            ->assertOk()
            ->assertJsonPath('titulo', 'Chamados abertos (criados) de 01/10/2026 a 31/10/2026 por categoria, por mês');

        $titulo = $this->getJson('/api/relatorios/incidentes?contar=encaminhamentos&agrupar_por=para_grupo', $header)
            ->assertOk()->json('titulo');
        $this->assertSame('Encaminhamentos em todo o período por grupo de destino', $titulo);
    }

    public function test_titulo_lists_filters_in_order_with_status_label(): void
    {
        $titulo = $this->getJson(
            '/api/relatorios/incidentes?contar=cancelados&agrupar_por=tipo&status=em_andamento&tipo=solicitacao',
            $this->adminHeader()
        )->assertOk()->json('titulo');

        $this->assertSame('Chamados cancelados em todo o período por tipo — tipo: Solicitação; status: Em andamento', $titulo);
    }

    public function test_titulo_resolves_customer_filter_name(): void
    {
        $customer = Customer::factory()->create(['name' => 'Maria Portal']);

        $this->getJson(
            "/api/relatorios/incidentes?contar=abertos&agrupar_por=tipo&customer_id={$customer->id}",
            $this->adminHeader()
        )->assertOk()->assertJsonPath('titulo', 'Chamados abertos (criados) em todo o período por tipo — usuário do cliente: Maria Portal');
    }

    public function test_formato_is_validated_together_with_other_fields(): void
    {
        $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=analista&formato=pdf', $this->adminHeader())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['agrupar_por', 'formato']);
    }

    public function test_xlsx_layout(): void
    {
        Excel::fake();
        $header = $this->adminHeader();
        $ana = User::factory()->create(['name' => 'Ana']);
        $beto = User::factory()->create(['name' => 'Beto']);
        $i1 = Incidente::factory()->create();
        $i2 = Incidente::factory()->create();
        // i1 concluído por dois analistas: total geral 3, chamados distintos 2.
        $this->evento($i1, $ana, 'resolvido');
        $this->evento($i1, $beto, 'fechado');
        $this->evento($i2, $ana, 'resolvido');

        $this->get('/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista&formato=xlsx', $header)->assertOk();

        Excel::assertDownloaded('relatorio-concluidos-analista.xlsx', function (RelatorioTabelaExport $e) {
            $a = $e->array();
            $this->assertStringContainsString('Chamados concluídos', $a[0][0]);
            $this->assertStringStartsWith('Gerado em ', $a[1][0]);
            $this->assertStringContainsString('(horário de Brasília) por Gestor', $a[1][0]);
            $this->assertSame([], array_filter($a[2]));
            $this->assertSame(['Analista', 'Total', '%'], $a[3]);
            $this->assertSame(['Ana', 2, 66.7], $a[4]);
            $this->assertSame(['Beto', 1, 33.3], $a[5]);
            $this->assertSame(['Total', 3, 100.0], $a[count($a) - 2]);
            $this->assertSame(['Chamados distintos no resultado: 2'], $a[count($a) - 1]);

            return true;
        });
    }

    public function test_xlsx_omits_distinct_line_when_equal_and_includes_breakdown_columns(): void
    {
        Excel::fake();
        $ana = User::factory()->create(['name' => 'Ana']);
        $this->evento(Incidente::factory()->create(), $ana, 'resolvido');

        $this->get(
            '/api/relatorios/incidentes?contar=concluidos&agrupar_por=analista&quebra=mes&data_inicio=2026-10-01&data_fim=2026-10-31&formato=xlsx',
            $this->adminHeader()
        )->assertOk();

        Excel::assertDownloaded('relatorio-concluidos-analista.xlsx', function (RelatorioTabelaExport $e) {
            $a = $e->array();
            $this->assertSame(['Analista', 'Out/2026', 'Total', '%'], $a[3]);
            $this->assertSame(['Ana', 1, 1, 100.0], $a[4]);
            $this->assertSame(['Total', 1, 1, 100.0], $a[count($a) - 1]);

            return true;
        });
    }
}
