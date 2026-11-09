<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortalIncidenteResource;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Abertura e listagem de incidentes pelo portal do cliente (guard
 * "customer"). Separado do IncidenteController do staff de propósito: lá o
 * payload aceita classificação, encaminhamento e origem, que o cliente não
 * pode tocar. Aqui o cliente só manda título e descrição — o resto é fixado
 * no servidor, e por auditoria ele só abre chamado em nome próprio (ver
 * BACKEND_SPECS.md §3.4.10).
 */
class IncidenteController extends Controller
{
    public function index(Request $request)
    {
        // Escopo sempre pelo token, nunca por parâmetro — inclui os chamados
        // que o staff abriu em nome do cliente.
        return PortalIncidenteResource::collection(
            Incidente::query()
                ->where('customer_id', $request->user()->id)
                ->latest()
                ->paginate(15)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['required', 'string'],
        ]);

        $customer = $request->user();

        // Chamado nasce sem classificação (item/prioridade/prazos nulos): o
        // staff classifica na triagem, e o update() do staff deriva
        // prioridade e prazos nesse momento — contando desde created_at, ou
        // seja, desde a abertura.
        $incidente = DB::transaction(function () use ($data, $customer) {
            $incidente = Incidente::query()->create([
                'customer_id' => $customer->id,
                'titulo' => $data['titulo'],
                'tipo' => 'incidente',
                'origem' => 'portal',
                'status' => 'aberto',
            ]);
            // Fora do #[Fillable(...)], mesmo padrão de criado_por_id.
            $incidente->criado_por_customer_id = $customer->id;
            $incidente->save();

            $incidente->descricoes()->create([
                'customer_id' => $customer->id,
                'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
                'descricao' => $data['descricao'],
            ]);

            return $incidente;
        });

        return (new PortalIncidenteResource($incidente))
            ->response()
            ->setStatusCode(201);
    }
}
