<?php

namespace Tests\Feature\Lookups;

use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     */
    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $permission = Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpoints(): array
    {
        return [
            'clients' => ['/api/lookups/clients'],
            'customers' => ['/api/lookups/customers'],
            'users' => ['/api/lookups/users'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_agente_with_tickets_view_can_read_lookups(string $endpoint): void
    {
        // Mesmo conjunto de permissions do papel Agente — sem nenhum *.manage.
        $token = $this->staffToken(['tickets.view', 'tickets.manage', 'relatorios.view']);

        $this->getJson($endpoint, $this->authHeader($token))->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_relatorios_view_alone_is_enough(string $endpoint): void
    {
        $token = $this->staffToken(['relatorios.view']);

        $this->getJson($endpoint, $this->authHeader($token))->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_staff_without_tickets_or_relatorios_view_is_forbidden(string $endpoint): void
    {
        $token = $this->staffToken(['categorias.view']);

        $this->getJson($endpoint, $this->authHeader($token))->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_unauthenticated_request_is_rejected(string $endpoint): void
    {
        $this->getJson($endpoint)->assertUnauthorized();
    }

    public function test_customers_lookup_returns_only_selection_fields_with_the_client_name(): void
    {
        $client = Client::factory()->create(['name' => 'Empresa X']);
        Customer::factory()->create(['name' => 'Ana', 'email' => 'ana@x.com', 'client_id' => $client->id]);
        $token = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/lookups/customers', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.email', 'ana@x.com')
            ->assertJsonPath('data.0.client.name', 'Empresa X');
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'client_id', 'client'],
            array_keys($response->json('data.0'))
        );
    }

    public function test_users_lookup_returns_only_id_name_and_grupo(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $token = $this->staffToken(['tickets.view']);
        User::factory()->create(['name' => 'Bruno', 'grupo_solucao_id' => $grupo->id]);

        $response = $this->getJson('/api/lookups/users', $this->authHeader($token));

        $response->assertOk();
        $bruno = collect($response->json('data'))->firstWhere('name', 'Bruno');
        $this->assertSame(['id', 'name', 'grupo_solucao_id'], array_keys($bruno));
        $this->assertSame($grupo->id, $bruno['grupo_solucao_id']);
    }

    public function test_users_lookup_excludes_soft_deleted_users(): void
    {
        $token = $this->staffToken(['tickets.view']);
        User::factory()->create(['name' => 'Removido'])->delete();

        $response = $this->getJson('/api/lookups/users', $this->authHeader($token));

        $this->assertNull(collect($response->json('data'))->firstWhere('name', 'Removido'));
    }

    public function test_lookups_are_not_paginated(): void
    {
        Customer::factory()->count(250)->create();
        $token = $this->staffToken(['tickets.view']);

        $this->getJson('/api/lookups/customers', $this->authHeader($token))
            ->assertOk()
            ->assertJsonCount(250, 'data');
    }
}
