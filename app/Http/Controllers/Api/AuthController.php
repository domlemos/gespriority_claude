<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Customer;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Auth\GuardSessaoOuToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autenticação dos dois perfis (`web` = staff, `customer` = cliente do portal).
 *
 * O SPA autentica por **sessão em cookie HttpOnly** (Sanctum SPA): o login
 * grava a sessão do perfil, troca o ID da sessão (anti-fixação) e nunca
 * devolve um token ao navegador — padrão OWASP (Session Management e HTML5
 * Security Cheat Sheets). Escritas exigem o token CSRF de `GET /csrf-token`.
 * Token Bearer continua aceito pelo guard (testes/integrações), mas nenhum
 * endpoint o emite. Ver BACKEND_SPECS.md §1.3 e §3.4.
 */
class AuthController extends Controller
{
    /** Por perfil: model, guard de sessão interno e broker de redefinição de senha. */
    private const GUARDS = [
        'web' => [
            'model' => User::class,
            'sessao' => 'web_sessao',
            'broker' => 'users',
        ],
        'customer' => [
            'model' => Customer::class,
            'sessao' => 'customer_sessao',
            'broker' => 'customers',
        ],
    ];

    /**
     * Token CSRF da sessão do SPA. Em JSON (não no cookie XSRF-TOKEN): o
     * cookie seria do host da API e o JavaScript do front, em outro
     * subdomínio, não conseguiria lê-lo. O CORS restringe quem lê a resposta.
     */
    public function csrf(Request $request): JsonResponse
    {
        abort_unless($request->hasSession(), 400, 'Sessão indisponível para esta origem.');

        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function login(Request $request, string $guard = 'web')
    {
        $config = $this->guardConfig($guard);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Sem sessão = origem fora de `sanctum.stateful` (não é o SPA): não
        // há onde guardar o login, e nenhum token é emitido no lugar.
        abort_unless($request->hasSession(), 400, 'Login disponível apenas pelo aplicativo web.');

        $model = $config['model']::query()->where('email', $credentials['email'])->first();

        if (! $model || ! Hash::check($credentials['password'], $model->password)) {
            // Sem ator autenticado: guarda o e-mail tentado (e o id, se a
            // conta existe) pra dar pra filtrar tentativas contra um usuário.
            Auditoria::registrar(
                'login_falhou',
                atorNome: $credentials['email'],
                atorType: $guard === 'customer' ? 'customer' : 'user',
                atorId: $model?->getKey(),
            );

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        Auth::guard($config['sessao'])->login($model);
        // OWASP: novo ID de sessão a cada mudança de privilégio (anti-fixação).
        $request->session()->regenerate();
        $request->session()->put(GuardSessaoOuToken::chaveLoginEm($guard), now()->getTimestamp());

        Auditoria::registrar('login', ator: $model);

        return response()->json([
            'user' => $this->presentAuthenticatable($model),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(
            $this->presentAuthenticatable($request->user())
        );
    }

    public function logout(Request $request, string $guard = 'web')
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            $this->encerrarSessao($request, $guard);
        }

        Auditoria::registrar('logout', ator: $user);

        return response()->json(['message' => 'Logged out.'] + $this->csrfAtual($request));
    }

    public function logoutAll(Request $request, string $guard = 'web')
    {
        $user = $request->user();

        // Toda sessão iniciada até agora deixa de valer (GuardSessaoOuToken),
        // em qualquer navegador; e todo token é revogado.
        $user->forceFill(['sessoes_invalidadas_em' => now()])->saveQuietly();
        $user->tokens()->delete();

        if (! $user->currentAccessToken() instanceof PersonalAccessToken) {
            $this->encerrarSessao($request, $guard);
        }

        Auditoria::registrar('logout_todos', ator: $user);

        return response()->json(['message' => 'Logged out from all devices.'] + $this->csrfAtual($request));
    }

    public function forgotPassword(Request $request, string $guard = 'web')
    {
        $config = $this->guardConfig($guard);

        $request->validate(['email' => ['required', 'email']]);

        Password::broker($config['broker'])->sendResetLink(
            $request->only('email')
        );

        return response()->json(['message' => 'If that email exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request, string $guard = 'web')
    {
        $config = $this->guardConfig($guard);

        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::broker($config['broker'])->reset(
            $credentials,
            function ($model) use ($credentials) {
                // Senha nova derruba toda sessão/token existente — inclusive
                // a de quem eventualmente roubou a credencial antiga.
                $model->forceFill([
                    'password' => Hash::make($credentials['password']),
                    'remember_token' => Str::random(60),
                    'sessoes_invalidadas_em' => now(),
                ])->save();

                $model->tokens()->delete();

                Auditoria::registrar('senha_redefinida', ator: $model);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * Sai do perfil na sessão atual. Se o mesmo navegador ainda está logado
     * no outro perfil, mantém a sessão com ID e CSRF novos; senão, invalida
     * a sessão inteira (OWASP: descartar a sessão no logout).
     */
    private function encerrarSessao(Request $request, string $guard): void
    {
        if (! $request->hasSession()) {
            return;
        }

        Auth::guard(self::GUARDS[$guard]['sessao'])->logout();
        $request->session()->forget(GuardSessaoOuToken::chaveLoginEm($guard));

        $outro = $guard === 'web' ? 'customer' : 'web';

        if ($request->session()->has(GuardSessaoOuToken::chaveLoginEm($outro))) {
            $request->session()->migrate(true);
        } else {
            $request->session()->invalidate();
        }

        $request->session()->regenerateToken();
    }

    /** @return array{csrf_token?: string} */
    private function csrfAtual(Request $request): array
    {
        return $request->hasSession() ? ['csrf_token' => $request->session()->token()] : [];
    }

    private function presentAuthenticatable(User|Customer $model): UserResource|Customer
    {
        if ($model instanceof User) {
            return new UserResource($model->loadMissing('roles.permissions'));
        }

        // Portal exibe a empresa do cliente (tela de abertura de incidente).
        return $model->loadMissing('client:id,name');
    }

    private function guardConfig(string $guard): array
    {
        return self::GUARDS[$guard] ?? abort(404);
    }
}
