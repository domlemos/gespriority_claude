<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromRequest
{
    // Fixo de propósito: App::setLocale() também reescreve config('app.locale'),
    // então ler o padrão da config faria o idioma de uma requisição (en)
    // virar o "padrão" da seguinte quando o container é reaproveitado.
    private const PADRAO = 'pt_BR';

    public function handle(Request $request, Closure $next): Response
    {
        // Sempre define o idioma (inclusive o padrão): no Octane o processo
        // do worker é reaproveitado e o locale da requisição anterior vazaria.
        App::setLocale(self::resolver($request->header('Accept-Language')));

        return $next($request);
    }

    /**
     * Resolve o idioma pelo primeiro item do Accept-Language.
     * en* => en, pt* => pt_BR, qualquer outro/ausente => pt_BR.
     */
    public static function resolver(?string $header): string
    {
        $primeiro = strtolower(trim(explode(',', (string) $header)[0]));
        $primeiro = trim(explode(';', $primeiro)[0]);

        return match (true) {
            str_starts_with($primeiro, 'en') => 'en',
            str_starts_with($primeiro, 'pt') => 'pt_BR',
            default => self::PADRAO,
        };
    }
}
