<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Simula o SPA nos testes: origem em `sanctum.stateful` (o que liga sessão +
 * CSRF via statefulApi) e o cookie de sessão levado de uma requisição para a
 * próxima, como o navegador faria. CSRF não é validado em testes (Laravel
 * pula ValidateCsrfToken com runningUnitTests()).
 */
trait UsaSessaoSpa
{
    protected function setUpUsaSessaoSpa(): void
    {
        config(['sanctum.stateful' => ['spa.test']]);
    }

    /** Requisição do SPA com o cookie da sessão atual. */
    protected function spa(string $metodo, string $uri, array $dados = []): TestResponse
    {
        // Guards guardam o usuário resolvido; o app de teste é reaproveitado
        // entre requisições, então zera para cada uma resolver do zero.
        $this->app['auth']->forgetGuards();

        $resposta = $this->json($metodo, $uri, $dados, ['Origin' => 'http://spa.test']);
        $this->usarSessao($this->app['session.store']->getId());

        return $resposta;
    }

    /** Troca o cookie de sessão (simula outro navegador / outra sessão). */
    protected function usarSessao(?string $id): void
    {
        $this->withCookie(config('session.cookie'), (string) $id);
    }

    protected function idSessaoAtual(): string
    {
        return $this->app['session.store']->getId();
    }
}
