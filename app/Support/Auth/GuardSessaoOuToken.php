<?php

namespace App\Support\Auth;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Laravel\Sanctum\Guard as SanctumGuard;

/**
 * Guard dos dois perfis (`web` = staff, `customer` = cliente do portal).
 *
 * 1. **Sessão** (o caminho do SPA): o guard de sessão próprio do perfil
 *    (`web_sessao`/`customer_sessao`), cujo cookie é HttpOnly — o navegador
 *    nunca vê a credencial (OWASP Session Management / HTML5 Security Cheat
 *    Sheets). Aqui também ficam as regras que o SessionGuard não tem:
 *    duração máxima absoluta e invalidação global ("sair de todos os
 *    dispositivos", redefinição de senha) via `sessoes_invalidadas_em`.
 * 2. **Token Bearer do Sanctum**, restrito ao provider do perfil — mantido
 *    para testes e integrações; nenhum endpoint o entrega ao navegador.
 *
 * `sanctum.guard` continua `[]`: o Guard do Sanctum só olha o token, nunca
 * outra sessão (o que deixaria a sessão de um perfil autenticar o outro).
 * Ver BACKEND_SPECS.md §1.3 e §3.3.
 */
final class GuardSessaoOuToken
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly string $nome,
        private readonly array $config,
    ) {}

    public function __invoke(Request $request): User|Customer|null
    {
        return $this->porSessao($request) ?? $this->porToken($request);
    }

    /** Chave de sessão com o instante do login neste perfil (gravada no AuthController). */
    public static function chaveLoginEm(string $guard): string
    {
        return "auth.{$guard}.login_em";
    }

    private function porSessao(Request $request): User|Customer|null
    {
        if (! $request->hasSession()) {
            return null;
        }

        $sessao = $this->auth->guard($this->config['sessao']);
        $user = $sessao->user();

        if (! $user instanceof User && ! $user instanceof Customer) {
            return null;
        }

        $loginEm = $request->session()->get(self::chaveLoginEm($this->nome));
        $maxima = (int) ($this->config['duracao_maxima_minutos'] ?? 720);
        $invalidadasEm = $user->sessoes_invalidadas_em?->getTimestamp();

        $expirou = $loginEm === null
            || now()->getTimestamp() - $loginEm > $maxima * 60
            || ($invalidadasEm !== null && $loginEm <= $invalidadasEm);

        if ($expirou) {
            $sessao->logout();
            $request->session()->forget(self::chaveLoginEm($this->nome));

            return null;
        }

        return $user;
    }

    private function porToken(Request $request): User|Customer|null
    {
        return (new SanctumGuard($this->auth, config('sanctum.expiration'), $this->config['provider'], config('sanctum.last_used_at', true)))($request);
    }
}
