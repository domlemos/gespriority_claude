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
        Schema::rename('incidente_resolucoes', 'incidente_eventos');

        Schema::table('incidente_eventos', function (Blueprint $table) {
            // Default 'resolvido' pra ficar correto pro backfill — até aqui
            // a tabela só guardava evento de resolução mesmo, então toda
            // linha existente É desse tipo. Sem default a coluna NOT NULL
            // quebraria em bancos com dado (dev local já tinha linhas desta
            // feature no momento da migration). String literal, não uma
            // constante do model — migration não deveria depender de
            // código da aplicação, que pode mudar depois. O default fica
            // pra sempre (não removido — exigiria doctrine/dbal, que não
            // está instalado); inofensivo, já que o código sempre define
            // 'tipo' explicitamente a partir daqui (ver IncidenteController).
            $table->string('tipo')->default('resolvido')->after('incidente_id');

            // Alvo do encaminhamento (GrupoSolucao ou User) — só preenchido
            // pra tipo = encaminhado_grupo/encaminhado_responsavel; nulo
            // pros demais tipos, que não têm "destino".
            $table->nullableMorphs('alvo');

            $table->index(['incidente_id', 'tipo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidente_eventos', function (Blueprint $table) {
            $table->dropIndex(['incidente_id', 'tipo']);
            $table->dropColumn(['tipo', 'alvo_type', 'alvo_id']);
        });

        Schema::rename('incidente_eventos', 'incidente_resolucoes');
    }
};
