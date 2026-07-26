<?php

namespace App\Providers;

use App\Models\Customer;
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
        // (cliente) com o token da querystring. O prefixo da rota do front
        // precisa bater com o guard do $notifiable — um Customer usa o broker
        // "customers" (tabela customer_password_reset_tokens), então o token só
        // é válido em /api/customer/reset-password; mandar esse link para a
        // página de staff (/reset-password) faria o front chamar o broker
        // errado e o reset falhar com "token inválido".
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $path = $notifiable instanceof Customer ? '/portal/reset-password' : '/reset-password';

            return sprintf(
                '%s%s?token=%s&email=%s',
                config('app.frontend_url'),
                $path,
                $token,
                urlencode($notifiable->getEmailForPasswordReset())
            );
        });
    }
}
