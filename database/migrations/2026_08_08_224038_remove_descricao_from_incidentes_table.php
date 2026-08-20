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
        // A descrição do incidente vira feed (tabela `incidente_descricoes`)
        // em vez de campo único — ver BACKEND_SPECS.md seção 3.1. A
        // descrição de abertura passa a ser a primeira entrada do feed
        // (tipo 'comentario'), criada na mesma transação do POST /incidentes.
        Schema::table('incidentes', function (Blueprint $table) {
            $table->dropColumn('descricao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidentes', function (Blueprint $table) {
            $table->text('descricao')->nullable();
        });
    }
};
