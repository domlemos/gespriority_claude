<?php

namespace App\Models;

use Database\Factories\RelatorioSalvoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'nome', 'filtros', 'agrupar_por'])]
class RelatorioSalvo extends Model
{
    /** @use HasFactory<RelatorioSalvoFactory> */
    use HasFactory;

    // Pluralização automática do Eloquent daria 'relatorio_salvos' (só o
    // último termo pluralizado); a tabela é 'relatorios_salvos'.
    protected $table = 'relatorios_salvos';

    public const AGRUPAMENTOS = [
        'status_sla', 'responsavel', 'aberto_por', 'resolvido_por', 'fechado_por',
        'encaminhado_por', 'encaminhado_para_grupo', 'encaminhado_para_responsavel',
        'grupo_solucao', 'categoria', 'subcategoria', 'item',
    ];

    protected function casts(): array
    {
        return [
            'filtros' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
