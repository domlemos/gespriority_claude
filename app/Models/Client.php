<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function politicasSla(): HasMany
    {
        return $this->hasMany(PoliticaSla::class);
    }

    /**
     * Resolve a política de SLA aplicável a este cliente para uma
     * prioridade: prioriza uma política própria do cliente e cai para o
     * "padrão global" (client_id nulo) da mesma prioridade quando não há
     * override — ver seção 3.1 do BACKEND_SPECS.md.
     */
    public function resolvedSlaFor(string $prioridade): ?PoliticaSla
    {
        return PoliticaSla::query()
            ->where('prioridade', $prioridade)
            ->where('ativo', true)
            ->where(function ($query) {
                $query->where('client_id', $this->id)->orWhereNull('client_id');
            })
            ->orderByRaw('client_id is null')
            ->first();
    }

    /**
     * Filtro composto — hoje só `name`, por busca parcial (`LIKE`), não
     * igualdade exata (diferente de `Incidente::scopeFiltros()`) porque é a
     * única coluna filtrável da listagem e o caso de uso é "procurar
     * cliente pelo nome", não selecionar um valor de uma lista fechada.
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query->when(
            $filtros['name'] ?? null,
            fn (Builder $q, string $v) => $q->where('name', 'like', "%{$v}%"),
        );
    }
}
