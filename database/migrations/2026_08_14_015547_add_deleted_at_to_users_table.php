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
        Schema::table('users', function (Blueprint $table) {
            // "Excluir" um usuário agora é desativação (soft delete) — o
            // registro nunca some de verdade, então referências históricas
            // (Incidente.responsavel_id, IncidenteDescricao.user_id,
            // Anexo.user_id) continuam íntegras mesmo depois. Ver
            // BACKEND_SPECS.md §3.1/§3.4.3.
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
