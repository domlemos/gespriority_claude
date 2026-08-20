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
        Schema::create('anexos', function (Blueprint $table) {
            $table->id();

            // Registro histórico do próprio incidente — some junto com ele
            // (não há rota de exclusão de Incidente, então isso só entra em
            // jogo se o incidente for removido diretamente no banco).
            $table->foreignId('incidente_id')->constrained('incidentes')->cascadeOnDelete();

            // Quem enviou o arquivo — nunca nulo/"sistema".
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Nome original do arquivo enviado pelo cliente (exibição/download);
            // 'caminho' é o nome gerado ao salvar no disco (evita colisão e
            // path traversal) — os dois nunca são o mesmo valor.
            $table->string('nome_original');
            $table->string('caminho');
            $table->string('mime_type');
            $table->unsignedBigInteger('tamanho');

            $table->timestamps();

            $table->index('incidente_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anexos');
    }
};
