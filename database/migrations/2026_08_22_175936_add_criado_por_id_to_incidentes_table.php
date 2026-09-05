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
        Schema::table('incidentes', function (Blueprint $table) {
            // Nullable — "quem abriu" nunca muda depois de setado (não é
            // um evento repetível como resolvido/fechado, não precisa de
            // tabela de eventos, ver BACKEND_SPECS.md §3.4.9), mas incidentes
            // criados antes desta coluna existir não têm como ser
            // retroativamente preenchidos (a informação nunca foi
            // capturada). Toda criação nova a partir daqui sempre seta este
            // campo (IncidenteController::store()).
            $table->foreignId('criado_por_id')->nullable()->after('customer_id')->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidentes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('criado_por_id');
        });
    }
};
