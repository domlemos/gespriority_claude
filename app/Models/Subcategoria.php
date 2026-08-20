<?php

namespace App\Models;

use Database\Factories\SubcategoriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['categoria_id', 'nome', 'ativo'])]
class Subcategoria extends Model
{
    /** @use HasFactory<SubcategoriaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Filtro composto — `nome` LIKE parcial, `categoria_id` igualdade exata,
     * `ativo` via `array_key_exists` (ver Categoria::scopeFiltros pro porquê
     * de não usar `??` aqui).
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['nome'] ?? null, fn (Builder $q, string $v) => $q->where('nome', 'like', "%{$v}%"))
            ->when($filtros['categoria_id'] ?? null, fn (Builder $q, int $v) => $q->where('categoria_id', $v))
            ->when(array_key_exists('ativo', $filtros), fn (Builder $q) => $q->where('ativo', $filtros['ativo']));
    }
}
