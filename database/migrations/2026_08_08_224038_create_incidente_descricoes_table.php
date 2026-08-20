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
        Schema::create('incidente_descricoes', function (Blueprint $table) {
            $table->id();

            // Registro histórico do próprio incidente — sem valor
            // independente dele, some junto (diferente do restrict usado
            // pra Client/Categoria/etc., que protegem registros de negócio
            // com vida própria).
            $table->foreignId('incidente_id')->constrained('incidentes')->cascadeOnDelete();

            // Autor da entrada — quem escreveu o comentário, ou quem
            // disparou a ação de escalonamento (nunca nulo/"sistema").
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // 'comentario' (escrito por um agente, editável/excluível só
            // pelo autor) | 'escalonamento' (gerado automaticamente quando
            // grupo_solucao_id/responsavel_id do incidente muda — ver
            // IncidenteController::update() —, nunca editável/excluível).
            $table->string('tipo');

            $table->text('descricao');

            $table->timestamps();

            $table->index('incidente_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidente_descricoes');
    }
};
