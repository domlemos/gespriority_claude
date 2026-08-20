<?php

namespace App\Models;

use Database\Factories\GrupoSolucaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nome', 'ativo'])]
class GrupoSolucao extends Model
{
    /** @use HasFactory<GrupoSolucaoFactory> */
    use HasFactory;

    protected $table = 'grupos_solucao';

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function incidentes(): HasMany
    {
        return $this->hasMany(Incidente::class);
    }

    /**
     * `nome` — busca parcial (`LIKE '%valor%'`), mesmo motivo do
     * `Client::scopeFiltros()`: é a única coluna de texto filtrável, caso de
     * uso é "procurar grupo pelo nome". `ativo` é booleano — detectado via
     * `array_key_exists()`, não `?? null`, porque `false ?? null` também
     * avalia pra `false` e faria `when()` pular o filtro quando alguém
     * explicitamente pede `ativo=false` ("Não").
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['nome'] ?? null, fn (Builder $q, string $v) => $q->where('nome', 'like', "%{$v}%"))
            ->when(array_key_exists('ativo', $filtros), fn (Builder $q) => $q->where('ativo', $filtros['ativo']));
    }
}
