<?php

namespace App\Models;

use Database\Factories\AnexoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 'caminho' fica de fora de propósito — é o controller quem gera o nome ao
// salvar no disco (AnexoController::store()), nunca vem do cliente.
#[Fillable(['incidente_id', 'user_id', 'nome_original', 'mime_type', 'tamanho'])]
class Anexo extends Model
{
    /** @use HasFactory<AnexoFactory> */
    use HasFactory;

    public function incidente(): BelongsTo
    {
        return $this->belongsTo(Incidente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
