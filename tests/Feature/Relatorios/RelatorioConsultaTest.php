<?php

namespace Tests\Feature\Relatorios;

use App\Models\Categoria;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caminho novo de `GET /relatorios/incidentes` (com `contar`): consulta,
 * fonte de incidentes (abertos/cancelados) e agregador com colunas por período.
 */
class RelatorioConsultaTest extends TestCase
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

    private function adminHeader(): array
    {
        [$token] = $this->staffToken(['relatorios.view'], asAdmin: true);

        return $this->authHeader($token);
    }

    private function itemDaCategoria(string $nomeCategoria): Item
    {
        $categoria = Categoria::factory()->create(['nome' => $nomeCategoria]);
        $subcategoria = Subcategoria::factory()->create(['categoria_id' => $categoria->id]);

        return Item::factory()->create(['subcategoria_id' => $subcategoria->id]);
    }

    public function test_abertos_counts_all_created_in_period_regardless_of_status(): void
    {
        $item = $this->itemDaCategoria('Rede');
        foreach (['aberto', 'fechado', 'cancelado'] as $status) {
            Incidente::factory()->create(['item_id' => $item->id, 'status' => $status, 'created_at' => '2026-10-15 12:00:00']);
        }
        Incidente::factory()->create(['item_id' => $item->id, 'created_at' => '2026-09-15 12:00:00']);

        $response = $this->getJson(
            '/api/relatorios/incidentes?contar=abertos&agrupar_por=categoria&data_inicio=2026-10-01&data_fim=2026-10-31',
            $this->adminHeader()
        );

        $response->assertOk()
            ->assertJsonPath('contar', 'abertos')
            ->assertJsonPath('agrupar_por', 'categoria')
            ->assertJsonPath('quebra', 'nenhuma')
            ->assertJsonPath('periodo', ['inicio' => '2026-10-01', 'fim' => '2026-10-31'])
            ->assertJsonPath('colunas', [['chave' => 'total', 'rotulo' => 'Total']])
            ->assertJsonCount(1, 'linhas')
            ->assertJsonPath('linhas.0.rotulo', 'Rede')
            ->assertJsonPath('linhas.0.celulas', ['total' => 3])
            ->assertJsonPath('linhas.0.total', 3)
            ->assertJsonPath('totais', ['celulas' => ['total' => 3], 'total' => 3])
            ->assertJsonPath('chamados_distintos', 3);
        $this->assertSame(100.0, $response->json('linhas.0.percentual'));
    }

    public function test_abertos_uses_brasilia_day_and_month_column(): void
    {
        // 02:30 UTC de 01/11 = 23:30 de 31/10 em Brasília.
        Incidente::factory()->create(['created_at' => '2026-11-01 02:30:00']);

        $response = $this->getJson(
            '/api/relatorios/incidentes?contar=abertos&agrupar_por=tipo&quebra=mes&data_inicio=2026-10-01&data_fim=2026-11-30',
            $this->adminHeader()
        );

        $response->assertOk()
            ->assertJsonPath('colunas', [
                ['chave' => '2026-10-01', 'rotulo' => 'Out/2026'],
                ['chave' => '2026-11-01', 'rotulo' => 'Nov/2026'],
            ])
            ->assertJsonPath('linhas.0.chave', 'incidente')
            ->assertJsonPath('linhas.0.rotulo', 'Incidente')
            ->assertJsonPath('linhas.0.celulas', ['2026-10-01' => 1, '2026-11-01' => 0])
            ->assertJsonPath('totais.celulas', ['2026-10-01' => 1, '2026-11-01' => 0]);
    }

    public function test_weekly_breakdown_first_column_is_monday_before_start(): void
    {
        Incidente::factory()->create(['created_at' => '2026-09-29 15:00:00']);
        Incidente::factory()->create(['created_at' => '2026-10-02 15:00:00']);

        $response = $this->getJson(
            '/api/relatorios/incidentes?contar=abertos&agrupar_por=tipo&quebra=semana&data_inicio=2026-10-01&data_fim=2026-10-14',
            $this->adminHeader()
        );

        $response->assertOk()
            ->assertJsonPath('colunas.0', ['chave' => '2026-09-28', 'rotulo' => 'Sem 28/09'])
            ->assertJsonPath('linhas.0.celulas.2026-09-28', 1)
            ->assertJsonPath('linhas.0.total', 1)
            ->assertJsonPath('chamados_distintos', 1);
    }

    public function test_cancelados_filters_by_concluido_em_and_status(): void
    {
        Incidente::factory()->create([
            'status' => 'cancelado',
            'created_at' => '2026-09-10 12:00:00',
            'concluido_em' => '2026-10-10 12:00:00',
        ]);
        Incidente::factory()->create([
            'status' => 'fechado',
            'created_at' => '2026-10-02 12:00:00',
            'concluido_em' => '2026-10-10 12:00:00',
        ]);

        $response = $this->getJson(
            '/api/relatorios/incidentes?contar=cancelados&agrupar_por=tipo&data_inicio=2026-10-01&data_fim=2026-10-31',
            $this->adminHeader()
        );

        $response->assertOk()
            ->assertJsonCount(1, 'linhas')
            ->assertJsonPath('linhas.0.total', 1)
            ->assertJsonPath('totais.total', 1);
    }

    public function test_totals_percent_and_sorting(): void
    {
        $itemB = $this->itemDaCategoria('B Categoria');
        $itemA = $this->itemDaCategoria('A Categoria');
        Incidente::factory()->count(3)->create(['item_id' => $itemA->id]);
        Incidente::factory()->create(['item_id' => $itemB->id]);

        $response = $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=categoria', $this->adminHeader());

        $response->assertOk()
            ->assertJsonPath('periodo', null)
            ->assertJsonPath('linhas.0.rotulo', 'A Categoria')
            ->assertJsonPath('linhas.1.rotulo', 'B Categoria')
            ->assertJsonPath('totais.total', 4);
        $this->assertSame(75.0, $response->json('linhas.0.percentual'));
        $this->assertSame(25.0, $response->json('linhas.1.percentual'));
    }

    public function test_ties_are_sorted_by_label_ignoring_case_and_accents(): void
    {
        foreach (['banco', 'Ápice', 'Acesso'] as $nome) {
            Incidente::factory()->create(['item_id' => $this->itemDaCategoria($nome)->id]);
        }

        $response = $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=categoria', $this->adminHeader());

        $this->assertSame(['Acesso', 'Ápice', 'banco'], collect($response->json('linhas'))->pluck('rotulo')->all());
    }

    public function test_empty_key_is_vazio_with_label(): void
    {
        Incidente::factory()->create(['item_id' => null]);

        $response = $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=item', $this->adminHeader());

        $response->assertOk()
            ->assertJsonPath('linhas.0.chave', '__vazio__')
            ->assertJsonPath('linhas.0.rotulo', '(sem classificação)');
    }

    public function test_aberto_por_keeps_portal_row(): void
    {
        $analista = User::factory()->create(['name' => 'Analista X']);
        Incidente::factory()->create(['criado_por_id' => $analista->id]);
        Incidente::factory()->create(['criado_por_id' => null, 'criado_por_customer_id' => Customer::factory()->create()->id]);
        Incidente::factory()->create(['criado_por_id' => null, 'criado_por_customer_id' => null]);

        $response = $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=aberto_por', $this->adminHeader());

        $response->assertOk();
        $porChave = collect($response->json('linhas'))->keyBy('chave');
        $this->assertSame('Portal (cliente)', $porChave['portal']['rotulo']);
        $this->assertSame(1, $porChave['portal']['total']);
        $this->assertSame('(desconhecido)', $porChave['__vazio__']['rotulo']);
        $this->assertSame('Analista X', $porChave[$analista->id]['rotulo']);
    }

    public function test_responsavel_with_grupo_analista_lists_idle_members_with_zero(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $ativo = User::factory()->create(['name' => 'Ativo', 'grupo_solucao_id' => $grupo->id]);
        User::factory()->create(['name' => 'Ocioso', 'grupo_solucao_id' => $grupo->id]);
        Incidente::factory()->create(['responsavel_id' => $ativo->id]);

        $response = $this->getJson(
            "/api/relatorios/incidentes?contar=abertos&agrupar_por=responsavel&grupo_analista_id={$grupo->id}&quebra=mes&periodo=este_mes",
            $this->adminHeader()
        );

        $response->assertOk()->assertJsonCount(2, 'linhas')
            ->assertJsonPath('linhas.0.rotulo', 'Ativo')
            ->assertJsonPath('linhas.1.rotulo', 'Ocioso')
            ->assertJsonPath('linhas.1.total', 0);
        $this->assertSame([0], array_values($response->json('linhas.1.celulas')));
    }

    public function test_rejects_grouping_not_allowed_for_contar(): void
    {
        $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=analista', $this->adminHeader())
            ->assertStatus(422)
            ->assertJsonValidationErrors('agrupar_por');
    }

    public function test_breakdown_requires_period_and_respects_column_limit(): void
    {
        $header = $this->adminHeader();

        $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=tipo&quebra=dia', $header)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quebra' => 'Informe um período para quebrar por dia.']);

        $this->getJson(
            '/api/relatorios/incidentes?contar=abertos&agrupar_por=tipo&quebra=dia&data_inicio=2026-01-01&data_fim=2026-03-31',
            $header
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quebra' => 'Período longo demais para quebra por dia: máximo de 62 colunas.']);
    }

    public function test_rejects_end_date_before_start_date(): void
    {
        $this->getJson(
            '/api/relatorios/incidentes?contar=abertos&agrupar_por=tipo&data_inicio=2026-10-31&data_fim=2026-10-01',
            $this->adminHeader()
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('data_fim');
    }

    public function test_periodo_and_dates_are_mutually_exclusive(): void
    {
        $this->getJson(
            '/api/relatorios/incidentes?contar=abertos&agrupar_por=tipo&periodo=este_mes&data_inicio=2026-10-01',
            $this->adminHeader()
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('periodo');
    }

    public function test_legacy_path_unchanged_without_contar(): void
    {
        $this->getJson('/api/relatorios/incidentes?agrupar_por=categoria', $this->adminHeader())
            ->assertOk()
            ->assertJsonStructure(['agrupado_por', 'data'])
            ->assertJsonMissingPath('linhas');
    }

    public function test_non_admin_only_counts_visible_incidents(): void
    {
        $n2 = GrupoSolucao::factory()->create(['nome' => 'N2']);
        $n3 = GrupoSolucao::factory()->create(['nome' => 'N3']);
        Incidente::factory()->create(['grupo_solucao_id' => $n2->id]);
        Incidente::factory()->create(['grupo_solucao_id' => $n3->id]);

        [$token] = $this->staffToken(['relatorios.view'], $n2);

        $response = $this->getJson('/api/relatorios/incidentes?contar=abertos&agrupar_por=grupo_chamado', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(1, 'linhas')
            ->assertJsonPath('linhas.0.rotulo', 'N2')
            ->assertJsonPath('totais.total', 1);
    }
}
