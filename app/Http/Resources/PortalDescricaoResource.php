<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrada do feed na visão do cliente: só texto, data e o nome de quem
 * escreveu (`equipe` = staff). Sem ids de usuário nem grupo.
 */
class PortalDescricaoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'descricao' => $this->descricao,
            'created_at' => $this->created_at,
            'autor' => [
                'nome' => $this->user?->name ?? $this->customer?->name,
                'equipe' => $this->user_id !== null,
            ],
        ];
    }
}
