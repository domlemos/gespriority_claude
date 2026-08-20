<?php

namespace Tests\Feature\PoliticasSla;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\PoliticaSla;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoliticaSlaCrudTest extends TestCase
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

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nome' => 'Padrão Urgente',
            'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15,
            'tempo_resolucao_minutos' => 240,
            'apenas_horas_uteis' => true,
            'ativo' => true,
        ], $overrides);
    }

    public function test_staff_with_view_permission_can_list_policies(): void
    {
        PoliticaSla::factory()->count(2)->create();
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson('/api/politicas-sla', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_with_view_permission_can_view_a_single_policy(): void
    {
        $policy = PoliticaSla::factory()->create(['nome' => 'Padrão Alta']);
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson("/api/politicas-sla/{$policy->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'Padrão Alta');
    }

    public function test_staff_without_view_permission_cannot_list_policies(): void
    {
        $token = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/politicas-sla', $this->authHeader($token))->assertStatus(403);
    }

    public function test_admin_can_create_a_policy(): void
    {
        $token = $this->staffToken(['slas.manage']);

        $response = $this->postJson('/api/politicas-sla', $this->validPayload(), $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.nome', 'Padrão Urgente')
            ->assertJsonPath('data.prioridade', 'urgente')
            ->assertJsonPath('data.tempo_resposta_minutos', 15)
            ->assertJsonPath('data.tempo_resolucao_minutos', 240)
            ->assertJsonPath('data.client_id', null);

        $this->assertDatabaseHas('politicas_sla', ['nome' => 'Padrão Urgente', 'client_id' => null]);
    }

    public function test_creating_a_policy_without_optional_flags_reflects_database_defaults_in_the_response(): void
    {
        $token = $this->staffToken(['slas.manage']);

        $payload = $this->validPayload();
        unset($payload['apenas_horas_uteis'], $payload['ativo']);

        $response = $this->postJson('/api/politicas-sla', $payload, $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.ativo', true)
            ->assertJsonPath('data.apenas_horas_uteis', false);
    }

    public function test_view_only_permission_cannot_create_a_policy(): void
    {
        $token = $this->staffToken(['slas.view']);

        $this->postJson('/api/politicas-sla', $this->validPayload(), $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_creating_policy_requires_required_fields(): void
    {
        $token = $this->staffToken(['slas.manage']);

        $response = $this->postJson('/api/politicas-sla', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors([
            'nome', 'prioridade', 'tempo_resposta_minutos', 'tempo_resolucao_minutos',
        ]);
    }

    public function test_creating_policy_rejects_invalid_prioridade(): void
    {
        $token = $this->staffToken(['slas.manage']);

        $response = $this->postJson('/api/politicas-sla', $this->validPayload(['prioridade' => 'gigante']), $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_creating_policy_rejects_resolucao_shorter_than_resposta(): void
    {
        $token = $this->staffToken(['slas.manage']);

        $response = $this->postJson('/api/politicas-sla', $this->validPayload([
            'tempo_resposta_minutos' => 100,
            'tempo_resolucao_minutos' => 50,
        ]), $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('tempo_resolucao_minutos');
    }

    public function test_cannot_create_two_active_global_policies_for_the_same_priority(): void
    {
        PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => null]);
        $token = $this->staffToken(['slas.manage']);

        $response = $this->postJson('/api/politicas-sla', $this->validPayload(), $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_cannot_create_two_active_policies_for_the_same_client_and_priority(): void
    {
        $client = Client::factory()->create();
        PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => $client->id]);
        $token = $this->staffToken(['slas.manage']);

        $response = $this->postJson(
            '/api/politicas-sla',
            $this->validPayload(['client_id' => $client->id]),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_can_create_same_priority_policy_for_different_clients(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => $clientA->id]);
        $token = $this->staffToken(['slas.manage']);

        $response = $this->postJson(
            '/api/politicas-sla',
            $this->validPayload(['client_id' => $clientB->id]),
            $this->authHeader($token)
        );

        $response->assertCreated();
    }

    public function test_admin_can_update_a_policy(): void
    {
        $policy = PoliticaSla::factory()->create(['nome' => 'Old Name']);
        $token = $this->staffToken(['slas.manage']);

        $response = $this->putJson(
            "/api/politicas-sla/{$policy->id}",
            $this->validPayload(['nome' => 'New Name']),
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonPath('data.nome', 'New Name');
        $this->assertDatabaseHas('politicas_sla', ['id' => $policy->id, 'nome' => 'New Name']);
    }

    public function test_updating_a_policy_does_not_collide_with_itself_on_uniqueness(): void
    {
        $policy = PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => null]);
        $token = $this->staffToken(['slas.manage']);

        $response = $this->putJson(
            "/api/politicas-sla/{$policy->id}",
            $this->validPayload(['prioridade' => 'urgente']),
            $this->authHeader($token)
        );

        $response->assertOk();
    }

    public function test_view_only_permission_cannot_update_a_policy(): void
    {
        $policy = PoliticaSla::factory()->create();
        $token = $this->staffToken(['slas.view']);

        $this->putJson("/api/politicas-sla/{$policy->id}", $this->validPayload(), $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_admin_can_delete_a_policy(): void
    {
        $policy = PoliticaSla::factory()->create();
        $token = $this->staffToken(['slas.manage']);

        $response = $this->deleteJson("/api/politicas-sla/{$policy->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('politicas_sla', ['id' => $policy->id]);
    }

    public function test_view_only_permission_cannot_delete_a_policy(): void
    {
        $policy = PoliticaSla::factory()->create();
        $token = $this->staffToken(['slas.view']);

        $this->deleteJson("/api/politicas-sla/{$policy->id}", [], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_policies(): void
    {
        $this->getJson('/api/politicas-sla')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_policies(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/politicas-sla', $this->authHeader($token))->assertStatus(401);
    }

    public function test_client_resolves_its_own_policy_over_the_global_default(): void
    {
        $client = Client::factory()->create();
        PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => null, 'tempo_resposta_minutos' => 15]);
        $ownPolicy = PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => $client->id, 'tempo_resposta_minutos' => 5]);

        $resolved = $client->resolvedSlaFor('urgente');

        $this->assertNotNull($resolved);
        $this->assertSame($ownPolicy->id, $resolved->id);
    }

    public function test_client_falls_back_to_the_global_default_when_it_has_no_override(): void
    {
        $client = Client::factory()->create();
        $globalDefault = PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => null]);

        $resolved = $client->resolvedSlaFor('urgente');

        $this->assertNotNull($resolved);
        $this->assertSame($globalDefault->id, $resolved->id);
    }

    public function test_client_resolution_ignores_inactive_policies(): void
    {
        $client = Client::factory()->create();
        PoliticaSla::factory()->create(['prioridade' => 'urgente', 'client_id' => null, 'ativo' => false]);

        $resolved = $client->resolvedSlaFor('urgente');

        $this->assertNull($resolved);
    }

    public function test_can_filter_policies_by_partial_nome(): void
    {
        PoliticaSla::factory()->create(['nome' => 'Padrão Urgente Financeiro']);
        PoliticaSla::factory()->create(['nome' => 'Outra política']);
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson('/api/politicas-sla?nome=urgente', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Padrão Urgente Financeiro');
    }

    public function test_can_filter_policies_by_exact_prioridade(): void
    {
        PoliticaSla::factory()->create(['prioridade' => 'alta', 'client_id' => Client::factory()->create()->id]);
        PoliticaSla::factory()->create(['prioridade' => 'baixa', 'client_id' => Client::factory()->create()->id]);
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson('/api/politicas-sla?prioridade=alta', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.prioridade', 'alta');
    }

    public function test_filtering_policies_rejects_invalid_prioridade(): void
    {
        $token = $this->staffToken(['slas.view']);

        $this->getJson('/api/politicas-sla?prioridade=gigante', $this->authHeader($token))
            ->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_can_filter_policies_by_ativo_true(): void
    {
        PoliticaSla::factory()->create(['prioridade' => 'alta', 'ativo' => true, 'client_id' => Client::factory()->create()->id]);
        PoliticaSla::factory()->create(['prioridade' => 'baixa', 'ativo' => false, 'client_id' => Client::factory()->create()->id]);
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson('/api/politicas-sla?ativo=1', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ativo', true);
    }

    public function test_can_filter_policies_by_ativo_false(): void
    {
        // Este é o teste da pegadinha de boolean: `ativo=false` precisa
        // realmente filtrar (via array_key_exists), não ser silenciosamente
        // ignorado como "sem filtro" (`false ?? null` avaliaria `false`).
        PoliticaSla::factory()->create(['prioridade' => 'alta', 'ativo' => true, 'client_id' => Client::factory()->create()->id]);
        PoliticaSla::factory()->create(['prioridade' => 'baixa', 'ativo' => false, 'client_id' => Client::factory()->create()->id]);
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson('/api/politicas-sla?ativo=0', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ativo', false);
    }

    public function test_can_filter_policies_by_specific_client_id(): void
    {
        $client = Client::factory()->create();
        PoliticaSla::factory()->create(['prioridade' => 'alta', 'client_id' => $client->id]);
        PoliticaSla::factory()->create(['prioridade' => 'baixa', 'client_id' => Client::factory()->create()->id]);
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson("/api/politicas-sla?client_id={$client->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_id', $client->id);
    }

    public function test_can_filter_policies_by_global_client_sentinel(): void
    {
        PoliticaSla::factory()->create(['prioridade' => 'alta', 'client_id' => null]);
        PoliticaSla::factory()->create(['prioridade' => 'baixa', 'client_id' => Client::factory()->create()->id]);
        $token = $this->staffToken(['slas.view']);

        $response = $this->getJson('/api/politicas-sla?client_id=global', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_id', null);
    }

    public function test_filtering_policies_rejects_invalid_client_id(): void
    {
        $token = $this->staffToken(['slas.view']);

        $this->getJson('/api/politicas-sla?client_id=999999', $this->authHeader($token))
            ->assertStatus(422)->assertJsonValidationErrors('client_id');
    }
}
