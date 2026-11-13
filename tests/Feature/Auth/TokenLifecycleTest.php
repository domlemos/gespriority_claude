<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // Token Bearer: ainda aceito pelo guard (testes/integrações), mas nenhum
    // endpoint o emite ao navegador — /refresh foi removido junto com a
    // emissão de token no login (BACKEND_SPECS.md §1.3).
    public function test_refresh_endpoint_no_longer_exists(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        $this->postJson('/api/refresh', [], ['Authorization' => "Bearer {$token}"])->assertNotFound();
    }

    public function test_customer_bearer_token_works_on_customer_me_only(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;

        $this->getJson('/api/customer/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('id', $customer->id);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('spa', ['staff'], now()->addMinutes(120));
        $tokenB = $user->createToken('spa', ['staff'], now()->addMinutes(120));

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $response = $this->postJson('/api/logout', [], [
            'Authorization' => "Bearer {$tokenA->plainTextToken}",
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenA->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenB->accessToken->id]);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
    }

    public function test_logout_all_revokes_every_token_for_the_user(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('spa', ['staff'], now()->addMinutes(120));
        $tokenB = $user->createToken('spa', ['staff'], now()->addMinutes(120));

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $response = $this->postJson('/api/logout-all', [], [
            'Authorization' => "Bearer {$tokenB->plainTextToken}",
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenA->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenB->accessToken->id]);
    }

    public function test_logout_all_does_not_affect_other_users_tokens(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $userToken = $user->createToken('spa', ['staff'], now()->addMinutes(120));
        $otherToken = $otherUser->createToken('spa', ['staff'], now()->addMinutes(120));

        $this->postJson('/api/logout-all', [], [
            'Authorization' => "Bearer {$userToken->plainTextToken}",
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }
}
