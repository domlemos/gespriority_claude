<?php

namespace Tests\Feature\Dashboard;

use App\Models\Categoria;
use App\Models\Client;
use App\Models\Customer;
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
        PoliticaSla::factory()->create([
            'client_id' => $client->id,
            'prioridade' => 'alta',
            'tempo_resposta_minutos' => 90,
            'tempo_resolucao_minutos' => 300,
        ]);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'titulo' => 'Impressora não liga',
            'prioridade' => 'alta',
            'origem' => 'portal',
            'status' => 'em_andamento',
        ]);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0', [
            'numero' => $incidente->id,
            'titulo' => 'Impressora não liga',
            'origem' => 'portal',
            'status' => 'em_andamento',
            'prioridade' => 'alta',
            'cliente' => 'Acme Corp',
            'email_cliente' => 'joao@acme.com',
            'tempo_resposta_minutos' => 90,
            'tempo_resposta_horas' => 1.5,
            'tempo_resolucao_minutos' => 300,
            'tempo_resolucao_horas' => 5,
            'categoria' => 'Hardware',
            'subcategoria' => 'Impressora',
            'item' => 'Sem toner',
        ]);
    }

    public function test_dashboard_falls_back_to_global_sla_policy_when_client_has_no_override(): void
    {
        PoliticaSla::factory()->create([
            'client_id' => null,
            'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15,
            'tempo_resolucao_minutos' => 240,
        ]);
        $incidente = Incidente::factory()->create(['prioridade' => 'urgente']);
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
