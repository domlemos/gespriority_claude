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
            // Calculados uma única vez no store() (created_at + tempo_resposta/
            // resolucao_minutos da política aplicável) e congelados — nunca
            // recalculados depois, mesmo que a política mude (ver BACKEND_SPECS.md
            // seção 3.1). Nullable: incidente sem política de SLA aplicável
            // (prioridade sem policy nem global) fica "sem_sla".
            $table->timestamp('prazo_resposta')->nullable()->after('status');
            $table->timestamp('prazo_resolucao')->nullable()->after('prazo_resposta');

            // Marcos setados automaticamente em transições de status (nunca
            // vêm do cliente) — usados como referência no lugar de "agora"
            // quando o incidente já está concluído, pra não deixar o status
            // de SLA mudar depois do fato consumado.
            $table->timestamp('respondido_em')->nullable()->after('prazo_resolucao');
            $table->timestamp('concluido_em')->nullable()->after('respondido_em');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidentes', function (Blueprint $table) {
            $table->dropColumn(['prazo_resposta', 'prazo_resolucao', 'respondido_em', 'concluido_em']);
        });
    }
};
