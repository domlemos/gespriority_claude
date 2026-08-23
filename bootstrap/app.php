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

        // A app roda atrás do ALB, que termina o TLS. Sem confiar nos
        // cabeçalhos X-Forwarded-*, os links paginados voltam como
        // http:// e o rate limiting por IP (throttle:login,
        // throttle:password-recovery) vê sempre o IP privado do ALB.
        // '*' é seguro aqui porque o security group já garante que só
        // o ALB alcança as tasks (ver infra/terraform/modules/ecs).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
