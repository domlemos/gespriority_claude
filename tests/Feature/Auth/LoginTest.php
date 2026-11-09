<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_login_with_valid_credentials(): void
    {
        $role = Role::factory()->create(['slug' => 'agente']);
        $permission = Permission::factory()->create(['slug' => 'tickets.view']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => Hash::make('secret123'),
        ]);
        $user->roles()->attach($role);

        $response = $this->postJson('/api/login', [
            'email' => 'staff@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'roles', 'permissions']])
            ->assertJsonPath('user.email', 'staff@example.com')
            ->assertJsonPath('user.roles', ['agente'])
            ->assertJsonPath('user.permissions', ['tickets.view']);

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = PersonalAccessToken::sole();
        $this->assertSame(User::class, $token->tokenable_type);
        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertSame(['staff'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));
    }

    public function test_customer_can_login_with_valid_credentials(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/customer/login', [
            'email' => 'cliente@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.email', 'cliente@example.com')
            ->assertJsonMissingPath('user.roles')
            ->assertJsonMissingPath('user.permissions');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = PersonalAccessToken::sole();
        $this->assertSame(Customer::class, $token->tokenable_type);
        $this->assertSame($customer->id, $token->tokenable_id);
        $this->assertSame(['customer'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(239), now()->addMinutes(241)));
    }

    public function test_staff_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'staff@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'staff@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivated_staff_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => Hash::make('secret123'),
        ]);
        $user->delete();

        $response = $this->postJson('/api/login', [
            'email' => 'staff@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_customer_login_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/api/customer/login', [
            'email' => 'nao-existe@example.com',
            'password' => 'whatever123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_staff_credentials_do_not_authenticate_against_customer_guard(): void
    {
        // Mesma senha/e-mail, mas cadastrado como User (staff), não Customer —
        // o guard "customer" só resolve contra a tabela customers.
        User::factory()->create([
            'email' => 'ambos@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/customer/login', [
            'email' => 'ambos@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rate_limit_cannot_be_bypassed_by_spoofing_x_forwarded_for(): void
    {
        // O nginx da EC2 recebe direto da internet e repassa o
        // X-Forwarded-For que o cliente mandar — se a app confiar nele,
        // trocar o cabeçalho a cada tentativa zera o throttle:login.
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'alvo@example.com',
                'password' => 'wrong-password',
            ], ['X-Forwarded-For' => "203.0.113.{$i}"])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'alvo@example.com',
            'password' => 'wrong-password',
        ], ['X-Forwarded-For' => '203.0.113.6'])->assertStatus(429);
    }

    public function test_customer_me_includes_client(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;

        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('client.id', $customer->client_id)
            ->assertJsonPath('client.name', $customer->client->name);
    }
}
