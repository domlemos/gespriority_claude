<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Documentação OpenAPI gerada pelo Scramble (`/docs/api`, `/docs/api.json`).
 * Fora do ambiente `local` o acesso depende do gate `viewApiDocs`, que não
 * é definido — ou seja, em produção a doc fica fechada (403).
 */
class ApiDocsTest extends TestCase
{
    public function test_docs_bloqueadas_fora_do_ambiente_local(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    // Processo separado: gerar o spec inteiro carrega ~40MB de análise
    // estática do Scramble que ficariam retidos e estourariam o
    // memory_limit (128MB) no resto da suíte.
    #[RunInSeparateProcess]
    public function test_spec_documenta_as_rotas_da_api(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => true);

        $spec = $this->getJson('/docs/api.json')
            ->assertOk()
            ->json();

        $this->assertSame('ITSM API', $spec['info']['title']);
        $this->assertArrayHasKey('/login', $spec['paths']);
        $this->assertArrayHasKey('/incidentes', $spec['paths']);
        $this->assertArrayHasKey('/customer/incidentes', $spec['paths']);

        // Endpoints que dependem das regras de ConsultaRelatorio/ConsultaIndicadores
        // (classe anônima) — garantem que o Scramble não falhou ao gerá-los.
        foreach (['/relatorios/chamados', '/relatorios/indicadores'] as $path) {
            $descricao = $spec['paths'][$path]['get']['description'] ?? '';
            $this->assertStringNotContainsString('Cannot generate', $descricao, $path);
        }
    }
}
