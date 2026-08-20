<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'ativo' => is_null($this->deleted_at),
            'grupo_solucao_id' => $this->grupo_solucao_id,
            'grupo_solucao' => $this->whenLoaded('grupoSolucao', fn () => [
                'id' => $this->grupoSolucao->id,
                'nome' => $this->grupoSolucao->nome,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->values()),
            'permissions' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->flatMap->permissions->pluck('slug')->unique()->values()
            ),
        ];
    }
}
