<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PoliticaSlaResource extends JsonResource
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
            'nome' => $this->nome,
            'prioridade' => $this->prioridade,
            'tempo_resposta_minutos' => $this->tempo_resposta_minutos,
            'tempo_resolucao_minutos' => $this->tempo_resolucao_minutos,
            'apenas_horas_uteis' => $this->apenas_horas_uteis,
            'ativo' => $this->ativo,
            'client_id' => $this->client_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
