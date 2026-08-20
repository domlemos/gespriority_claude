<?php

namespace Tests\Feature\Customers;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(string $permissionSlug = 'customers.manage'): string
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

    public function test_admin_can_list_customers_with_client(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Corp']);
        Customer::factory()->count(2)->create(['client_id' => $client->id]);
        $token = $this->staffToken();

        $response = $this->getJson('/api/customers', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.client.name', 'Acme Corp');
    }

    public function test_admin_can_filter_customers_by_partial_name(): void
    {
        Customer::factory()->create(['name' => 'Ana Silva']);
        Customer::factory()->create(['name' => 'Bruno Costa']);
        $token = $this->staffToken();

        $response = $this->getJson('/api/customers?name=ana', $this->authHeader($token));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Ana Silva', $names);
        $this->assertNotContains('Bruno Costa', $names);
    }

    public function test_admin_can_filter_customers_by_partial_email(): void
    {
        Customer::factory()->create(['email' => 'ana@example.com']);
        Customer::factory()->create(['email' => 'bruno@example.com']);
        $token = $this->staffToken();

        $response = $this->getJson('/api/customers?email=ana@', $this->authHeader($token));

        $response->assertOk();
        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('ana@example.com', $emails);
        $this->assertNotContains('bruno@example.com', $emails);
    }

    public function test_filtering_customers_by_name_with_no_match_returns_empty(): void
    {
        Customer::factory()->create(['name' => 'Ana Silva']);
        $token = $this->staffToken();

        $response = $this->getJson('/api/customers?name=zzznomatch', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_can_filter_customers_by_client_id(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $customerA = Customer::factory()->create(['client_id' => $clientA->id]);
        Customer::factory()->create(['client_id' => $clientB->id]);
        $token = $this->staffToken();

        $response = $this->getJson("/api/customers?client_id={$clientA->id}", $this->authHeader($token));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($customerA->id, $ids);
    }

    public function test_filtering_customers_by_nonexistent_client_id_returns_422(): void
    {
        $token = $this->staffToken();

        $response = $this->getJson('/api/customers?client_id=999999', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('client_id');
    }

    public function test_admin_can_create_a_customer(): void
    {
        $client = Client::factory()->create();
        $token = $this->staffToken();

        $response = $this->postJson('/api/customers', [
            'name' => 'João Cliente',
            'email' => 'joao@example.com',
            'password' => 'password123',
            'client_id' => $client->id,
        ], $this->authHeader($token));

        $response->assertCreated()->assertJsonPath('data.name', 'João Cliente');
        $this->assertDatabaseHas('customers', ['email' => 'joao@example.com', 'client_id' => $client->id]);
    }

    public function test_creating_customer_requires_a_valid_client_id(): void
    {
        $token = $this->staffToken();

        $response = $this->postJson('/api/customers', [
            'name' => 'João Cliente',
            'email' => 'joao@example.com',
            'password' => 'password123',
            'client_id' => 999,
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('client_id');
    }

    public function test_admin_can_update_a_customer_without_changing_password(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id, 'name' => 'Old Name']);
        $originalPassword = $customer->password;
        $token = $this->staffToken();

        $response = $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'New Name',
            'email' => $customer->email,
            'client_id' => $client->id,
        ], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertSame($originalPassword, $customer->fresh()->password);
    }

    public function test_admin_can_delete_a_customer(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->staffToken();

        $response = $this->deleteJson("/api/customers/{$customer->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_cannot_delete_a_customer_that_has_incidentes(): void
    {
        $customer = Customer::factory()->create();
        Incidente::factory()->create(['customer_id' => $customer->id]);
        $token = $this->staffToken();

        $response = $this->deleteJson("/api/customers/{$customer->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_staff_without_customers_manage_permission_is_forbidden(): void
    {
        $token = $this->staffToken('some.other.permission');

        $this->getJson('/api/customers', $this->authHeader($token))->assertStatus(403);
    }

    public function test_guests_cannot_manage_customers(): void
    {
        $this->getJson('/api/customers')->assertStatus(401);
    }

    public function test_customer_guard_cannot_manage_customers(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/customers', $this->authHeader($token))->assertStatus(401);
    }
}
