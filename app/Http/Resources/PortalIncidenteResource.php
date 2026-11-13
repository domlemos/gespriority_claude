<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visão do cliente sobre o próprio chamado — de propósito sem grupo,
 * responsável, prioridade, SLA ou classificação (dados internos do
 * atendimento).
 */
class PortalIncidenteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Só no detalhe (GET /customer/incidentes/{id}) — já filtrado pelo
            // controller, nunca o feed inteiro do staff.
            'descricoes' => PortalDescricaoResource::collection($this->whenLoaded('descricoesPortal')),
            // Também só no detalhe (evita uma consulta por linha na listagem):
            // o portal mostra o botão "Reabrir" enquanto `pode_reabrir`.
            $this->mergeWhen($this->relationLoaded('descricoesPortal'), fn () => $this->dadosReabertura()),
        ];
    }

    /** @return array{reabrir_ate: ?string, pode_reabrir: bool} */
    private function dadosReabertura(): array
    {
        $ate = $this->resource->reaberturaPortalPermitidaAte();

        return [
            'reabrir_ate' => $ate?->toJSON(),
            'pode_reabrir' => $ate !== null && $ate->isFuture(),
        ];
    }
}
