<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens', function (Blueprint $table) {
            // Prioridade de SLA sugerida ao abrir um incidente pra este item
            // — nullable: item sem prioridade padrão mantém o fluxo atual
            // (prioridade sempre escolhida livremente na abertura). Mesmo
            // enum-em-string de PoliticaSla::PRIORIDADES, sem FK direta pra
            // uma PoliticaSla específica (ver BACKEND_SPECS.md §3.1).
            $table->string('prioridade_padrao')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('itens', function (Blueprint $table) {
            $table->dropColumn('prioridade_padrao');
        });
    }
};
