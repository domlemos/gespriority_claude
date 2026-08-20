<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
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
            'subcategoria_id' => $this->subcategoria_id,
            'nome' => $this->nome,
            'ativo' => $this->ativo,
            'subcategoria' => $this->whenLoaded('subcategoria', fn () => [
                'id' => $this->subcategoria->id,
                'nome' => $this->subcategoria->nome,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
