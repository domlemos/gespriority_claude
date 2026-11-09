<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidentes', function (Blueprint $table) {
            // Categorização do chamado (Incidente::TIPOS) — mesmo
            // enum-em-string de `origem`/`status`. Default 'incidente' faz o
            // backfill dos chamados existentes e mantém compatível quem chama
            // a API sem mandar o campo (ver BACKEND_SPECS.md §3.1).
            $table->string('tipo')->default('incidente')->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('incidentes', function (Blueprint $table) {
            $table->dropIndex(['tipo']);
            $table->dropColumn('tipo');
        });
    }
};
