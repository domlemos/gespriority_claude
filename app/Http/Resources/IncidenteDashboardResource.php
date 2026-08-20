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
        // tempo_resposta/resolucao_minutos derivados de prazo_* - created_at
        // (congelados no Incidente na abertura, ver §3.1) em vez de chamar
        // Client::resolvedSlaFor() de novo — zero query extra por linha,
        // diferente da versão anterior desta resource.
        $tempoRespostaMinutos = $this->prazo_resposta
            ? $this->created_at->diffInMinutes($this->prazo_resposta)
            : null;
        $tempoResolucaoMinutos = $this->prazo_resolucao
            ? $this->created_at->diffInMinutes($this->prazo_resolucao)
            : null;

        return [
            'numero' => $this->id,
            'titulo' => $this->titulo,
            'origem' => $this->origem,
            'status' => $this->status,
            'prioridade' => $this->prioridade,
            'cliente' => $this->customer?->client?->name,
            'email_cliente' => $this->customer?->email,
            'data_abertura' => $this->created_at,
            'prazo_resposta' => $this->prazo_resposta,
            'prazo_resolucao' => $this->prazo_resolucao,
            'status_sla_resposta' => $this->statusSlaResposta(),
            'status_sla_resolucao' => $this->statusSlaResolucao(),
            'tempo_resposta_minutos' => $tempoRespostaMinutos,
            'tempo_resposta_horas' => $tempoRespostaMinutos !== null ? round($tempoRespostaMinutos / 60, 2) : null,
            'tempo_resolucao_minutos' => $tempoResolucaoMinutos,
            'tempo_resolucao_horas' => $tempoResolucaoMinutos !== null ? round($tempoResolucaoMinutos / 60, 2) : null,
            'tempo_restante_resposta_minutos' => $this->tempoRestanteRespostaMinutos(),
            'tempo_restante_resolucao_minutos' => $this->tempoRestanteResolucaoMinutos(),
            'categoria' => $this->item?->subcategoria?->categoria?->nome,
            'subcategoria' => $this->item?->subcategoria?->nome,
            'item' => $this->item?->nome,
            'grupo_solucao' => $this->grupoSolucao?->nome,
            'responsavel' => $this->responsavel?->name,
        ];
    }
}
