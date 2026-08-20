<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidenteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'prioridade' => $this->prioridade,
            'origem' => $this->origem,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id,
                'nome' => $this->item->nome,
            ] : null),
            'grupo_solucao_id' => $this->grupo_solucao_id,
            'grupo_solucao' => $this->whenLoaded('grupoSolucao', fn () => $this->grupoSolucao ? [
                'id' => $this->grupoSolucao->id,
                'nome' => $this->grupoSolucao->nome,
            ] : null),
            'responsavel_id' => $this->responsavel_id,
            'responsavel' => $this->whenLoaded('responsavel', fn () => $this->responsavel ? [
                'id' => $this->responsavel->id,
                'name' => $this->responsavel->name,
            ] : null),
            'prazo_resposta' => $this->prazo_resposta,
            'prazo_resolucao' => $this->prazo_resolucao,
            'respondido_em' => $this->respondido_em,
            'concluido_em' => $this->concluido_em,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
