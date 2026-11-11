<?php

namespace Tests\Feature\Relatorios;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\RelatorioSalvo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatorioSalvoCrudTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nome' => 'SLA mensal por agente',
            'agrupar_por' => 'responsavel',
            'filtros' => ['status' => 'fechado'],
        ], $overrides);
    }

    public function test_staff_with_manage_permission_can_create_a_relatorio_salvo(): void
    {
        [$token, $user] = $this->staffToken(['relatorios.manage']);

        $response = $this->postJson('/api/relatorios-salvos', $this->validPayload(), $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.nome', 'SLA mensal por agente')
            ->assertJsonPath('data.agrupar_por', 'responsavel')
            ->assertJsonPath('data.filtros.status', 'fechado')
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('relatorios_salvos', ['nome' => 'SLA mensal por agente', 'user_id' => $user->id]);
    }

    public function test_saved_report_accepts_concluido_por(): void
    {
        [$token] = $this->staffToken(['relatorios.manage']);

        $this->postJson('/api/relatorios-salvos', $this->validPayload(['agrupar_por' => 'concluido_por']), $this->authHeader($token))
            ->assertCreated();
    }

    public function test_saved_report_accepts_grupo_analista_id(): void
    {
        [$token] = $this->staffToken(['relatorios.manage']);
        $grupo = GrupoSolucao::factory()->create();

        $this->postJson('/api/relatorios-salvos', $this->validPayload(['filtros' => ['grupo_analista_id' => $grupo->id]]), $this->authHeader($token))
            ->assertCreated()
            ->assertJsonPath('data.filtros.grupo_analista_id', $grupo->id);

        $this->postJson('/api/relatorios-salvos', $this->validPayload(['filtros' => ['grupo_analista_id' => 999999]]), $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('filtros.grupo_analista_id');
    }

    public function test_creating_relatorio_salvo_requires_nome_agrupar_por_and_filtros(): void
    {
        [$token] = $this->staffToken(['relatorios.manage']);

        $response = $this->postJson('/api/relatorios-salvos', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors(['nome', 'agrupar_por', 'filtros']);
    }

    public function test_creating_relatorio_salvo_with_empty_filtros_succeeds(): void
    {
        // 'present', não 'required' — um array vazio é "nenhum filtro
        // aplicado" (relatório sobre todos os dados), não um erro. Achado
        // testando ao vivo: a regra 'required' do Laravel rejeita array
        // vazio, o que quebrava exatamente esse caso de uso.
        [$token] = $this->staffToken(['relatorios.manage']);

        $response = $this->postJson(
            '/api/relatorios-salvos',
            $this->validPayload(['filtros' => []]),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.filtros', []);
    }

    public function test_creating_relatorio_salvo_rejects_invalid_agrupar_por(): void
    {
        [$token] = $this->staffToken(['relatorios.manage']);

        $response = $this->postJson(
            '/api/relatorios-salvos',
            $this->validPayload(['agrupar_por' => 'inventado']),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('agrupar_por');
    }

    public function test_view_only_permission_cannot_create_a_relatorio_salvo(): void
    {
        [$token] = $this->staffToken(['relatorios.view']);

        $this->postJson('/api/relatorios-salvos', $this->validPayload(), $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_staff_with_view_permission_can_list_relatorios_salvos(): void
    {
        RelatorioSalvo::factory()->count(2)->create();
        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios-salvos', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_with_view_permission_can_show_a_relatorio_salvo(): void
    {
        $relatorio = RelatorioSalvo::factory()->create(['nome' => 'Categoria trimestral']);
        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson("/api/relatorios-salvos/{$relatorio->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'Categoria trimestral');
    }

    public function test_staff_with_manage_permission_can_update_a_relatorio_salvo(): void
    {
        $relatorio = RelatorioSalvo::factory()->create(['nome' => 'Original']);
        [$token] = $this->staffToken(['relatorios.manage']);

        $response = $this->putJson(
            "/api/relatorios-salvos/{$relatorio->id}",
            $this->validPayload(['nome' => 'Atualizado']),
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonPath('data.nome', 'Atualizado');
    }

    public function test_staff_with_manage_permission_can_delete_a_relatorio_salvo(): void
    {
        $relatorio = RelatorioSalvo::factory()->create();
        [$token] = $this->staffToken(['relatorios.manage']);

        $response = $this->deleteJson("/api/relatorios-salvos/{$relatorio->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('relatorios_salvos', ['id' => $relatorio->id]);
    }

    public function test_executar_runs_the_saved_filters_against_current_data(): void
    {
        Incidente::factory()->create(['status' => 'fechado']);
        Incidente::factory()->create(['status' => 'aberto']);
        $relatorio = RelatorioSalvo::factory()->create([
            'agrupar_por' => 'status_sla',
            'filtros' => ['status' => 'fechado'],
        ]);
        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson("/api/relatorios-salvos/{$relatorio->id}/executar", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('agrupado_por', 'status_sla');
        $total = collect($response->json('data'))->sum('total');
        $this->assertSame(1, $total);
    }

    public function test_executar_supports_formato_xlsx(): void
    {
        Incidente::factory()->create(['status' => 'fechado']);
        $relatorio = RelatorioSalvo::factory()->create(['agrupar_por' => 'status_sla', 'filtros' => []]);
        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->get(
            "/api/relatorios-salvos/{$relatorio->id}/executar?formato=xlsx",
            $this->authHeader($token)
        );

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_guests_cannot_access_relatorios_salvos(): void
    {
        $this->getJson('/api/relatorios-salvos')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_relatorios_salvos(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/relatorios-salvos', $this->authHeader($token))->assertStatus(401);
    }

    public function test_saved_report_accepts_tipo_filter(): void
    {
        [$token] = $this->staffToken(['relatorios.manage']);

        $this->postJson(
            '/api/relatorios-salvos',
            $this->validPayload(['filtros' => ['tipo' => 'solicitacao']]),
            $this->authHeader($token)
        )->assertCreated()->assertJsonPath('data.filtros.tipo', 'solicitacao');

        $this->postJson(
            '/api/relatorios-salvos',
            $this->validPayload(['filtros' => ['tipo' => 'problema']]),
            $this->authHeader($token)
        )->assertStatus(422)->assertJsonValidationErrors('filtros.tipo');
    }

    public function test_saves_new_shape_with_period_shortcut(): void
    {
        [$token] = $this->staffToken(['relatorios.manage']);
        $payload = [
            'nome' => 'Abertos por categoria',
            'contar' => 'abertos',
            'agrupar_por' => 'categoria',
            'quebra' => 'mes',
            'filtros' => ['periodo' => 'mes_passado'],
        ];

        $this->postJson('/api/relatorios-salvos', $payload, $this->authHeader($token))
            ->assertCreated()
            ->assertJsonPath('data.contar', 'abertos')
            ->assertJsonPath('data.agrupar_por', 'categoria')
            ->assertJsonPath('data.quebra', 'mes')
            ->assertJsonPath('data.filtros.periodo', 'mes_passado')
            ->assertJsonPath('data.formato_antigo', false);

        $this->postJson('/api/relatorios-salvos', [...$payload, 'agrupar_por' => 'analista'], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('agrupar_por');

        $this->postJson('/api/relatorios-salvos', [...$payload, 'filtros' => ['periodo' => 'nunca']], $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('filtros.periodo');
    }

    public function test_saved_shortcut_is_relative_to_execution_date(): void
    {
        $relatorio = RelatorioSalvo::factory()->create([
            'contar' => 'abertos',
            'agrupar_por' => 'categoria',
            'quebra' => 'nenhuma',
            'filtros' => ['periodo' => 'mes_passado'],
        ]);
        [$token] = $this->staffToken(['relatorios.view']);

        $this->travelTo('2026-11-05');
        $this->getJson("/api/relatorios-salvos/{$relatorio->id}/executar", $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('periodo.inicio', '2026-10-01');
        $titulo = $this->getJson("/api/relatorios-salvos/{$relatorio->id}/executar", $this->authHeader($token))->json('titulo');
        $this->assertStringContainsString('de 01/10/2026 a 31/10/2026', $titulo);

        $this->travelTo('2026-12-05');
        $titulo = $this->getJson("/api/relatorios-salvos/{$relatorio->id}/executar", $this->authHeader($token))->json('titulo');
        $this->assertStringContainsString('de 01/11/2026 a 30/11/2026', $titulo);
    }

    public function test_legacy_equivalent_runs_new_path_and_is_not_old_format(): void
    {
        $relatorio = RelatorioSalvo::factory()->create([
            'agrupar_por' => 'fechado_por',
            'filtros' => [],
        ]);
        [$token] = $this->staffToken(['relatorios.view']);

        $this->getJson("/api/relatorios-salvos/{$relatorio->id}", $this->authHeader($token))
            ->assertJsonPath('data.contar', 'fechados')
            ->assertJsonPath('data.agrupar_por', 'fechado_por')
            ->assertJsonPath('data.formato_antigo', false);

        $this->getJson("/api/relatorios-salvos/{$relatorio->id}/executar", $this->authHeader($token))
            ->assertOk()
            ->assertJsonPath('contar', 'fechados')
            ->assertJsonPath('agrupar_por', 'analista')
            ->assertJsonStructure(['titulo', 'colunas', 'linhas', 'totais', 'chamados_distintos']);
    }

    public function test_legacy_without_equivalent_stays_legacy(): void
    {
        [$token] = $this->staffToken(['relatorios.view']);

        $categoria = RelatorioSalvo::factory()->create(['agrupar_por' => 'categoria', 'filtros' => []]);
        $this->getJson("/api/relatorios-salvos/{$categoria->id}", $this->authHeader($token))
            ->assertJsonPath('data.contar', null)
            ->assertJsonPath('data.formato_antigo', true);
        $this->getJson("/api/relatorios-salvos/{$categoria->id}/executar", $this->authHeader($token))
            ->assertOk()
            ->assertJsonStructure(['agrupado_por', 'data']);

        $comDatas = RelatorioSalvo::factory()->create([
            'agrupar_por' => 'aberto_por',
            'filtros' => ['data_inicio' => '2026-10-01', 'data_fim' => '2026-10-31'],
        ]);
        $this->getJson("/api/relatorios-salvos/{$comDatas->id}", $this->authHeader($token))
            ->assertJsonPath('data.contar', null)
            ->assertJsonPath('data.formato_antigo', true);

        $semDatas = RelatorioSalvo::factory()->create(['agrupar_por' => 'aberto_por', 'filtros' => []]);
        $this->getJson("/api/relatorios-salvos/{$semDatas->id}", $this->authHeader($token))
            ->assertJsonPath('data.contar', 'abertos')
            ->assertJsonPath('data.formato_antigo', false);
    }
}
