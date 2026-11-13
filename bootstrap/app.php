<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Backend API-only: não existe rota `login` para redirecionar guests.
        // Sem isso, uma requisição sem `Accept: application/json` batendo em
        // rota protegida por `auth:...` faz o middleware tentar `route('login')`
        // e quebrar com RouteNotFoundException em vez de devolver um 401 limpo.
        $middleware->redirectGuestsTo(fn () => null);

        // Sessão por cookie HttpOnly + CSRF para requisições do SPA (origens
        // de `sanctum.stateful`); demais chamadas seguem sem estado (Bearer).
        $middleware->statefulApi();

        // Sem trustProxies de propósito: na EC2 (infra2) não há ALB — o
        // nginx recebe direto da internet e repassa os X-Forwarded-* que o
        // cliente mandar. Confiar neles deixava qualquer um trocar o
        // próprio IP a cada tentativa e zerar o throttle:login/
        // throttle:password-recovery. O HTTPS já chega via
        // `fastcgi_param HTTPS on` (docker/nginx/app.conf.template).
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
