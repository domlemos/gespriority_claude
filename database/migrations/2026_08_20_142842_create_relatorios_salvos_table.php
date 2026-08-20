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
        Schema::create('relatorios_salvos', function (Blueprint $table) {
            $table->id();

            // Autor — quem salvou a configuração. `restrictOnDelete()` sem
            // guard extra em UserController::destroy() de propósito: User
            // é soft delete (ver §3.4.3), a linha nunca some de verdade,
            // então essa FK nunca chega a ser violada.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('nome');

            // Filtros do relatório (status, data_inicio, data_fim,
            // categoria_id, subcategoria_id, item_id, grupo_solucao_id,
            // responsavel_id, client_id, customer_id) — schema-less de
            // propósito: espelha exatamente o payload aceito por
            // RelatorioController::index(), evita duplicar/sincronizar uma
            // coluna por filtro toda vez que um filtro novo for adicionado.
            $table->json('filtros');

            // status_sla | responsavel | grupo_solucao | categoria | subcategoria | item —
            // ver RelatorioSalvo::AGRUPAMENTOS, RelatorioController.
            $table->string('agrupar_por');

            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relatorios_salvos');
    }
};
