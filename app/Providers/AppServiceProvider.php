<?php

namespace App\Providers;

use App\Support\PasswordUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 5 tentativas/minuto por IP + e-mail, para /api/login e /api/refresh.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower($request->input('email', $request->ip())).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // 5 tentativas/minuto por IP + e-mail, para forgot-password/reset-password
        // (staff e customer) — evita força bruta no token de reset e abuso do
        // endpoint de envio de e-mail (spam de reset para terceiros, enumeração
        // de contas por IP tentando vários e-mails em sequência).
        RateLimiter::for('password-recovery', function (Request $request) {
            $key = Str::lower($request->input('email', $request->ip())).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Backend é API-only: não existe rota web `password.reset` para o
        // link padrão do Laravel apontar. Redireciona para o SPA Vue, que
        // faz o POST em /api/reset-password (staff) ou /api/customer/reset-password
        // (cliente) com o token da querystring. PasswordUrl::build() decide o
        // path certo por tipo de $notifiable (mesma lógica reaproveitada pelo
        // convite de novo usuário, ver App\Notifications\ConviteUsuario).
        ResetPassword::createUrlUsing(
            fn ($notifiable, string $token) => PasswordUrl::build($notifiable, $token)
        );
    }
}
