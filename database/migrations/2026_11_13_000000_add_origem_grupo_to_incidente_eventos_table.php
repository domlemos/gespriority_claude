<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grupo de onde saiu cada `encaminhado_grupo` (`origem_grupo_id`) e
 * preenchimento dos encaminhamentos já gravados. Só query builder, sem models
 * nem constantes da aplicação (mesma regra das outras migrations de
 * backfill), em chunks de 500.
 *
 * Idempotente **só antes da entrada em produção**: a coluna só é criada se
 * não existe, e o preenchimento só toca `encaminhado_grupo` com
 * `origem_grupo_id` nulo; o resultado de cada evento depende só do histórico
 * (nunca da origem de outro evento), então rodar de novo dá o mesmo
 * resultado. `retroativo` só é ligado, nunca desligado. Depois que o
 * `IncidenteController` passa a gravar a origem ao vivo, rodar de novo
 * sobrescreveria os nulos reais ("Sem grupo") com uma origem reconstruída.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('incidente_eventos', 'origem_grupo_id')) {
            Schema::table('incidente_eventos', function (Blueprint $table) {
                // Grupo do chamado antes do encaminhamento (ou da remoção do
                // grupo, no `removido_grupo`); nulo = sem grupo ou desconhecido.
                $table->foreignId('origem_grupo_id')->nullable()->after('alvo_id')
                    ->constrained('grupos_solucao')->nullOnDelete();
                $table->index('origem_grupo_id');
            });
        }

        $this->preencherOrigens();
    }

    private function preencherOrigens(): void
    {
        DB::table('incidente_eventos')
            ->where('tipo', 'encaminhado_grupo')
            ->whereNull('origem_grupo_id')
            ->chunkById(500, function ($eventos) {
                foreach ($eventos as $evento) {
                    [$origem, $marcar] = $this->origemDe($evento);

                    if ($origem === null && ! $marcar) {
                        continue;
                    }

                    DB::table('incidente_eventos')->where('id', $evento->id)->update(
                        ['origem_grupo_id' => $origem] + ($marcar ? ['retroativo' => true] : [])
                    );
                }
            });
    }

    /**
     * Origem do encaminhamento e se ele deve ser marcado `retroativo`
     * (origem desconhecida, não "sem grupo").
     *
     * @return array{0: int|null, 1: bool}
     */
    private function origemDe(object $evento): array
    {
        // Ordem do chamado: created_at, depois id.
        $anterior = DB::table('incidente_eventos')
            ->where('incidente_id', $evento->incidente_id)
            ->where('tipo', 'encaminhado_grupo')
            ->where(fn ($q) => $q
                ->where('created_at', '<', $evento->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $evento->created_at)->where('id', '<', $evento->id)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first();

        if ($anterior !== null) {
            // alvo_id pode apontar pra grupo já excluído (a FK quebraria).
            $origem = $anterior->alvo_id === null
                ? null
                : DB::table('grupos_solucao')->where('id', $anterior->alvo_id)->value('id');

            return [$origem, $origem === null];
        }

        $aberto = DB::table('incidente_eventos')
            ->where('incidente_id', $evento->incidente_id)
            ->where('tipo', 'aberto')
            ->orderBy('created_at')->orderBy('id')
            ->first();

        return match (true) {
            $aberto === null => [null, true],
            $aberto->grupo_solucao_id !== null => [(int) $aberto->grupo_solucao_id, false],
            // Portal sem grupo: a leitura mostra a origem como "Cliente".
            $aberto->autor_customer_id !== null => [null, false],
            // Abertura reconstruída sem grupo: o grupo inicial é desconhecido.
            (bool) $aberto->retroativo => [null, true],
            // Abertura ao vivo sem grupo: o chamado de fato não tinha grupo.
            default => [null, false],
        };
    }

    /**
     * Apaga os `removido_grupo` (não existem sem a coluna) e remove a coluna.
     * Não desfaz a marcação `retroativo` dos encaminhamentos: depois do
     * backfill não dá pra distinguir os marcados aqui dos marcados antes.
     */
    public function down(): void
    {
        DB::table('incidente_eventos')->where('tipo', 'removido_grupo')->delete();

        Schema::table('incidente_eventos', function (Blueprint $table) {
            $table->dropIndex(['origem_grupo_id']);
            $table->dropConstrainedForeignId('origem_grupo_id');
        });
    }
};
