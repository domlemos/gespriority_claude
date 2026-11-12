<?php

namespace App\Models;

use App\Support\PeriodoRelatorio;
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
#[Fillable(['incidente_id', 'user_id', 'tipo', 'alvo_type', 'alvo_id', 'grupo_solucao_id', 'autor_grupo_solucao_id', 'autor_customer_id', 'retroativo'])]
class IncidenteEvento extends Model
{
    /** @use HasFactory<IncidenteEventoFactory> */
    use HasFactory;

    // Pluralização automática do Eloquent daria 'incidente_eventos' certo
    // por coincidência aqui, mas explícito por consistência com o resto do
    // projeto (todo model em português tem $table explícito).
    protected $table = 'incidente_eventos';

    public const UPDATED_AT = null;

    public const TIPO_ABERTO = 'aberto';

    public const TIPO_RESOLVIDO = 'resolvido';

    public const TIPO_FECHADO = 'fechado';

    public const TIPO_CANCELADO = 'cancelado';

    public const TIPO_REABERTO = 'reaberto';

    public const TIPO_ENCAMINHADO_GRUPO = 'encaminhado_grupo';

    public const TIPO_ENCAMINHADO_RESPONSAVEL = 'encaminhado_responsavel';

    public const TIPOS = [
        self::TIPO_ABERTO,
        self::TIPO_RESOLVIDO,
        self::TIPO_FECHADO,
        self::TIPO_CANCELADO,
        self::TIPO_REABERTO,
        self::TIPO_ENCAMINHADO_GRUPO,
        self::TIPO_ENCAMINHADO_RESPONSAVEL,
    ];

    protected function casts(): array
    {
        return ['retroativo' => 'boolean'];
    }

    /**
     * Congela o grupo do incidente e o grupo do autor no momento do evento.
     * Só preenche o que não foi informado (array_key_exists, não isset),
     * pra permitir `null` explícito (ex.: backfill, testes) sem ser
     * sobrescrito. O evento é insert-only, então o snapshot nunca muda.
     */
    protected static function booted(): void
    {
        static::creating(function (self $evento) {
            $atributos = $evento->getAttributes();

            if (! array_key_exists('grupo_solucao_id', $atributos)) {
                $evento->grupo_solucao_id = Incidente::find($evento->incidente_id)?->grupo_solucao_id;
            }

            if (! array_key_exists('autor_grupo_solucao_id', $atributos)) {
                $evento->autor_grupo_solucao_id = $evento->user_id === null
                    ? null
                    : User::withTrashed()->find($evento->user_id)?->grupo_solucao_id;
            }
        });
    }

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

    /** Mesmas dimensões de Incidente::scopeFiltrosRelatorio(), via whereHas('incidente') — exceto `grupo_solucao_id`, que usa o snapshot do grupo no momento do evento (coluna desta tabela), não o grupo atual do incidente — a data filtra o evento (created_at), não Incidente.concluido_em. */
    public function scopeFiltrosRelatorio(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['data_inicio'] ?? null, fn (Builder $q, string $v) => $q->where('created_at', '>=', PeriodoRelatorio::inicioUtc($v)))
            ->when($filtros['data_fim'] ?? null, fn (Builder $q, string $v) => $q->where('created_at', '<', PeriodoRelatorio::fimExclusivoUtc($v)))
            ->when($filtros['status'] ?? null, fn (Builder $q, string $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('status', $v)))
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('tipo', $v)))
            ->when($filtros['item_id'] ?? null, fn (Builder $q, int $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('item_id', $v)))
            ->when(
                $filtros['subcategoria_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('incidente.item', fn (Builder $qi) => $qi->where('subcategoria_id', $v))
            )
            ->when(
                $filtros['categoria_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('incidente.item.subcategoria', fn (Builder $qs) => $qs->where('categoria_id', $v))
            )
            ->when($filtros['grupo_solucao_id'] ?? null, fn (Builder $q, int $v) => $q->where('grupo_solucao_id', $v))
            // Grupo do autor no momento do evento (snapshot), não o atual.
            ->when($filtros['grupo_analista_id'] ?? null, fn (Builder $q, int $v) => $q->where('autor_grupo_solucao_id', $v))
            ->when($filtros['responsavel_id'] ?? null, fn (Builder $q, int $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('responsavel_id', $v)))
            ->when($filtros['customer_id'] ?? null, fn (Builder $q, int $v) => $q->whereHas('incidente', fn (Builder $qi) => $qi->where('customer_id', $v)))
            ->when(
                $filtros['client_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('incidente.customer', fn (Builder $qc) => $qc->where('client_id', $v))
            );
    }

    /**
     * Mesma regra de Incidente::scopeVisiveisPara(), via whereHas('incidente'). O evento tem um snapshot
     * `grupo_solucao_id`, mas a visibilidade usa deliberadamente o grupo ATUAL do incidente (decisão da
     * fase 1; visibilidade baseada no snapshot fica pra fase 2).
     */
    public function scopeVisiveisPara(Builder $query, User $user): Builder
    {
        return $query->whereHas('incidente', fn (Builder $qi) => $qi->visiveisPara($user));
    }

    /**
     * Visibilidade do caminho novo de relatórios (spec 5.3): o evento é
     * visível se o chamado é visível hoje OU se o grupo do chamado / do autor
     * NO MOMENTO do evento (snapshots) está entre os grupos do usuário. Sem
     * isso, o time que concluiu um chamado perderia o histórico assim que
     * ele fosse encaminhado a outro grupo. Admin (`null`) vê tudo.
     * `scopeVisiveisPara` fica como está para o caminho legado.
     */
    public function scopeVisiveisParaRelatorio(Builder $query, User $user): Builder
    {
        $ids = $user->visibleGrupoSolucaoIds();

        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereHas('incidente', fn (Builder $qi) => $qi->visiveisPara($user))
            ->orWhereIn('grupo_solucao_id', $ids)
            ->orWhereIn('autor_grupo_solucao_id', $ids));
    }
}
