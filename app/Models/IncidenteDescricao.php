<?php

namespace App\Models;

use Database\Factories\IncidenteDescricaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Excluir um comentário é soft delete de propósito — fica no feed marcado
// (`excluido_em` no Resource), nunca é apagado de verdade (auditoria). Ver
// BACKEND_SPECS.md §3.4.7.1.
#[Fillable(['incidente_id', 'user_id', 'tipo', 'descricao'])]
class IncidenteDescricao extends Model
{
    /** @use HasFactory<IncidenteDescricaoFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'incidente_descricoes';

    /**
     * Comentários excluídos continuam visíveis no feed (Incidente::descricoes()
     * usa withTrashed()) — sem isso, o binding de rota resolveria a entrada
     * como "não encontrada" e um GET/PUT/DELETE num item já excluído
     * devolveria 404 em vez do 200 (ainda visível) ou 403 (ação bloqueada)
     * esperados.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->withTrashed()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public const TIPO_COMENTARIO = 'comentario';

    public const TIPO_ESCALONAMENTO = 'escalonamento';

    public const TIPO_ALTERACAO = 'alteracao';

    public const TIPOS = [self::TIPO_COMENTARIO, self::TIPO_ESCALONAMENTO, self::TIPO_ALTERACAO];

    public function incidente(): BelongsTo
    {
        return $this->belongsTo(Incidente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
