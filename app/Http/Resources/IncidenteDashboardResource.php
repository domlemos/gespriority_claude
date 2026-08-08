<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidenteDashboardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Resolvido em tempo real (não persistido no Incidente — "só a parte
        // cadastral", ver BACKEND_SPECS.md seção 3.4.7), mesmo mecanismo de
        // Client::resolvedSlaFor() usado pra qualquer outra consulta de SLA.
        $politica = $this->customer?->client?->resolvedSlaFor($this->prioridade);

        return [
            'numero' => $this->id,
            'titulo' => $this->titulo,
            'origem' => $this->origem,
            'status' => $this->status,
            'prioridade' => $this->prioridade,
            'cliente' => $this->customer?->client?->name,
            'email_cliente' => $this->customer?->email,
            'tempo_resposta_minutos' => $politica?->tempo_resposta_minutos,
            'tempo_resposta_horas' => $politica ? round($politica->tempo_resposta_minutos / 60, 2) : null,
            'tempo_resolucao_minutos' => $politica?->tempo_resolucao_minutos,
            'tempo_resolucao_horas' => $politica ? round($politica->tempo_resolucao_minutos / 60, 2) : null,
            'categoria' => $this->item?->subcategoria?->categoria?->nome,
            'subcategoria' => $this->item?->subcategoria?->nome,
            'item' => $this->item?->nome,
        ];
    }
}
