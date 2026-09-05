<?php

namespace App\Models;

use Database\Factories\IncidenteEventoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Um registro por evento estruturado do Incidente (resolvido, fechado,
// encaminhado pra grupo/responsável) — nunca atualizado/apagado, nem na
// reabertura (diferente de Incidente.concluido_em). Generalização de
// IncidenteResolucao (que só cobria 'resolvido') — ver
// BACKEND_SPECS.md §3.1 (`incidente_eventos`) pro porquê.
#[Fillable(['incidente_id', 'user_id', 'tipo', 'alvo_type', 'alvo_id'])]
class IncidenteEvento extends Model
{
    /** @use HasFactory<IncidenteEventoFactory> */
    use HasFactory;

    // Pluralização automática do Eloquent daria 'incidente_eventos' certo
    // por coincidência aqui, mas explícito por consistência com o resto do
    // projeto (todo model em português tem $table explícito).
    protected $table = 'incidente_eventos';

    public const UPDATED_AT = null;

    public const TIPO_RESOLVIDO = 'resolvido';

    public const TIPO_FECHADO = 'fechado';

    public const TIPO_ENCAMINHADO_GRUPO = 'encaminhado_grupo';

    public const TIPO_ENCAMINHADO_RESPONSAVEL = 'encaminhado_responsavel';

    public const TIPOS = [
        self::TIPO_RESOLVIDO,
        self::TIPO_FECHADO,
        self::TIPO_ENCAMINHADO_GRUPO,
        self::TIPO_ENCAMINHADO_RESPONSAVEL,
    ];

    public function incidente(): BelongsTo
    {
        return $this->belongsTo(Incidente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Alvo do encaminhamento (`GrupoSolucao` ou `User`, via `alvo_type`/
     * `alvo_id`) — resolvido manualmente em vez de `morphTo()` pra não
     * depender do comportamento de `withTrashed()` em relação polimórfica
     * (só `User` é soft delete; `GrupoSolucao` não). `null` pra tipos sem
     * alvo (`resolvido`/`fechado`).
     */
    public function alvo(): ?Model
    {
        if ($this->alvo_type === null || $this->alvo_id === null) {
            return null;
        }

        return $this->alvo_type === User::class
            ? User::withTrashed()->find($this->alvo_id)
            : $this->alvo_type::query()->find($this->alvo_id);
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
