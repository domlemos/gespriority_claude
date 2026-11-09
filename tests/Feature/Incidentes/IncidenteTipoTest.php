<?php

namespace Tests\Feature\Incidentes;

use App\Models\Customer;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteTipoTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'media'])->id,
            'titulo' => 'Ajustar regra de firewall',
            'descricao' => 'Liberar a porta 8443 para o ambiente de homologação.',
            'origem' => 'email',
        ], $overrides);
    }

    public function test_store_without_tipo_defaults_to_incidente(): void
    {
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson('/api/incidentes', $this->validPayload(), $this->authHeader($token))
            ->assertCreated();

        $this->assertSame('incidente', $response->json('data.tipo'));
        $this->assertDatabaseHas('incidentes', ['id' => $response->json('data.id'), 'tipo' => 'incidente']);
    }

    public function test_store_accepts_solicitacao(): void
    {
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['tipo' => 'solicitacao']),
            $this->authHeader($token)
        )->assertCreated();

        $this->assertSame('solicitacao', $response->json('data.tipo'));
    }

    public function test_store_rejects_unknown_tipo(): void
    {
        $token = $this->staffToken(['tickets.manage']);

        $this->postJson('/api/incidentes', $this->validPayload(['tipo' => 'problema']), $this->authHeader($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');
    }

    public function test_changing_tipo_with_edit_all_logs_feed_entry(): void
    {
        $incidente = Incidente::factory()->create(['tipo' => 'incidente']);
        $token = $this->staffToken(['tickets.manage', 'tickets.edit_all']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['tipo' => 'solicitacao'],
            $this->authHeader($token)
        )->assertOk()->assertJsonPath('data.tipo', 'solicitacao');

        $entrada = IncidenteDescricao::query()
            ->where('incidente_id', $incidente->id)
            ->where('tipo', IncidenteDescricao::TIPO_ALTERACAO)
            ->sole();

        $this->assertStringContainsString("Campo 'Tipo' alterado de 'Incidente' para 'Solicitação'", $entrada->descricao);
    }
}
