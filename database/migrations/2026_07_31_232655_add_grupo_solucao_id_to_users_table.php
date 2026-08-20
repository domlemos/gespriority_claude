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
            // NOT NULL — todo User (staff) pertence a um grupo de solução;
            // Customer (guard separado, tabela separada) nunca tem essa
            // coluna, é sempre isento (ver BACKEND_SPECS.md seção 3.1).
            $table->foreignId('grupo_solucao_id')->after('id')->constrained('grupos_solucao')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grupo_solucao_id');
        });
    }
};
