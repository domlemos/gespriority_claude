<?php

namespace Tests\Feature\Auth;

use App\Mail\ConviteUsuarioMail;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ConviteUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ConviteTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(string $permissionSlug): string
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

    public function test_admin_can_send_an_invite_to_a_user(): void
    {
        Notification::fake();
        $token = $this->staffToken('users.manage');
        $target = User::factory()->create();

        $response = $this->postJson("/api/users/{$target->id}/convite", [], $this->authHeader($token));

        $response->assertOk()->assertJsonStructure(['message']);
        Notification::assertSentTo($target, ConviteUsuario::class);
    }

    public function test_sending_invite_to_a_user_requires_users_manage_permission(): void
    {
        Notification::fake();
        $token = $this->staffToken('tickets.view');
        $target = User::factory()->create();

        $this->postJson("/api/users/{$target->id}/convite", [], $this->authHeader($token))
            ->assertStatus(403);

        Notification::assertNothingSent();
    }

    public function test_sending_invite_to_a_user_requires_authentication(): void
    {
        $target = User::factory()->create();

        $this->postJson("/api/users/{$target->id}/convite")->assertStatus(401);
    }

    public function test_admin_can_send_an_invite_to_a_customer(): void
    {
        Notification::fake();
        $token = $this->staffToken('customers.manage');
        $client = Client::factory()->create();
        $target = Customer::factory()->create(['client_id' => $client->id]);

        $response = $this->postJson("/api/customers/{$target->id}/convite", [], $this->authHeader($token));

        $response->assertOk()->assertJsonStructure(['message']);
        Notification::assertSentTo($target, ConviteUsuario::class);
    }

    public function test_sending_invite_to_a_customer_requires_customers_manage_permission(): void
    {
        Notification::fake();
        $token = $this->staffToken('users.manage');
        $client = Client::factory()->create();
        $target = Customer::factory()->create(['client_id' => $client->id]);

        $this->postJson("/api/customers/{$target->id}/convite", [], $this->authHeader($token))
            ->assertStatus(403);

        Notification::assertNothingSent();
    }

    public function test_sending_invite_to_a_customer_requires_authentication(): void
    {
        $client = Client::factory()->create();
        $target = Customer::factory()->create(['client_id' => $client->id]);

        $this->postJson("/api/customers/{$target->id}/convite")->assertStatus(401);
    }

    public function test_sending_invites_to_users_is_rate_limited_after_five_per_minute(): void
    {
        Notification::fake();
        $token = $this->staffToken('users.manage');
        $targets = User::factory()->count(6)->create();

        foreach ($targets->take(5) as $target) {
            $this->postJson("/api/users/{$target->id}/convite", [], $this->authHeader($token))
                ->assertOk();
        }

        $this->postJson("/api/users/{$targets->last()->id}/convite", [], $this->authHeader($token))
            ->assertStatus(429);
    }

    public function test_sending_invites_to_customers_is_rate_limited_after_five_per_minute(): void
    {
        Notification::fake();
        $token = $this->staffToken('customers.manage');
        $client = Client::factory()->create();
        $targets = Customer::factory()->count(6)->create(['client_id' => $client->id]);

        foreach ($targets->take(5) as $target) {
            $this->postJson("/api/customers/{$target->id}/convite", [], $this->authHeader($token))
                ->assertOk();
        }

        $this->postJson("/api/customers/{$targets->last()->id}/convite", [], $this->authHeader($token))
            ->assertStatus(429);
    }

    public function test_invite_mail_renders_recipient_name_and_url(): void
    {
        $html = (new ConviteUsuarioMail(
            'Ana Silva',
            'http://localhost:5173/reset-password?token=abc&email=ana%40example.com'
        ))->render();

        $this->assertStringContainsString('Ana Silva', $html);
        $this->assertStringContainsString('token=abc', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
