<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalIncidenteTest extends TestCase
{
    use RefreshDatabase;

    private function customerToken(Customer $customer): string
    {
        return $customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function abrir(Customer $customer, array $payload = [])
    {
        return $this->postJson('/api/customer/incidentes', array_merge([
            'titulo' => 'Sistema fora do ar',
            'descricao' => 'Desde as 9h não consigo acessar.',
        ], $payload), $this->authHeader($this->customerToken($customer)));
    }

    private function assertAbertoPeloPortalEmNomeDe(Customer $customer, int $id): void
    {
        $incidente = Incidente::query()->findOrFail($id);

        $this->assertSame($customer->id, $incidente->customer_id);
        $this->assertSame($customer->id, $incidente->criado_por_customer_id);
        $this->assertSame('incidente', $incidente->tipo);
        $this->assertSame('portal', $incidente->origem);
        $this->assertSame('aberto', $incidente->status);
        $this->assertNull($incidente->item_id);
        $this->assertNull($incidente->prioridade);
        $this->assertNull($incidente->prazo_resposta);
        $this->assertNull($incidente->prazo_resolucao);
        $this->assertNull($incidente->grupo_solucao_id);
        $this->assertNull($incidente->responsavel_id);
        $this->assertNull($incidente->criado_por_id);
    }

    public function test_customer_opens_incident_in_own_name(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->abrir($customer)->assertCreated();

        $this->assertAbertoPeloPortalEmNomeDe($customer, $response->json('data.id'));
    }

    public function test_forged_fields_in_payload_are_ignored(): void
    {
        $customer = Customer::factory()->create();
        $outro = Customer::factory()->create();

        $response = $this->abrir($customer, [
            'customer_id' => $outro->id,
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'urgente'])->id,
            'prioridade' => 'urgente',
            'status' => 'resolvido',
            'tipo' => 'solicitacao',
            'origem' => 'email',
            'grupo_solucao_id' => GrupoSolucao::factory()->create()->id,
            'responsavel_id' => User::factory()->create()->id,
        ])->assertCreated();

        $this->assertAbertoPeloPortalEmNomeDe($customer, $response->json('data.id'));
    }

    public function test_opening_description_is_first_feed_entry_authored_by_customer(): void
    {
        $customer = Customer::factory()->create();

        $id = $this->abrir($customer)->json('data.id');

        $entrada = IncidenteDescricao::query()->where('incidente_id', $id)->sole();
        $this->assertSame(IncidenteDescricao::TIPO_COMENTARIO, $entrada->tipo);
        $this->assertSame($customer->id, $entrada->customer_id);
        $this->assertNull($entrada->user_id);
        $this->assertSame('Desde as 9h não consigo acessar.', $entrada->descricao);
    }

    public function test_response_does_not_expose_internal_fields(): void
    {
        $response = $this->abrir(Customer::factory()->create())->assertCreated();

        $this->assertEqualsCanonicalizing(
            ['id', 'titulo', 'status', 'created_at', 'updated_at'],
            array_keys($response->json('data'))
        );
    }

    public function test_validation(): void
    {
        $customer = Customer::factory()->create();

        $this->abrir($customer, ['titulo' => null])->assertStatus(422)->assertJsonValidationErrors('titulo');
        $this->abrir($customer, ['titulo' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors('titulo');
        $this->abrir($customer, ['descricao' => null])->assertStatus(422)->assertJsonValidationErrors('descricao');
    }

    public function test_lists_only_own_tickets(): void
    {
        $empresa = Client::factory()->create();
        $clienteA = Customer::factory()->create(['client_id' => $empresa->id]);
        $clienteB = Customer::factory()->create(['client_id' => $empresa->id]);
        $doPortal = Incidente::query()->findOrFail($this->abrir($clienteA)->json('data.id'));
        $abertoPeloStaff = Incidente::factory()->create(['customer_id' => $clienteA->id]);
        Incidente::factory()->create(['customer_id' => $clienteB->id]);

        $response = $this->getJson('/api/customer/incidentes', $this->authHeader($this->customerToken($clienteA)))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$doPortal->id, $abertoPeloStaff->id],
            array_column($response->json('data'), 'id')
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'titulo', 'status', 'created_at', 'updated_at'],
            array_keys($response->json('data.0'))
        );
    }

    public function test_staff_token_cannot_use_portal_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        $this->getJson('/api/customer/incidentes', $this->authHeader($token))->assertUnauthorized();
        $this->postJson('/api/customer/incidentes', ['titulo' => 'x', 'descricao' => 'y'], $this->authHeader($token))
            ->assertUnauthorized();
    }

    public function test_customer_token_still_cannot_use_staff_incident_routes(): void
    {
        $token = $this->customerToken(Customer::factory()->create());

        $this->getJson('/api/incidentes', $this->authHeader($token))->assertUnauthorized();
    }
}
