<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Relatorios\Indicadores\AgregadorIndicadores;
use App\Relatorios\Indicadores\ConsultaIndicadores;
use App\Relatorios\Indicadores\FonteIndicadores;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET /relatorios/indicadores` — abertos, resolvidos, fechados e cancelados
 * por grupo de solução (ou por integrante de um grupo), contados no grupo do
 * autor no momento do evento. Ver BACKEND_SPECS §3.4.9 "Indicadores por grupo".
 */
class IndicadoresController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // `formato` na mesma passada dos demais campos: um único 422 com todos os erros.
        $validado = $request->validate(ConsultaIndicadores::regras() + [
            'formato' => ['sometimes', 'string', Rule::in(['json'])],
        ]);

        $c = ConsultaIndicadores::deDados($validado);
        $c->validarVisibilidade($request->user());

        return $this->responder($c, $validado['formato'] ?? 'json', $request->user());
    }

    /** Público para o relatório salvo executar a mesma consulta. */
    public function responder(ConsultaIndicadores $c, string $formato, User $u): JsonResponse
    {
        return response()->json(AgregadorIndicadores::agregar($c, FonteIndicadores::linhas($c, $u), $u));
    }
}
