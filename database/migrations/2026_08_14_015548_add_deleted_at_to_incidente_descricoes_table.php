<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('incidente_descricoes', function (Blueprint $table) {
            // Excluir um comentário é soft delete de propósito — fica no
            // feed pra fins de auditoria (marcado via 'excluido_em' no
            // IncidenteDescricaoResource), nunca é apagado de verdade. Ver
            // BACKEND_SPECS.md §3.4.7.1.
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidente_descricoes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
