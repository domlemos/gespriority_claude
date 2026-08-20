<?php

namespace App\Models;

use Database\Factories\IncidenteResolucaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Um registro por transição pra 'resolvido' — nunca atualizado/apagado, nem
// na reabertura (diferente de Incidente.concluido_em). Ver
// IncidenteController::registrarResolucaoSeAplicavel().
#[Fillable(['incidente_id', 'user_id'])]
class IncidenteResolucao extends Model
{
    /** @use HasFactory<IncidenteResolucaoFactory> */
    use HasFactory;

    // Pluralização automática do Eloquent daria 'incidente_resolucaos'.
    protected $table = 'incidente_resolucoes';

    public const UPDATED_AT = null;

    public function incidente(): BelongsTo
    {
        return $this->belongsTo(Incidente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Mesmas dimensões de Incidente::scopeFiltrosRelatorio(), via whereHas('incidente') — a data filtra o evento (created_at), não Incidente.concluido_em. */
    public function scopeFiltrosRelatorio(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['data_inicio'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['data_fim'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filtros['status'] ?? null, fn (Builder $q, string $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('status', $v)))
            ->when($filtros['item_id'] ?? null, fn (Builder $q, int $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('item_id', $v)))
            ->when(
                $filtros['subcategoria_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('incidente.item', fn (Builder $qi) => $qi->where('subcategoria_id', $v))
            )
            ->when(
                $filtros['categoria_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('incidente.item.subcategoria', fn (Builder $qs) => $qs->where('categoria_id', $v))
            )
            ->when($filtros['grupo_solucao_id'] ?? null, fn (Builder $q, int $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('grupo_solucao_id', $v)))
            ->when($filtros['responsavel_id'] ?? null, fn (Builder $q, int $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('responsavel_id', $v)))
            ->when($filtros['customer_id'] ?? null, fn (Builder $q, int $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('customer_id', $v)))
            ->when(
                $filtros['client_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('incidente.customer', fn (Builder $qc) => $qc->where('client_id', $v))
            );
    }
}
