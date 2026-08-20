<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['subcategoria_id', 'nome', 'ativo'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $table = 'itens';

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function subcategoria(): BelongsTo
    {
        return $this->belongsTo(Subcategoria::class);
    }

    public function incidentes(): HasMany
    {
        return $this->hasMany(Incidente::class);
    }

    /**
     * Filtro composto — `nome` LIKE parcial, `subcategoria_id` igualdade
     * exata, `ativo` via `array_key_exists` (ver Categoria::scopeFiltros).
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['nome'] ?? null, fn (Builder $q, string $v) => $q->where('nome', 'like', "%{$v}%"))
            ->when($filtros['subcategoria_id'] ?? null, fn (Builder $q, int $v) => $q->where('subcategoria_id', $v))
            ->when(array_key_exists('ativo', $filtros), fn (Builder $q) => $q->where('ativo', $filtros['ativo']));
    }
}
