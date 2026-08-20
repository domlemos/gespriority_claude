<?php

namespace Tests\Feature\Dashboard;

use App\Models\Categoria;
use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Permission;
use App\Models\PoliticaSla;
use App\Models\Role;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_dashboard_returns_flattened_incidente_information(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Corp']);
        $customer = Customer::factory()->create(['client_id' => $client->id, 'email' => 'joao@acme.com']);
        $categoria = Categoria::factory()->create(['nome' => 'Hardware']);
        $subcategoria = Subcategoria::factory()->create(['categoria_id' => $categoria->id, 'nome' => 'Impressora']);
        $item = Item::factory()->create(['subcategoria_id' => $subcategoria->id, 'nome' => 'Sem toner']);
        $grupoSolucao = GrupoSolucao::factory()->create(['nome' => 'Suporte N1']);
        $responsavel = User::factory()->create(['name' => 'Ana Souza']);
        PoliticaSla::factory()->create([
            'client_id' => $client->id,
            'prioridade' => 'alta',
            'tempo_resposta_minutos' => 90,
            'tempo_resolucao_minutos' => 300,
        ]);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'grupo_solucao_id' => $grupoSolucao->id,
            'responsavel_id' => $responsavel->id,
            'titulo' => 'Impressora não liga',
            'prioridade' => 'alta',
            'origem' => 'portal',
            'status' => 'em_andamento',
        ]);
        // prazo_resposta/prazo_resolucao só são calculados pelo
        // IncidenteController::store() — criar via factory direto no banco
        // não passa por essa lógica, então precisa simular aqui.
        $incidente->forceFill([
            'prazo_resposta' => $incidente->created_at->copy()->addMinutes(90),
            'prazo_resolucao' => $incidente->created_at->copy()->addMinutes(300),
        ])->save();
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.0.numero', $incidente->id)
            ->assertJsonPath('data.0.titulo', 'Impressora não liga')
            ->assertJsonPath('data.0.origem', 'portal')
            ->assertJsonPath('data.0.status', 'em_andamento')
            ->assertJsonPath('data.0.prioridade', 'alta')
            ->assertJsonPath('data.0.cliente', 'Acme Corp')
            ->assertJsonPath('data.0.email_cliente', 'joao@acme.com')
            ->assertJsonPath('data.0.tempo_resposta_minutos', 90)
            ->assertJsonPath('data.0.tempo_resposta_horas', 1.5)
            ->assertJsonPath('data.0.tempo_resolucao_minutos', 300)
            ->assertJsonPath('data.0.tempo_resolucao_horas', 5)
            ->assertJsonPath('data.0.categoria', 'Hardware')
            ->assertJsonPath('data.0.subcategoria', 'Impressora')
            ->assertJsonPath('data.0.item', 'Sem toner')
            ->assertJsonPath('data.0.grupo_solucao', 'Suporte N1')
            ->assertJsonPath('data.0.responsavel', 'Ana Souza')
            ->assertJsonPath('data.0.data_abertura', $incidente->created_at->toJSON())
            ->assertJsonPath('data.0.status_sla_resposta', 'dentro_prazo')
            ->assertJsonPath('data.0.status_sla_resolucao', 'dentro_prazo');

        $this->assertNotNull($response->json('data.0.prazo_resposta'));
        $this->assertNotNull($response->json('data.0.prazo_resolucao'));
        $this->assertEqualsWithDelta(90, $response->json('data.0.tempo_restante_resposta_minutos'), 1);
        $this->assertEqualsWithDelta(300, $response->json('data.0.tempo_restante_resolucao_minutos'), 1);
    }

    public function test_dashboard_shows_estourado_when_still_open_past_the_deadline(): void
    {
        $incidente = Incidente::factory()->create([
            'status' => 'em_andamento',
            'respondido_em' => now(),
        ]);
        $incidente->forceFill(['prazo_resolucao' => now()->subMinutes(30)])->save();
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.status_sla_resolucao', 'estourado');
        $this->assertLessThan(0, $response->json('data.0.tempo_restante_resolucao_minutos'));
    }

    public function test_dashboard_freezes_status_sla_resolucao_using_concluido_em_instead_of_now(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        // Prazo já passou *agora*, mas foi concluído bem antes do prazo —
        // igual ao comportamento já coberto em IncidenteSlaTest, só que
        // verificado de ponta a ponta via HTTP aqui.
        $incidente->forceFill([
            'prazo_resolucao' => now()->subMinutes(10),
            'concluido_em' => now()->subMinutes(120),
        ])->save();
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?status=resolvido', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.status_sla_resolucao', 'dentro_prazo');
    }

    public function test_dashboard_shows_sem_sla_when_there_is_no_applicable_policy(): void
    {
        Incidente::factory()->create(['prioridade' => 'baixa']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.0.status_sla_resposta', 'sem_sla')
            ->assertJsonPath('data.0.status_sla_resolucao', 'sem_sla')
            ->assertJsonPath('data.0.prazo_resposta', null)
            ->assertJsonPath('data.0.prazo_resolucao', null)
            ->assertJsonPath('data.0.tempo_restante_resposta_minutos', null)
            ->assertJsonPath('data.0.tempo_restante_resolucao_minutos', null);
    }

    public function test_dashboard_displays_the_persisted_deadlines_regardless_of_which_policy_calculated_them(): void
    {
        // A resolução de política (específica do cliente vs global) é
        // responsabilidade do IncidenteController::store() (ver
        // IncidenteCrudTest) — o dashboard só lê prazo_resposta/prazo_resolucao
        // já persistidos, não resolve política nenhuma.
        $incidente = Incidente::factory()->create(['prioridade' => 'urgente']);
        $incidente->forceFill([
            'prazo_resposta' => $incidente->created_at->copy()->addMinutes(15),
            'prazo_resolucao' => $incidente->created_at->copy()->addMinutes(240),
        ])->save();
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.0.tempo_resposta_minutos', 15)
            ->assertJsonPath('data.0.tempo_resolucao_minutos', 240);
    }

    public function test_dashboard_shows_null_times_when_no_applicable_sla_policy_exists(): void
    {
        $incidente = Incidente::factory()->create(['prioridade' => 'baixa']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.0.tempo_resposta_minutos', null)
            ->assertJsonPath('data.0.tempo_resposta_horas', null)
            ->assertJsonPath('data.0.tempo_resolucao_minutos', null)
            ->assertJsonPath('data.0.tempo_resolucao_horas', null);
    }

    public function test_dashboard_shows_null_classification_when_item_not_set(): void
    {
        Incidente::factory()->create(['item_id' => null]);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.0.categoria', null)
            ->assertJsonPath('data.0.subcategoria', null)
            ->assertJsonPath('data.0.item', null);
    }

    public function test_dashboard_without_status_filter_defaults_to_aberto_em_andamento_and_pendente_only(): void
    {
        Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'em_andamento']);
        Incidente::factory()->create(['status' => 'pendente']);
        Incidente::factory()->create(['status' => 'resolvido']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(3, 'data');
        $statuses = collect($response->json('data'))->pluck('status');
        $this->assertEqualsCanonicalizing(['aberto', 'em_andamento', 'pendente'], $statuses->all());
    }

    public function test_dashboard_todos_status_bypasses_the_default_status_restriction(): void
    {
        Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'resolvido']);
        Incidente::factory()->create(['status' => 'fechado']);
        Incidente::factory()->create(['status' => 'cancelado']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?todos_status=1', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_dashboard_explicit_status_takes_priority_over_todos_status(): void
    {
        Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'resolvido']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson(
            '/api/dashboard/incidentes?todos_status=1&status=resolvido',
            $this->authHeader($token)
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'resolvido');
    }

    public function test_dashboard_can_filter_by_status(): void
    {
        Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'resolvido']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?status=resolvido', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'resolvido');
    }

    public function test_dashboard_can_filter_by_numero(): void
    {
        $target = Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'aberto']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/dashboard/incidentes?numero={$target->id}", $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero', $target->id);
    }

    public function test_dashboard_can_combine_filters(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['prioridade' => 'alta', 'grupo_solucao_id' => $grupo->id]);
        Incidente::factory()->create(['prioridade' => 'baixa', 'grupo_solucao_id' => $grupo->id]);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson(
            "/api/dashboard/incidentes?prioridade=alta&grupo_solucao_id={$grupo->id}",
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_dashboard_filtering_by_invalid_status_returns_422(): void
    {
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?status=em_orbita', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_dashboard_can_sort_by_titulo_ascending(): void
    {
        Incidente::factory()->create(['titulo' => 'Zebra']);
        Incidente::factory()->create(['titulo' => 'Abacaxi']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?sort_by=titulo&sort_dir=asc', $this->authHeader($token));

        $response->assertOk();
        $titulos = collect($response->json('data'))->pluck('titulo');
        $this->assertSame(['Abacaxi', 'Zebra'], $titulos->all());
    }

    public function test_dashboard_can_sort_by_titulo_descending(): void
    {
        Incidente::factory()->create(['titulo' => 'Zebra']);
        Incidente::factory()->create(['titulo' => 'Abacaxi']);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?sort_by=titulo&sort_dir=desc', $this->authHeader($token));

        $response->assertOk();
        $titulos = collect($response->json('data'))->pluck('titulo');
        $this->assertSame(['Zebra', 'Abacaxi'], $titulos->all());
    }

    public function test_dashboard_can_sort_by_cliente_name_via_joined_relation(): void
    {
        $clientA = Client::factory()->create(['name' => 'Alfa Ltda']);
        $clientZ = Client::factory()->create(['name' => 'Zeta Ltda']);
        $customerA = Customer::factory()->create(['client_id' => $clientA->id]);
        $customerZ = Customer::factory()->create(['client_id' => $clientZ->id]);
        Incidente::factory()->create(['customer_id' => $customerZ->id]);
        Incidente::factory()->create(['customer_id' => $customerA->id]);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?sort_by=cliente&sort_dir=asc', $this->authHeader($token));

        $response->assertOk();
        $clientes = collect($response->json('data'))->pluck('cliente');
        $this->assertSame(['Alfa Ltda', 'Zeta Ltda'], $clientes->all());
    }

    public function test_dashboard_sorting_by_invalid_column_returns_422(): void
    {
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?sort_by=status_sla_resposta', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('sort_by');
    }

    public function test_dashboard_sorting_by_invalid_direction_returns_422(): void
    {
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?sort_by=titulo&sort_dir=sideways', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('sort_dir');
    }

    public function test_staff_without_view_permission_cannot_access_dashboard(): void
    {
        $token = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/dashboard/incidentes', $this->authHeader($token))->assertStatus(403);
    }

    public function test_guests_cannot_access_dashboard(): void
    {
        $this->getJson('/api/dashboard/incidentes')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_dashboard(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/dashboard/incidentes', $this->authHeader($token))->assertStatus(401);
    }
}
