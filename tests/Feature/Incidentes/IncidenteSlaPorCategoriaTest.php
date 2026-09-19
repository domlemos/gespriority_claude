<?php

namespace Tests\Feature\Incidentes;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Permission;
use App\Models\PoliticaSla;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteSlaPorCategoriaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     */
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

    /**
     * @param  string[]  $permissionSlugs
     */
    private function adminToken(array $permissionSlugs = ['tickets.manage']): string
    {
        $role = Role::factory()->create(['slug' => 'admin']);

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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'titulo' => 'Impressora não liga',
            'descricao' => 'Tentei ligar e nada acontece.',
            'origem' => 'portal',
        ], $overrides);
    }

    public function test_creating_incidente_defaults_prioridade_from_the_item_prioridade_padrao(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'urgente']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id]),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.prioridade', 'urgente');
    }

    public function test_creating_incidente_without_item_default_still_requires_explicit_prioridade(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => null]);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id]),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_non_admin_can_send_a_prioridade_that_matches_the_item_default(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'alta']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id, 'prioridade' => 'alta']),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.prioridade', 'alta');
    }

    public function test_non_admin_cannot_override_the_item_default_prioridade(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'baixa']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id, 'prioridade' => 'urgente']),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_admin_can_personalize_the_prioridade_away_from_the_item_default(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'baixa']);
        $token = $this->adminToken();

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id, 'prioridade' => 'urgente']),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.prioridade', 'urgente');
    }

    public function test_updating_item_id_applies_the_new_item_default_and_recalculates_deadlines(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => null, 'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15, 'tempo_resolucao_minutos' => 240,
        ]);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id, 'item_id' => null, 'prioridade' => 'baixa',
        ]);
        $novoItem = Item::factory()->create(['prioridade_padrao' => 'urgente']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['item_id' => $novoItem->id],
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonPath('data.prioridade', 'urgente');
        $fresh = $incidente->fresh();
        $this->assertSame('urgente', $fresh->prioridade);
        $this->assertSame(
            $fresh->created_at->copy()->addMinutes(15)->timestamp,
            $fresh->prazo_resposta->timestamp
        );
    }

    public function test_admin_overriding_prioridade_directly_recalculates_deadlines(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => null, 'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15, 'tempo_resolucao_minutos' => 240,
        ]);
        $item = Item::factory()->create(['prioridade_padrao' => 'baixa']);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id, 'item_id' => $item->id, 'prioridade' => 'baixa',
        ]);
        $token = $this->adminToken();

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['prioridade' => 'urgente'],
            $this->authHeader($token)
        )->assertOk();

        $fresh = $incidente->fresh();
        $this->assertSame(
            $fresh->created_at->copy()->addMinutes(15)->timestamp,
            $fresh->prazo_resposta->timestamp
        );
    }

    public function test_recalculated_deadlines_become_null_when_the_new_prioridade_has_no_applicable_policy(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => null, 'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15, 'tempo_resolucao_minutos' => 240,
        ]);
        $token = $this->adminToken();

        $createResponse = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['customer_id' => $customer->id, 'prioridade' => 'urgente']),
            $this->authHeader($token)
        );
        $incidenteId = $createResponse->json('data.id');
        $this->assertNotNull(Incidente::find($incidenteId)->prazo_resposta);

        $this->putJson(
            "/api/incidentes/{$incidenteId}",
            ['prioridade' => 'baixa'],
            $this->authHeader($token)
        )->assertOk();

        $fresh = Incidente::find($incidenteId);
        $this->assertNull($fresh->prazo_resposta);
        $this->assertNull($fresh->prazo_resolucao);
    }

    public function test_updating_unrelated_field_does_not_recalculate_deadlines(): void
    {
        $incidente = Incidente::factory()->create();
        $prazoFixo = now()->subDays(3);
        $incidente->forceFill(['prazo_resposta' => $prazoFixo, 'prazo_resolucao' => $prazoFixo])->save();
        // Compara contra o valor já persistido (não contra $prazoFixo em
        // memória): a coluna trunca microssegundos ao salvar, então
        // comparar direto com $prazoFixo seria um falso negativo mesmo sem
        // nenhum recálculo acontecer.
        $prazoPersistido = $incidente->fresh()->prazo_resposta;
        $token = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertTrue($incidente->fresh()->prazo_resposta->equalTo($prazoPersistido));
    }
}
