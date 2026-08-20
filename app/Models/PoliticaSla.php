<?php

namespace App\Models;

use Database\Factories\PoliticaSlaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['nome', 'prioridade', 'tempo_resposta_minutos', 'tempo_resolucao_minutos', 'apenas_horas_uteis', 'ativo', 'client_id'])]
class PoliticaSla extends Model
{
    /** @use HasFactory<PoliticaSlaFactory> */
    use HasFactory;

    protected $table = 'politicas_sla';

    public const PRIORIDADES = ['baixa', 'media', 'alta', 'urgente'];

    protected function casts(): array
    {
        return [
            'tempo_resposta_minutos' => 'integer',
            'tempo_resolucao_minutos' => 'integer',
            'apenas_horas_uteis' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Filtro composto pra listagem (`PoliticaSlaController::index()`).
     * `tempo_resposta_minutos`/`tempo_resolucao_minutos` ficam de fora de
     * propósito — não são campos de busca/seleção de uma lista fechada, é
     * escopo explicitamente excluído desta entrega.
     *
     * `ativo`/`apenas_horas_uteis` usam `array_key_exists()` em vez de
     * `$filtros['x'] ?? null` — são booleanos, e `false ?? null` avalia pra
     * `false`, que o `->when()` trataria como "sem filtro" e ignoraria
     * silenciosamente um filtro explícito por `false`. `array_key_exists()`
     * distingue "campo ausente" (chave não veio da validação `sometimes`)
     * de "campo presente com valor `false`".
     *
     * `client_id` tem três estados possíveis, não dois: sem filtro (chave
     * ausente), filtrar só políticas "Global" (`client_id` nulo na tabela —
     * chave presente com o sentinel string `'global'`, nunca `null` puro,
     * que colidiria com "sem filtro"), ou filtrar por um cliente específico
     * (chave presente com um id numérico).
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['nome'] ?? null, fn (Builder $q, string $v) => $q->where('nome', 'like', "%{$v}%"))
            ->when($filtros['prioridade'] ?? null, fn (Builder $q, string $v) => $q->where('prioridade', $v))
            ->when(
                array_key_exists('ativo', $filtros),
                fn (Builder $q) => $q->where('ativo', $filtros['ativo']),
            )
            ->when(
                array_key_exists('apenas_horas_uteis', $filtros),
                fn (Builder $q) => $q->where('apenas_horas_uteis', $filtros['apenas_horas_uteis']),
            )
            ->when(
                array_key_exists('client_id', $filtros),
                function (Builder $q) use ($filtros) {
                    $value = $filtros['client_id'];

                    if ($value === 'global') {
                        $q->whereNull('client_id');
                    } else {
                        $q->where('client_id', $value);
                    }
                },
            );
    }
}
