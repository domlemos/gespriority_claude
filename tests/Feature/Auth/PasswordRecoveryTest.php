<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_is_rate_limited_after_five_attempts_per_email_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'throttle@example.com'])
                ->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'throttle@example.com'])
            ->assertStatus(429);
    }

    public function test_forgot_password_throttle_bucket_is_scoped_per_email(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'primeiro@example.com'])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'primeiro@example.com'])->assertStatus(429);

        // E-mail diferente, mesmo IP de teste: bucket próprio, ainda não bloqueado.
        $this->postJson('/api/forgot-password', ['email' => 'segundo@example.com'])->assertOk();
    }

    public function test_reset_password_is_rate_limited_after_five_attempts(): void
    {
        $payload = [
            'token' => 'token-invalido',
            'email' => 'throttle@example.com',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ];

        for ($i = 0; $i < 5; $i++) {
            // Token inválido -> 422, mas ainda dentro do limite de tentativas.
            $this->postJson('/api/reset-password', $payload)->assertStatus(422);
        }

        $this->postJson('/api/reset-password', $payload)->assertStatus(429);
    }

    public function test_reset_password_changes_password_and_revokes_all_existing_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-antiga')]);
        $user->createToken('spa', ['staff'], now()->addMinutes(120));
        $user->createToken('spa', ['staff'], now()->addMinutes(120));

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $token = Password::broker('users')->createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('senha-nova-123', $user->fresh()->password));
    }

    public function test_customer_reset_password_uses_the_customer_broker_and_revokes_tokens(): void
    {
        $customer = Customer::factory()->create(['password' => Hash::make('senha-antiga')]);
        $customer->createToken('spa', ['customer'], now()->addMinutes(240));

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = Password::broker('customers')->createToken($customer);

        $response = $this->postJson('/api/customer/reset-password', [
            'token' => $token,
            'email' => $customer->email,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('senha-nova-123', $customer->fresh()->password));
    }

    public function test_reset_password_fails_with_invalid_token_and_keeps_old_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-antiga')]);

        $response = $this->postJson('/api/reset-password', [
            'token' => 'token-invalido',
            'email' => $user->email,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('senha-antiga', $user->fresh()->password));
    }
}
