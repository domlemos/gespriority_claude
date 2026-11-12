<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Relatorios\Fluxos\AgregadorFluxos;
use App\Relatorios\Indicadores\ConsultaIndicadores;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * `GET /relatorios/fluxos` — mapa de fluxos: passagens de chamados entre o
 * cliente e os grupos de solução no período, com abertos, recebidos,
 * encaminhados e resolvidos por grupo. Ver BACKEND_SPECS §3.4.9 "Mapa de fluxos".
 */
class FluxosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate(self::regras() + [
            'formato' => ['sometimes', 'string', Rule::in(['json'])],
        ]);

        return $this->responder(ConsultaIndicadores::deDados($validado), $validado['formato'] ?? 'json', $request->user());
    }

    /**
     * Valida a visibilidade aqui (e não em `index()`) para nenhum chamador
     * esquecer: com `grupos`, a fonte filtra só pelos grupos pedidos.
     *
     * @throws ValidationException
     */
    public function responder(ConsultaIndicadores $c, string $formato, User $u): JsonResponse
    {
        $c->validarVisibilidade($u);

        return response()->json(AgregadorFluxos::agregar($c, $u));
    }

    /**
     * Período e grupos dos Indicadores, sem quebra nem detalhamento por integrante.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function regras(): array
    {
        return array_diff_key(ConsultaIndicadores::regras(), array_flip(['quebra', 'por_integrante']));
    }
}
