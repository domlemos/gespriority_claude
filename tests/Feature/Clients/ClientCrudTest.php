<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCrudTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(string $permissionSlug = 'clients.manage'): string
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['slug' => $permissionSlug]);
        $role->permissions()->attach($permission);

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_admin_can_list_clients(): void
    {
        Client::factory()->count(2)->create();
        $token = $this->staffToken();

        $response = $this->getJson('/api/clients', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_request_a_larger_page_size(): void
    {
        Client::factory()->count(20)->create();
        $token = $this->staffToken();

        $response = $this->getJson('/api/clients?per_page=20', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(20, 'data');
    }

    public function test_admin_can_filter_clients_by_partial_name(): void
    {
        Client::factory()->create(['name' => 'Acme Corp']);
        Client::factory()->create(['name' => 'Globex Inc']);
        $token = $this->staffToken();

        $response = $this->getJson('/api/clients?name=acme', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Acme Corp');
    }

    public function test_filtering_clients_by_name_with_no_match_returns_empty(): void
    {
        Client::factory()->create(['name' => 'Acme Corp']);
        $token = $this->staffToken();

        $response = $this->getJson('/api/clients?name=nonexistent', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_create_a_client(): void
    {
        $token = $this->staffToken();

        $response = $this->postJson('/api/clients', ['name' => 'Acme Corp'], $this->authHeader($token));

        $response->assertCreated()->assertJsonPath('data.name', 'Acme Corp');
        $this->assertDatabaseHas('clients', ['name' => 'Acme Corp']);
    }

    public function test_creating_client_requires_name(): void
    {
        $token = $this->staffToken();

        $response = $this->postJson('/api/clients', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_admin_can_view_a_single_client(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Corp']);
        $token = $this->staffToken();

        $response = $this->getJson("/api/clients/{$client->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.name', 'Acme Corp');
    }

    public function test_admin_can_update_a_client(): void
    {
        $client = Client::factory()->create(['name' => 'Old Name']);
        $token = $this->staffToken();

        $response = $this->putJson("/api/clients/{$client->id}", ['name' => 'New Name'], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'New Name']);
    }

    public function test_admin_can_delete_a_client_without_customers(): void
    {
        $client = Client::factory()->create();
        $token = $this->staffToken();

        $response = $this->deleteJson("/api/clients/{$client->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_cannot_delete_a_client_that_has_customers(): void
    {
        $client = Client::factory()->create();
        Customer::factory()->create(['client_id' => $client->id]);
        $token = $this->staffToken();

        $response = $this->deleteJson("/api/clients/{$client->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_staff_without_clients_manage_permission_is_forbidden(): void
    {
        $token = $this->staffToken('some.other.permission');

        $this->getJson('/api/clients', $this->authHeader($token))->assertStatus(403);
    }

    public function test_guests_cannot_manage_clients(): void
    {
        $this->getJson('/api/clients')->assertStatus(401);
    }

    public function test_customer_guard_cannot_manage_clients(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/clients', $this->authHeader($token))->assertStatus(401);
    }
}
