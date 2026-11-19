<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidenteDescricaoResource;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Notifications\RespostaAoCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resposta do staff ao cliente: entra no feed como 'resposta' (visível no
 * portal) e vai por e-mail ao cliente do chamado (RespostaAoCliente).
 * Qualquer status, sem mudar o status; imutável depois de criada.
 * Ver BACKEND_SPECS.md §3.4.7.1.
 */
class IncidenteRespostaController extends Controller
{
    public function store(Request $request, Incidente $incidente): JsonResponse
    {
        $this->authorize('view', $incidente);

        $data = $request->validate([
            'descricao' => ['required', 'string', 'max:5000'],
        ]);

        // tipo e user_id nunca vêm do payload.
        $resposta = $incidente->descricoes()->create([
            'user_id' => $request->user()->id,
            'tipo' => IncidenteDescricao::TIPO_RESPOSTA,
            'descricao' => $data['descricao'],
        ]);

        $incidente->customer()->first()?->notify(new RespostaAoCliente($resposta));

        return (new IncidenteDescricaoResource($resposta->load(['user', 'customer'])))
            ->response()
            ->setStatusCode(201);
    }
}
