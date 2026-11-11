<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorios_salvos', function (Blueprint $table) {
            // Ambas nullable de propósito: `contar` nulo identifica um relatório
            // salvo antes da Fase 2 (só `agrupar_por` legado), que continua com
            // o mesmo significado; sem backfill, as linhas antigas não mudam.
            // O período relativo (`periodo`) vive dentro de `filtros` (json),
            // então não precisa de coluna.
            $table->string('contar')->nullable()->after('agrupar_por');
            $table->string('quebra')->nullable()->after('contar');
        });
    }

    public function down(): void
    {
        Schema::table('relatorios_salvos', function (Blueprint $table) {
            $table->dropColumn(['contar', 'quebra']);
        });
    }
};
