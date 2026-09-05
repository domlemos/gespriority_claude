<?php

namespace Tests\Feature\Relatorios;

use App\Models\Categoria;
use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatorioIncidentesTest extends TestCase
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

    public function test_agrupar_por_status_sla_counts_within_and_outside_deadline(): void
    {
        // Fechado, concluído antes do prazo.
        Incidente::factory()->create([
            'status' => 'fechado',
            'prazo_resolucao' => now()->addHour(),
            'concluido_em' => now(),
        ]);
        // Fechado, concluído depois do prazo.
        Incidente::factory()->create([
            'status' => 'resolvido',
            'prazo_resolucao' => now()->subHour(),
            'concluido_em' => now(),
        ]);
        // Fechado, sem política de SLA aplicável.
        Incidente::factory()->create([
            'status' => 'fechado',
            'prazo_resolucao' => null,
        ]);
        // Aberto — não deve entrar (sem status explícito, restringe aos concluídos).
        Incidente::factory()->create(['status' => 'aberto']);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=status_sla', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('agrupado_por', 'status_sla');
        $porChave = collect($response->json('data'))->keyBy('chave');
        $this->assertSame(1, $porChave['dentro_prazo']['total']);
        $this->assertSame(1, $porChave['estourado']['total']);
        $this->assertSame(1, $porChave['sem_sla']['total']);
    }

    public function test_agrupar_por_status_sla_with_explicit_status_includes_open_incidentes(): void
    {
        Incidente::factory()->create([
            'status' => 'aberto',
            'prazo_resolucao' => now()->addHour(),
        ]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson(
            '/api/relatorios/incidentes?agrupar_por=status_sla&status=aberto',
            $this->authHeader($token)
        );

        $response->assertOk();
        $porChave = collect($response->json('data'))->keyBy('chave');
        $this->assertSame(1, $porChave['dentro_prazo']['total']);
    }

    public function test_agrupar_por_responsavel_counts_closed_incidentes_per_agent(): void
    {
        $agenteA = User::factory()->create(['name' => 'Agente A']);
        $agenteB = User::factory()->create(['name' => 'Agente B']);
        Incidente::factory()->count(2)->create(['status' => 'fechado', 'responsavel_id' => $agenteA->id]);
        Incidente::factory()->create(['status' => 'resolvido', 'responsavel_id' => $agenteB->id]);
        Incidente::factory()->create(['status' => 'fechado', 'responsavel_id' => null]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=responsavel', $this->authHeader($token));

        $response->assertOk();
        $porRotulo = collect($response->json('data'))->keyBy('rotulo');
        $this->assertSame(2, $porRotulo['Agente A']['total']);
        $this->assertSame(1, $porRotulo['Agente B']['total']);
        $this->assertSame(1, $porRotulo['(sem responsável)']['total']);
    }

    public function test_agrupar_por_responsavel_resolves_name_even_if_agent_was_deactivated(): void
    {
        $agente = User::factory()->create(['name' => 'Agente Desativado']);
        Incidente::factory()->create(['status' => 'fechado', 'responsavel_id' => $agente->id]);
        $agente->delete();

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=responsavel', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.rotulo', 'Agente Desativado');
    }

    public function test_agrupar_por_resolvido_por_counts_resolution_events_per_user(): void
    {
        $agenteA = User::factory()->create(['name' => 'Agente A']);
        $agenteB = User::factory()->create(['name' => 'Agente B']);
        $incidenteA = Incidente::factory()->create(['status' => 'resolvido']);
        $incidenteB = Incidente::factory()->create(['status' => 'fechado']);
        IncidenteEvento::factory()->create(['incidente_id' => $incidenteA->id, 'user_id' => $agenteA->id]);
        IncidenteEvento::factory()->create(['incidente_id' => $incidenteB->id, 'user_id' => $agenteB->id]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=resolvido_por', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('agrupado_por', 'resolvido_por');
        $porRotulo = collect($response->json('data'))->keyBy('rotulo');
        $this->assertSame(1, $porRotulo['Agente A']['total']);
        $this->assertSame(1, $porRotulo['Agente B']['total']);
    }

    public function test_agrupar_por_resolvido_por_counts_each_resolve_reopen_resolve_cycle_separately(): void
    {
        // O cenário que motivou essa dimensão: resolvido pelo Agente A,
        // reaberto, resolvido de novo pelo Agente B — as duas resoluções
        // devem aparecer, não só a mais recente (diferente do que
        // aconteceria olhando só pra Incidente.responsavel_id/concluido_em).
        $agenteA = User::factory()->create(['name' => 'Agente A']);
        $agenteB = User::factory()->create(['name' => 'Agente B']);
        $incidente = Incidente::factory()->create(['status' => 'em_andamento']);
        IncidenteEvento::factory()->create(['incidente_id' => $incidente->id, 'user_id' => $agenteA->id]);
        IncidenteEvento::factory()->create(['incidente_id' => $incidente->id, 'user_id' => $agenteB->id]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=resolvido_por', $this->authHeader($token));

        $porRotulo = collect($response->json('data'))->keyBy('rotulo');
        $this->assertSame(1, $porRotulo['Agente A']['total']);
        $this->assertSame(1, $porRotulo['Agente B']['total']);
        $this->assertSame(2, collect($response->json('data'))->sum('total'));
    }

    public function test_agrupar_por_resolvido_por_filters_by_the_resolution_events_own_date(): void
    {
        $agente = User::factory()->create(['name' => 'Agente Antigo']);
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        $resolucaoAntiga = IncidenteEvento::factory()->create(['incidente_id' => $incidente->id, 'user_id' => $agente->id]);
        $resolucaoAntiga->forceFill(['created_at' => '2026-01-10 10:00:00'])->save();

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson(
            '/api/relatorios/incidentes?agrupar_por=resolvido_por&data_inicio=2026-06-01',
            $this->authHeader($token)
        );

        $this->assertSame(0, collect($response->json('data'))->sum('total'));
    }

    public function test_agrupar_por_resolvido_por_resolves_name_even_if_user_was_deactivated(): void
    {
        $agente = User::factory()->create(['name' => 'Agente Desativado']);
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        IncidenteEvento::factory()->create(['incidente_id' => $incidente->id, 'user_id' => $agente->id]);
        $agente->delete();

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=resolvido_por', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.rotulo', 'Agente Desativado');
    }

    public function test_agrupar_por_fechado_por_counts_closure_events_per_user(): void
    {
        $agenteA = User::factory()->create(['name' => 'Agente A']);
        $agenteB = User::factory()->create(['name' => 'Agente B']);
        $incidenteA = Incidente::factory()->create(['status' => 'fechado']);
        $incidenteB = Incidente::factory()->create(['status' => 'fechado']);
        IncidenteEvento::factory()->create(['incidente_id' => $incidenteA->id, 'user_id' => $agenteA->id, 'tipo' => 'fechado']);
        IncidenteEvento::factory()->create(['incidente_id' => $incidenteB->id, 'user_id' => $agenteB->id, 'tipo' => 'fechado']);
        // Resolvido não deveria contar aqui — dimensões diferentes.
        IncidenteEvento::factory()->create(['incidente_id' => $incidenteA->id, 'user_id' => $agenteA->id, 'tipo' => 'resolvido']);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=fechado_por', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('agrupado_por', 'fechado_por');
        $porRotulo = collect($response->json('data'))->keyBy('rotulo');
        $this->assertSame(1, $porRotulo['Agente A']['total']);
        $this->assertSame(1, $porRotulo['Agente B']['total']);
    }

    public function test_agrupar_por_encaminhado_por_counts_both_grupo_and_responsavel_escalations(): void
    {
        $agente = User::factory()->create(['name' => 'Agente C']);
        $incidente = Incidente::factory()->create();
        IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $agente->id,
            'tipo' => 'encaminhado_grupo',
            'alvo_type' => GrupoSolucao::class,
            'alvo_id' => GrupoSolucao::factory()->create()->id,
        ]);
        IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $agente->id,
            'tipo' => 'encaminhado_responsavel',
            'alvo_type' => User::class,
            'alvo_id' => User::factory()->create()->id,
        ]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=encaminhado_por', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('agrupado_por', 'encaminhado_por');
        $this->assertSame(2, collect($response->json('data'))->firstWhere('rotulo', 'Agente C')['total']);
    }

    public function test_agrupar_por_encaminhado_para_grupo_counts_by_destination_group(): void
    {
        $grupo = GrupoSolucao::factory()->create(['nome' => 'Suporte N2']);
        $incidente = Incidente::factory()->create();
        IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'tipo' => 'encaminhado_grupo',
            'alvo_type' => GrupoSolucao::class,
            'alvo_id' => $grupo->id,
        ]);
        // Não deveria vazar pro agrupamento de grupo.
        IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'tipo' => 'encaminhado_responsavel',
            'alvo_type' => User::class,
            'alvo_id' => User::factory()->create()->id,
        ]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=encaminhado_para_grupo', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rotulo', 'Suporte N2');
    }

    public function test_agrupar_por_encaminhado_para_responsavel_counts_by_destination_user(): void
    {
        $responsavel = User::factory()->create(['name' => 'Ana Souza']);
        $incidente = Incidente::factory()->create();
        IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'tipo' => 'encaminhado_responsavel',
            'alvo_type' => User::class,
            'alvo_id' => $responsavel->id,
        ]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=encaminhado_para_responsavel', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rotulo', 'Ana Souza');
    }

    public function test_agrupar_por_aberto_por_counts_incidentes_by_creator(): void
    {
        $agenteA = User::factory()->create(['name' => 'Agente A']);
        $agenteB = User::factory()->create(['name' => 'Agente B']);
        Incidente::factory()->count(2)->create(['status' => 'aberto', 'criado_por_id' => $agenteA->id]);
        Incidente::factory()->create(['status' => 'fechado', 'criado_por_id' => $agenteB->id]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=aberto_por', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('agrupado_por', 'aberto_por');
        $porRotulo = collect($response->json('data'))->keyBy('rotulo');
        $this->assertSame(2, $porRotulo['Agente A']['total']);
        $this->assertSame(1, $porRotulo['Agente B']['total']);
    }

    public function test_agrupar_por_aberto_por_does_not_restrict_to_closed_incidentes(): void
    {
        // "Aberto por" é volume de quem registrou o chamado, não
        // desempenho de fechamento — sem a restrição implícita de
        // STATUS_CONCLUIDOS (mesmo critério de categoria/subcategoria/item).
        $agente = User::factory()->create(['name' => 'Agente A']);
        Incidente::factory()->create(['status' => 'em_andamento', 'criado_por_id' => $agente->id]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=aberto_por', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.total', 1);
    }

    public function test_agrupar_por_grupo_solucao_counts_closed_incidentes_per_group(): void
    {
        $grupoA = GrupoSolucao::factory()->create(['nome' => 'Suporte N1']);
        $grupoB = GrupoSolucao::factory()->create(['nome' => 'Redes']);
        Incidente::factory()->count(2)->create(['status' => 'fechado', 'grupo_solucao_id' => $grupoA->id]);
        Incidente::factory()->create(['status' => 'resolvido', 'grupo_solucao_id' => $grupoB->id]);
        Incidente::factory()->create(['status' => 'fechado', 'grupo_solucao_id' => null]);
        // Aberto — não deve entrar (sem status explícito, restringe aos concluídos,
        // mesmo critério de `responsavel`).
        Incidente::factory()->create(['status' => 'aberto', 'grupo_solucao_id' => $grupoA->id]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=grupo_solucao', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('agrupado_por', 'grupo_solucao');
        $porRotulo = collect($response->json('data'))->keyBy('rotulo');
        $this->assertSame(2, $porRotulo['Suporte N1']['total']);
        $this->assertSame(1, $porRotulo['Redes']['total']);
        $this->assertSame(1, $porRotulo['(sem grupo de solução)']['total']);
    }

    public function test_agrupar_por_item_categoria_subcategoria_counts_by_classification(): void
    {
        $categoria = Categoria::factory()->create(['nome' => 'Hardware']);
        $subcategoria = Subcategoria::factory()->create(['categoria_id' => $categoria->id, 'nome' => 'Impressora']);
        $item = Item::factory()->create(['subcategoria_id' => $subcategoria->id, 'nome' => 'Sem toner']);

        Incidente::factory()->count(2)->create(['status' => 'aberto', 'item_id' => $item->id]);
        Incidente::factory()->create(['status' => 'fechado', 'item_id' => null]);

        [$token] = $this->staffToken(['relatorios.view']);

        $porItem = $this->getJson('/api/relatorios/incidentes?agrupar_por=item', $this->authHeader($token));
        $porItem->assertOk();
        $this->assertSame(2, collect($porItem->json('data'))->firstWhere('rotulo', 'Sem toner')['total']);
        $this->assertSame(1, collect($porItem->json('data'))->firstWhere('rotulo', '(sem item)')['total']);

        $porSubcategoria = $this->getJson('/api/relatorios/incidentes?agrupar_por=subcategoria', $this->authHeader($token));
        $porSubcategoria->assertOk()->assertJsonPath('data.0.rotulo', 'Impressora')->assertJsonPath('data.0.total', 2);

        $porCategoria = $this->getJson('/api/relatorios/incidentes?agrupar_por=categoria', $this->authHeader($token));
        $porCategoria->assertOk()->assertJsonPath('data.0.rotulo', 'Hardware')->assertJsonPath('data.0.total', 2);
    }

    public function test_agrupar_por_item_does_not_restrict_to_closed_incidentes(): void
    {
        // Sem "fechados" no pedido original pra esse indicador — aberto deve contar.
        $item = Item::factory()->create();
        Incidente::factory()->create(['status' => 'aberto', 'item_id' => $item->id]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=item', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.total', 1);
    }

    public function test_filters_by_data_inicio_and_data_fim_on_concluido_em(): void
    {
        Incidente::factory()->create(['status' => 'fechado', 'concluido_em' => '2026-01-10 10:00:00']);
        Incidente::factory()->create(['status' => 'fechado', 'concluido_em' => '2026-06-15 10:00:00']);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson(
            '/api/relatorios/incidentes?agrupar_por=status_sla&data_inicio=2026-06-01&data_fim=2026-06-30',
            $this->authHeader($token)
        );

        $total = collect($response->json('data'))->sum('total');
        $this->assertSame(1, $total);
    }

    public function test_filters_by_client_id(): void
    {
        $clienteA = Client::factory()->create();
        $clienteB = Client::factory()->create();
        $customerA = Customer::factory()->create(['client_id' => $clienteA->id]);
        $customerB = Customer::factory()->create(['client_id' => $clienteB->id]);
        Incidente::factory()->create(['status' => 'fechado', 'customer_id' => $customerA->id]);
        Incidente::factory()->create(['status' => 'fechado', 'customer_id' => $customerB->id]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson(
            "/api/relatorios/incidentes?agrupar_por=status_sla&client_id={$clienteA->id}",
            $this->authHeader($token)
        );

        $total = collect($response->json('data'))->sum('total');
        $this->assertSame(1, $total);
    }

    public function test_filters_by_grupo_solucao_id(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['status' => 'fechado', 'grupo_solucao_id' => $grupo->id]);
        Incidente::factory()->create(['status' => 'fechado', 'grupo_solucao_id' => null]);

        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson(
            "/api/relatorios/incidentes?agrupar_por=status_sla&grupo_solucao_id={$grupo->id}",
            $this->authHeader($token)
        );

        $total = collect($response->json('data'))->sum('total');
        $this->assertSame(1, $total);
    }

    public function test_formato_xlsx_returns_a_downloadable_spreadsheet(): void
    {
        Incidente::factory()->create(['status' => 'fechado']);
        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->get(
            '/api/relatorios/incidentes?agrupar_por=status_sla&formato=xlsx',
            $this->authHeader($token)
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_requires_agrupar_por(): void
    {
        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('agrupar_por');
    }

    public function test_rejects_invalid_agrupar_por(): void
    {
        [$token] = $this->staffToken(['relatorios.view']);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=inventado', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('agrupar_por');
    }

    public function test_staff_without_relatorios_view_permission_is_forbidden(): void
    {
        [$token] = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/relatorios/incidentes?agrupar_por=status_sla', $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_relatorios(): void
    {
        $this->getJson('/api/relatorios/incidentes?agrupar_por=status_sla')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_relatorios(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/relatorios/incidentes?agrupar_por=status_sla', $this->authHeader($token))
            ->assertStatus(401);
    }
}
