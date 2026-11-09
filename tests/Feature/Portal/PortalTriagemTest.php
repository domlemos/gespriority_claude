<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Permission;
use App\Models\PoliticaSla;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortalTriagemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     */
    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function abrirPeloPortal(Customer $customer): int
    {
        $token = $customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;

        return $this->postJson('/api/customer/incidentes', [
            'titulo' => 'Sistema fora do ar',
            'descricao' => 'Desde as 9h não consigo acessar.',
        ], $this->authHeader($token))->assertCreated()->json('data.id');
    }

    public function test_first_classification_sets_priority_and_sla_from_opening(): void
    {
        $abertura = Carbon::parse('2026-11-10 09:00:00');
        $this->travelTo($abertura);
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => $client->id, 'prioridade' => 'alta',
            'tempo_resposta_minutos' => 60, 'tempo_resolucao_minutos' => 240,
        ]);
        $item = Item::factory()->create(['prioridade_padrao' => 'alta']);
        $id = $this->abrirPeloPortal($customer);

        $this->travelTo($abertura->copy()->addHours(3));
        $this->putJson(
            "/api/incidentes/{$id}",
            ['item_id' => $item->id],
            $this->authHeader($this->staffToken(['tickets.manage']))
        )->assertOk();

        $incidente = Incidente::query()->findOrFail($id);
        $this->assertSame('alta', $incidente->prioridade);
        $this->assertTrue($incidente->prazo_resposta->equalTo($abertura->copy()->addMinutes(60)));
        $this->assertTrue($incidente->prazo_resolucao->equalTo($abertura->copy()->addMinutes(240)));
        $this->assertSame(Incidente::SLA_ESTOURADO, $incidente->statusSlaResposta());
    }

    public function test_unclassified_portal_ticket_is_visible_to_all_staff(): void
    {
        $id = $this->abrirPeloPortal(Customer::factory()->create());

        $this->getJson('/api/dashboard/incidentes', $this->authHeader($this->staffToken(['tickets.view'])))
            ->assertOk()
            ->assertJsonPath('data.0.numero', $id)
            ->assertJsonPath('data.0.prioridade', null)
            ->assertJsonPath('data.0.status_sla_resposta', Incidente::SLA_SEM_SLA);
    }

    public function test_dashboard_filters_unclassified(): void
    {
        $doPortal = $this->abrirPeloPortal(Customer::factory()->create());
        Incidente::factory()->create([
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'media'])->id,
            'prioridade' => 'media',
        ]);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes?sem_classificacao=1', $this->authHeader($token))
            ->assertOk();
        $this->assertSame([$doPortal], array_column($response->json('data'), 'numero'));

        $this->getJson('/api/dashboard/incidentes?sem_classificacao=1&sort_by=prioridade', $this->authHeader($token))
            ->assertOk();
    }
}
