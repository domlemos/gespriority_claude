<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RelatorioSalvoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nova = $this->consultaNova();

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'agrupar_por' => $this->agrupar_por,
            'contar' => $nova['contar'] ?? null,
            'quebra' => $nova['quebra'] ?? null,
            'filtros' => $this->filtros,
            // Só o relatório sem `contar` e sem par no caminho novo roda como antes da Fase 2.
            'formato_antigo' => $nova === null,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
