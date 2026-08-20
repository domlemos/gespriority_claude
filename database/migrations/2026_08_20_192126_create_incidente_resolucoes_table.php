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
        Schema::create('incidente_resolucoes', function (Blueprint $table) {
            $table->id();

            // Registro de evento, sem vida própria fora do incidente —
            // mesmo raciocínio de incidente_descricoes.
            $table->foreignId('incidente_id')->constrained('incidentes')->cascadeOnDelete();

            // Quem resolveu — nunca nulo/"sistema".
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Só created_at — a linha nunca é atualizada, é inserida uma vez
            // por transição pra 'resolvido' e nunca mais tocada (nem na
            // reabertura, diferente de Incidente.concluido_em, que É limpo
            // nesse caso). É exatamente essa diferença que torna esta
            // tabela necessária: um chamado resolvido/reaberto/resolvido de
            // novo gera DUAS linhas aqui, cada uma com seu autor e data,
            // preservando as duas resoluções — uma coluna única em
            // `incidentes` só conseguiria guardar a mais recente.
            $table->timestamp('created_at')->useCurrent();

            $table->index('incidente_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidente_resolucoes');
    }
};
