<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\UsaSessaoSpa;
use Tests\TestCase;

/**
 * Login do SPA por sessão em cookie HttpOnly (Sanctum SPA) — nenhum token é
 * entregue ao navegador. Ver BACKEND_SPECS.md §1.3 e §3.4.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase, UsaSessaoSpa;

    private function staff(string $email = 'staff@example.com', string $senha = 'secret123'): User
    {
        $role = Role::query()->where('slug', 'agente')->first() ?? Role::factory()->create(['slug' => 'agente']);
        $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['slug' => 'tickets.view'], ['name' => 'tickets.view'])->id]);

        $user = User::factory()->create(['email' => $email, 'password' => Hash::make($senha)]);
        $user->roles()->attach($role);

        return $user;
    }

    private function cliente(string $email = 'cliente@example.com', string $senha = 'secret123'): Customer
    {
        return Customer::factory()->create(['email' => $email, 'password' => Hash::make($senha)]);
    }

    public function test_csrf_token_endpoint_needs_the_spa_origin(): void
    {
        $this->spa('GET', '/api/csrf-token')->assertOk()->assertJsonStructure(['csrf_token']);
        $this->getJson('/api/csrf-token')->assertStatus(400);
    }

    public function test_staff_login_creates_an_httponly_session_and_returns_no_token(): void
    {
        $this->staff();
        $this->spa('GET', '/api/csrf-token');
        $antes = $this->idSessaoAtual();

        $resposta = $this->spa('POST', '/api/login', ['email' => 'staff@example.com', 'password' => 'secret123']);

        $resposta->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'roles', 'permissions'], 'csrf_token'])
            ->assertJsonMissingPath('token')
            ->assertJsonPath('user.roles', ['agente'])
            ->assertJsonPath('user.permissions', ['tickets.view']);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Anti-fixação: o ID da sessão muda no login.
        $this->assertNotSame($antes, $this->idSessaoAtual());

        $cookie = collect($resposta->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        $this->spa('GET', '/api/me')->assertOk()->assertJsonPath('email', 'staff@example.com');
        // A sessão do staff não autentica o perfil cliente.
        $this->spa('GET', '/api/customer/me')->assertUnauthorized();
    }

    public function test_customer_login_and_me_includes_client(): void
    {
        $customer = $this->cliente();

        $this->spa('POST', '/api/customer/login', ['email' => 'cliente@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('user.email', 'cliente@example.com')
            ->assertJsonMissingPath('user.roles')
            ->assertJsonMissingPath('user.sessoes_invalidadas_em')
            ->assertJsonMissingPath('token');

        $this->spa('GET', '/api/customer/me')
            ->assertOk()
            ->assertJsonPath('client.id', $customer->client_id)
            ->assertJsonPath('client.name', $customer->client->name);
        $this->spa('GET', '/api/me')->assertUnauthorized();
    }

    public function test_login_outside_the_spa_origin_is_refused(): void
    {
        $this->staff();

        $this->postJson('/api/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertStatus(400);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->staff();
        $desativado = $this->staff('desativado@example.com');
        $desativado->delete();
        User::factory()->create(['email' => 'ambos@example.com', 'password' => Hash::make('secret123')]);

        foreach ([
            ['/api/login', 'staff@example.com', 'wrong-password'],
            ['/api/login', 'desativado@example.com', 'secret123'],
            ['/api/customer/login', 'nao-existe@example.com', 'whatever123'],
            // Mesmo e-mail/senha de um User: o perfil cliente só resolve customers.
            ['/api/customer/login', 'ambos@example.com', 'secret123'],
        ] as [$rota, $email, $senha]) {
            $this->spa('POST', $rota, ['email' => $email, 'password' => $senha])->assertStatus(422)->assertJsonValidationErrors('email');
        }

        $this->spa('GET', '/api/me')->assertUnauthorized();
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
            ], ['X-Forwarded-For' => "203.0.113.{$i}", 'Origin' => 'http://spa.test'])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'alvo@example.com',
            'password' => 'wrong-password',
        ], ['X-Forwarded-For' => '203.0.113.6', 'Origin' => 'http://spa.test'])->assertStatus(429);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->staff();
        $this->spa('POST', '/api/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertOk();
        $logado = $this->idSessaoAtual();

        $this->spa('POST', '/api/logout')->assertOk()->assertJsonStructure(['message', 'csrf_token']);

        $this->spa('GET', '/api/me')->assertUnauthorized();
        // O ID antigo também não vale mais (sessão invalidada, não só "deslogada").
        $this->usarSessao($logado);
        $this->spa('GET', '/api/me')->assertUnauthorized();
    }

    public function test_staff_and_customer_sessions_coexist_and_log_out_independently(): void
    {
        $this->staff();
        $this->cliente();

        $this->spa('POST', '/api/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertOk();
        $this->spa('POST', '/api/customer/login', ['email' => 'cliente@example.com', 'password' => 'secret123'])->assertOk();

        $this->spa('POST', '/api/customer/logout')->assertOk();

        $this->spa('GET', '/api/customer/me')->assertUnauthorized();
        $this->spa('GET', '/api/me')->assertOk()->assertJsonPath('email', 'staff@example.com');
    }

    public function test_logout_all_invalidates_the_users_other_sessions(): void
    {
        $user = $this->staff();
        $this->spa('POST', '/api/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertOk();
        $primeira = $this->idSessaoAtual();

        $this->travel(2)->seconds();

        // Outro navegador.
        $this->usarSessao('outro-navegador');
        $this->spa('POST', '/api/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertOk();
        $this->spa('POST', '/api/logout-all')->assertOk();

        $this->assertNotNull($user->fresh()->sessoes_invalidadas_em);
        $this->spa('GET', '/api/me')->assertUnauthorized();

        $this->usarSessao($primeira);
        $this->spa('GET', '/api/me')->assertUnauthorized();
    }

    public function test_password_reset_invalidates_existing_sessions(): void
    {
        $user = $this->staff();
        $this->spa('POST', '/api/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertOk();
        $sessao = $this->idSessaoAtual();

        $this->travel(2)->seconds();
        $token = Password::broker('users')->createToken($user);
        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'staff@example.com',
            'password' => 'nova-senha-123',
            'password_confirmation' => 'nova-senha-123',
        ])->assertOk();

        $this->usarSessao($sessao);
        $this->spa('GET', '/api/me')->assertUnauthorized();
    }

    public function test_admin_changing_a_password_invalidates_that_accounts_sessions(): void
    {
        $alvo = $this->staff('alvo@example.com');
        $this->spa('POST', '/api/login', ['email' => 'alvo@example.com', 'password' => 'secret123'])->assertOk();
        $sessaoAlvo = $this->idSessaoAtual();

        $this->travel(2)->seconds();
        $adminRole = Role::factory()->create(['slug' => 'admin']);
        $adminRole->permissions()->attach(Permission::query()->firstOrCreate(['slug' => 'users.manage'], ['name' => 'users.manage']));
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);
        $tokenAdmin = $admin->createToken('spa', ['staff'], now()->addHour())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/users/{$alvo->id}", [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'grupo_solucao_id' => $alvo->grupo_solucao_id,
            'password' => 'senha-nova-123',
        ], ['Authorization' => "Bearer {$tokenAdmin}"])->assertOk();

        $this->usarSessao($sessaoAlvo);
        $this->spa('GET', '/api/me')->assertUnauthorized();
    }

    public function test_session_has_an_absolute_maximum_duration(): void
    {
        // Inatividade (session.lifetime) fora do caminho: isola o teto absoluto.
        config(['session.lifetime' => 10_000]);
        $this->staff();
        $this->spa('POST', '/api/login', ['email' => 'staff@example.com', 'password' => 'secret123'])->assertOk();

        $this->travel(719)->minutes();
        $this->spa('GET', '/api/me')->assertOk();

        $this->travel(2)->minutes();
        $this->spa('GET', '/api/me')->assertUnauthorized();
    }
}
