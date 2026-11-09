<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('incidente_eventos', function (Blueprint $table) {
            // Snapshot do grupo do incidente NO MOMENTO do evento. Sem isso
            // o relatório por evento (fechado_por, resolvido_por...) filtra
            // pelo grupo ATUAL do incidente, e um chamado encaminhado depois
            // passa a "pertencer" retroativamente ao grupo novo. nullOnDelete
            // porque o histórico não pode impedir a exclusão de um grupo.
            $table->foreignId('grupo_solucao_id')->nullable()->after('alvo_id')
                ->constrained('grupos_solucao')->nullOnDelete();

            // Snapshot do grupo de quem executou a ação (o analista), pelo
            // mesmo motivo: o analista pode trocar de grupo depois.
            $table->foreignId('autor_grupo_solucao_id')->nullable()->after('grupo_solucao_id')
                ->constrained('grupos_solucao')->nullOnDelete();

            // Filtro do relatório por grupo no momento do evento (a FK, em
            // PostgreSQL, não cria índice sozinha).
            $table->index('grupo_solucao_id');
            $table->index('autor_grupo_solucao_id');
        });

        $this->preencherHistorico();
    }

    /**
     * Backfill dos eventos já existentes, só com query builder (sem models
     * nem constantes da aplicação — mesma regra da migration de
     * generalização). Aproximações, por falta de informação histórica:
     *
     * - autor_grupo_solucao_id = grupo ATUAL do usuário (o grupo dele na
     *   época nunca foi guardado).
     * - grupo_solucao_id: (1) se houve encaminhamento de grupo do incidente
     *   até o evento (created_at <=, desempate por id <=), vale o destino do
     *   mais recente; (2) senão, se não houve nenhum encaminhamento
     *   posterior, o grupo não mudou desde então e vale o atual do
     *   incidente; (3) senão null — o grupo inicial é desconhecido, porque
     *   o store() do incidente não gera evento de encaminhamento.
     */
    private function preencherHistorico(): void
    {
        DB::table('incidente_eventos')->orderBy('id')->chunkById(500, function ($eventos) {
            foreach ($eventos as $evento) {
                $anterior = DB::table('incidente_eventos')
                    ->where('incidente_id', $evento->incidente_id)
                    ->where('tipo', 'encaminhado_grupo')
                    ->where(fn ($q) => $q
                        ->where('created_at', '<', $evento->created_at)
                        ->orWhere(fn ($q2) => $q2->where('created_at', $evento->created_at)->where('id', '<=', $evento->id)))
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->first();

                if ($anterior !== null) {
                    // alvo_id pode apontar pra grupo já excluído; sem a
                    // checagem a FK quebraria o backfill.
                    $grupoId = DB::table('grupos_solucao')->where('id', $anterior->alvo_id)->value('id');
                } else {
                    $temPosterior = DB::table('incidente_eventos')
                        ->where('incidente_id', $evento->incidente_id)
                        ->where('tipo', 'encaminhado_grupo')
                        ->where(fn ($q) => $q
                            ->where('created_at', '>', $evento->created_at)
                            ->orWhere(fn ($q2) => $q2->where('created_at', $evento->created_at)->where('id', '>', $evento->id)))
                        ->exists();

                    $grupoId = $temPosterior
                        ? null
                        : DB::table('incidentes')->where('id', $evento->incidente_id)->value('grupo_solucao_id');
                }

                DB::table('incidente_eventos')->where('id', $evento->id)->update([
                    'grupo_solucao_id' => $grupoId,
                    'autor_grupo_solucao_id' => DB::table('users')->where('id', $evento->user_id)->value('grupo_solucao_id'),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidente_eventos', function (Blueprint $table) {
            $table->dropIndex(['autor_grupo_solucao_id']);
            $table->dropIndex(['grupo_solucao_id']);
            $table->dropConstrainedForeignId('autor_grupo_solucao_id');
            $table->dropConstrainedForeignId('grupo_solucao_id');
        });
    }
};
