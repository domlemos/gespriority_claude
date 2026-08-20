<?php

namespace App\Models;

use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nome', 'ativo'])]
class Categoria extends Model
{
    /** @use HasFactory<CategoriaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function subcategorias(): HasMany
    {
        return $this->hasMany(Subcategoria::class);
    }

    /**
     * Filtro composto — `nome` é LIKE parcial, `ativo` só filtra quando a
     * chave está presente em `$filtros` (`array_key_exists`, não `??`): um
     * `ativo=false` explícito precisa filtrar, e `false ?? null` avaliaria
     * pra `false`, que `->when()` trata como "sem filtro" (ver Incidente::scopeFiltros).
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['nome'] ?? null, fn (Builder $q, string $v) => $q->where('nome', 'like', "%{$v}%"))
            ->when(array_key_exists('ativo', $filtros), fn (Builder $q) => $q->where('ativo', $filtros['ativo']));
    }
}
