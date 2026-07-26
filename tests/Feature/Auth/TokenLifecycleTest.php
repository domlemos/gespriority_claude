<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_rotates_the_token_atomically(): void
    {
        $user = User::factory()->create();
        $original = $user->createToken('spa', ['staff'], now()->addMinutes(120));

        $response = $this->postJson('/api/refresh', [], [
            'Authorization' => "Bearer {$original->plainTextToken}",
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'expires_at']);

        $newPlainTextToken = $response->json('token');
        $this->assertNotSame($original->plainTextToken, $newPlainTextToken);

        // Rotação atômica: continua existindo só 1 token, o antigo foi trocado pelo novo.
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $original->accessToken->id]);

        $newToken = PersonalAccessToken::sole();
        $this->assertSame(['staff'], $newToken->abilities);
        $this->assertTrue($newToken->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));

        // Sanctum's RequestGuard memoiza o usuário resolvido na própria instância
        // do guard (`RequestGuard::$user`), que fica viva entre múltiplas chamadas
        // dentro do mesmo teste — sem isso, a próxima requisição "herdaria" o
        // usuário já resolvido na chamada de refresh acima, mesmo mandando um
        // token diferente/inválido no header.
        Auth::forgetGuards();

        // Token antigo não autentica mais.
        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$original->plainTextToken}"])
            ->assertStatus(401);

        Auth::forgetGuards();

        // Token novo autentica normalmente.
        $this->getJson('/api/me', ['Authorization' => "Bearer {$newPlainTextToken}"])->assertOk();
    }

    public function test_refresh_requires_authentication(): void
    {
        $this->postJson('/api/refresh')->assertStatus(401);
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
