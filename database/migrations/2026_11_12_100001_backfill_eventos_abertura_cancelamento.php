<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconstrói o histórico de abertura, cancelamento e reabertura em
 * `incidente_eventos` (que até aqui só tinha resolvido/fechado/encaminhado_*)
 * e marca como `retroativo` tudo o que é aproximado. Só query builder, sem
 * models nem constantes da aplicação (mesma regra das outras migrations de
 * backfill), em chunks de 500. Idempotente: pode rodar de novo sem duplicar.
 *
 * Instante do deploy da Fase 1 (snapshot de grupos, commit 619dad0):
 * 2026-11-11T14:49:34Z — FIM do job "deploy" do primeiro run do workflow
 * "Deploy (EC2 / infra2)" na main cujo head (ed093baa) contém 619dad0
 * (run 43042025933, success; run criado às 14:48:23Z). Usa o fim, não o
 * início: eventos gravados durante o deploy ainda vieram do código antigo,
 * e marcar a mais como aproximado é aceitável; marcar como exato o que é
 * aproximado não. Eventos anteriores tiveram o grupo do autor preenchido
 * pelo backfill da Fase 1 com o grupo ATUAL da pessoa, por isso são
 * aproximados.
 */
return new class extends Migration
{
    private const DEPLOY_FASE_1 = '2026-11-11 14:49:34';

    private const TIPOS_JA_EXISTENTES = ['resolvido', 'fechado', 'encaminhado_grupo', 'encaminhado_responsavel'];

    private const STATUS_CONCLUIDOS = ['resolvido', 'fechado', 'cancelado'];

    // Texto gravado por IncidenteController::logAlteracaoSeMudou(); 'alteracao'
    // é o literal de IncidenteDescricao::TIPO_ALTERACAO.
    private const PADRAO_LOG_STATUS = "/^Campo 'Status' alterado de '([a-z_]+)' para '([a-z_]+)'/";

    public function up(): void
    {
        $this->marcarEventosAnterioresAFase1();
        $this->criarAbertos();
        $this->criarCanceladosEReabertosDoLog();
        $this->criarCanceladosSemLog();
    }

    private function marcarEventosAnterioresAFase1(): void
    {
        DB::table('incidente_eventos')
            ->whereIn('tipo', self::TIPOS_JA_EXISTENTES)
            ->where('created_at', '<', self::DEPLOY_FASE_1)
            ->update(['retroativo' => true]);
    }

    /**
     * Um `aberto` por incidente que ainda não tem. Autor = quem criou (staff
     * ou cliente do portal; os dois nulos = desconhecido). Grupo do chamado =
     * o atual, só se ele nunca foi encaminhado (senão o inicial é
     * desconhecido: o store() nunca gerou evento de encaminhamento).
     */
    private function criarAbertos(): void
    {
        DB::table('incidentes')->chunkById(500, function ($incidentes) {
            foreach ($incidentes as $incidente) {
                if ($this->temEvento($incidente->id, 'aberto')) {
                    continue;
                }

                $foiEncaminhado = DB::table('incidente_eventos')
                    ->where('incidente_id', $incidente->id)
                    ->where('tipo', 'encaminhado_grupo')
                    ->exists();

                $userId = $this->usuarioExistente($incidente->criado_por_id);

                $this->inserir([
                    'incidente_id' => $incidente->id,
                    'tipo' => 'aberto',
                    'user_id' => $userId,
                    'autor_customer_id' => $incidente->criado_por_customer_id,
                    'autor_grupo_solucao_id' => $this->grupoAtualDoUsuario($userId),
                    'grupo_solucao_id' => $foiEncaminhado ? null : $incidente->grupo_solucao_id,
                    'created_at' => $incidente->created_at,
                ]);
            }
        });
    }

    /**
     * `cancelado` quando o destino logado é cancelado; `reaberto` quando sai
     * de um status concluído para um não concluído. As demais transições não
     * geram nada (resolvido/fechado já existem como eventos). Não recria um
     * evento do mesmo tipo, no mesmo incidente, com o mesmo created_at.
     */
    private function criarCanceladosEReabertosDoLog(): void
    {
        DB::table('incidente_descricoes')
            ->where('tipo', 'alteracao')
            ->where('descricao', 'like', "Campo 'Status' alterado de %")
            ->chunkById(500, function ($logs) {
                foreach ($logs as $log) {
                    // Sem data não há como situar o evento (nem o grupo da época).
                    if ($log->created_at === null || ! preg_match(self::PADRAO_LOG_STATUS, $log->descricao, $m)) {
                        continue;
                    }
                    [, $de, $para] = $m;

                    $tipo = match (true) {
                        $para === 'cancelado' => 'cancelado',
                        in_array($de, self::STATUS_CONCLUIDOS, true) && ! in_array($para, self::STATUS_CONCLUIDOS, true) => 'reaberto',
                        default => null,
                    };

                    if ($tipo === null) {
                        continue;
                    }

                    $jaExiste = DB::table('incidente_eventos')
                        ->where('incidente_id', $log->incidente_id)
                        ->where('tipo', $tipo)
                        ->where('created_at', $log->created_at)
                        ->exists();

                    if ($jaExiste) {
                        continue;
                    }

                    $userId = $this->usuarioExistente($log->user_id);

                    $this->inserir([
                        'incidente_id' => $log->incidente_id,
                        'tipo' => $tipo,
                        'user_id' => $userId,
                        'autor_customer_id' => null,
                        'autor_grupo_solucao_id' => $this->grupoAtualDoUsuario($userId),
                        'grupo_solucao_id' => $this->grupoDoIncidenteEm($log->incidente_id, $log->created_at),
                        'created_at' => $log->created_at,
                    ]);
                }
            });
    }

    /**
     * Incidente cancelado sem nenhum evento `cancelado` (nem do log): autor
     * desconhecido. Data: concluido_em, senão updated_at, senão created_at.
     */
    private function criarCanceladosSemLog(): void
    {
        DB::table('incidentes')->where('status', 'cancelado')->chunkById(500, function ($incidentes) {
            foreach ($incidentes as $incidente) {
                if ($this->temEvento($incidente->id, 'cancelado')) {
                    continue;
                }

                $this->inserir([
                    'incidente_id' => $incidente->id,
                    'tipo' => 'cancelado',
                    'user_id' => null,
                    'autor_customer_id' => null,
                    'autor_grupo_solucao_id' => null,
                    'grupo_solucao_id' => $incidente->grupo_solucao_id,
                    'created_at' => $incidente->concluido_em ?? $incidente->updated_at ?? $incidente->created_at,
                ]);
            }
        });
    }

    /**
     * Mesma regra do backfill da Fase 1: (1) destino do `encaminhado_grupo`
     * mais recente até o instante; (2) senão, se não houve encaminhamento
     * depois, o grupo atual do incidente; (3) senão null (desconhecido).
     */
    private function grupoDoIncidenteEm(int $incidenteId, string $instante): ?int
    {
        $anterior = DB::table('incidente_eventos')
            ->where('incidente_id', $incidenteId)
            ->where('tipo', 'encaminhado_grupo')
            ->where('created_at', '<=', $instante)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first();

        if ($anterior !== null) {
            // alvo_id pode apontar pra grupo já excluído (a FK quebraria).
            return DB::table('grupos_solucao')->where('id', $anterior->alvo_id)->value('id');
        }

        $temPosterior = DB::table('incidente_eventos')
            ->where('incidente_id', $incidenteId)
            ->where('tipo', 'encaminhado_grupo')
            ->where('created_at', '>', $instante)
            ->exists();

        return $temPosterior
            ? null
            : DB::table('incidentes')->where('id', $incidenteId)->value('grupo_solucao_id');
    }

    private function temEvento(int $incidenteId, string $tipo): bool
    {
        return DB::table('incidente_eventos')->where('incidente_id', $incidenteId)->where('tipo', $tipo)->exists();
    }

    /** O id, se o usuário ainda existe (inclusive soft-deleted); senão null, pra não quebrar a FK. */
    private function usuarioExistente(?int $userId): ?int
    {
        return $userId === null ? null : DB::table('users')->where('id', $userId)->value('id');
    }

    private function grupoAtualDoUsuario(?int $userId): ?int
    {
        return $userId === null ? null : DB::table('users')->where('id', $userId)->value('grupo_solucao_id');
    }

    private function inserir(array $evento): void
    {
        DB::table('incidente_eventos')->insert($evento + [
            'alvo_type' => null,
            'alvo_id' => null,
            'retroativo' => true,
        ]);
    }

    /** Remove o que foi criado e desfaz a marcação (nenhum código ao vivo grava retroativo = true). */
    public function down(): void
    {
        DB::table('incidente_eventos')
            ->where('retroativo', true)
            ->whereIn('tipo', ['aberto', 'cancelado', 'reaberto'])
            ->delete();

        DB::table('incidente_eventos')->where('retroativo', true)->update(['retroativo' => false]);
    }
};
