<?php

namespace Tests\Feature\I18n;

use App\Http\Middleware\SetLocaleFromRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocaleMiddlewareTest extends TestCase
{
    private const MENSAGEM_PT = 'O campo e-mail é obrigatório.';

    private const MENSAGEM_EN = 'The email field is required.';

    private function erroDeEmail(array $headers = []): string
    {
        // O Symfony injeta `Accept-Language: en-us,en;q=0.5` em toda Request
        // de teste; um valor vazio explícito simula "sem header".
        $headers += ['Accept-Language' => ''];

        return $this->postJson('/api/forgot-password', [], $headers)
            ->assertStatus(422)
            ->json('errors.email.0');
    }

    public function test_sem_header_responde_em_portugues(): void
    {
        $this->assertSame(self::MENSAGEM_PT, $this->erroDeEmail());
    }

    public function test_header_en_responde_em_ingles(): void
    {
        $this->assertSame(
            self::MENSAGEM_EN,
            $this->erroDeEmail(['Accept-Language' => 'en-US,en;q=0.9']),
        );
    }

    public function test_header_pt_br_responde_em_portugues(): void
    {
        $this->assertSame(self::MENSAGEM_PT, $this->erroDeEmail(['Accept-Language' => 'pt-BR']));
    }

    public function test_idioma_desconhecido_cai_no_portugues(): void
    {
        $this->assertSame(self::MENSAGEM_PT, $this->erroDeEmail(['Accept-Language' => 'fr-FR']));
    }

    public function test_locale_nao_vaza_para_a_proxima_requisicao(): void
    {
        $this->assertSame(self::MENSAGEM_EN, $this->erroDeEmail(['Accept-Language' => 'en']));
        $this->assertSame(self::MENSAGEM_PT, $this->erroDeEmail());
    }

    #[DataProvider('cabecalhos')]
    public function test_resolver(?string $header, string $esperado): void
    {
        $this->assertSame($esperado, SetLocaleFromRequest::resolver($header));
    }

    public static function cabecalhos(): array
    {
        return [
            'en' => ['en', 'en'],
            'EN' => ['EN', 'en'],
            'en-US com q' => ['en-US,en;q=0.9', 'en'],
            'pt' => ['pt', 'pt_BR'],
            'pt-BR' => ['pt-BR', 'pt_BR'],
            'fr' => ['fr', 'pt_BR'],
            'vazio' => ['', 'pt_BR'],
            'null' => [null, 'pt_BR'],
        ];
    }
}
