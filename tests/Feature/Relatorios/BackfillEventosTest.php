<?php

namespace Tests\Feature\Relatorios;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roda o up() da migration de backfill contra dados montados aqui. Os dados
 * são gravados com query builder (sem hooks do model), simulando o histórico
 * de antes desta entrega.
 */
class BackfillEventosTest extends TestCase
{
    use RefreshDatabase;

    private function rodarBackfill(): void
    {
        (require base_path('database/migrations/2026_11_12_100001_backfill_eventos_abertura_cancelamento.php'))->up();
    }

    private function incidente(array $colunas = []): int
    {
        $id = Incidente::factory()->create()->id;

        DB::table('incidentes')->where('id', $id)->update($colunas + ['created_at' => '2026-10-01 12:00:00']);

        return $id;
    }

    private function evento(int $incidenteId, string $tipo, array $colunas = []): int
    {
        return DB::table('incidente_eventos')->insertGetId($colunas + [
            'incidente_id' => $incidenteId,
            'tipo' => $tipo,
            'created_at' => '2026-10-05 12:00:00',
        ]);
    }

    private function logStatus(int $incidenteId, int $userId, string $de, string $para, string $quando): void
    {
        DB::table('incidente_descricoes')->insert([
            'incidente_id' => $incidenteId,
            'user_id' => $userId,
            'tipo' => 'alteracao',
            'descricao' => "Campo 'Status' alterado de '{$de}' para '{$para}' às 10:00 do dia 01/10/2026.",
            'created_at' => $quando,
            'updated_at' => $quando,
        ]);
    }

    private function eventos(int $incidenteId, string $tipo)
    {
        return IncidenteEvento::query()->where('incidente_id', $incidenteId)->where('tipo', $tipo)->orderBy('created_at')->get();
    }

    public function test_cria_aberto_para_incidente_de_agente_com_grupo_atual_e_retroativo(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        $agente = User::factory()->create(['grupo_solucao_id' => $n1->id]);
        $id = $this->incidente(['criado_por_id' => $agente->id, 'grupo_solucao_id' => $n2->id]);

        $this->rodarBackfill();

        $abertos = $this->eventos($id, 'aberto');
        $this->assertCount(1, $abertos);
        $aberto = $abertos->first();
        $this->assertSame($agente->id, $aberto->user_id);
        $this->assertNull($aberto->autor_customer_id);
        $this->assertSame($n1->id, $aberto->autor_grupo_solucao_id);
        $this->assertSame($n2->id, $aberto->grupo_solucao_id);
        $this->assertTrue($aberto->retroativo);
        $this->assertSame('2026-10-01 12:00:00', $aberto->created_at->format('Y-m-d H:i:s'));
    }

    public function test_cria_aberto_do_portal_com_autor_cliente(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $cliente = Customer::factory()->create();
        $id = $this->incidente(['criado_por_customer_id' => $cliente->id, 'grupo_solucao_id' => $n1->id]);

        $this->rodarBackfill();

        $aberto = $this->eventos($id, 'aberto')->sole();
        $this->assertNull($aberto->user_id);
        $this->assertSame($cliente->id, $aberto->autor_customer_id);
        $this->assertNull($aberto->autor_grupo_solucao_id);
        $this->assertSame($n1->id, $aberto->grupo_solucao_id);
        $this->assertTrue($aberto->retroativo);
    }

    public function test_incidente_sem_criador_gera_aberto_sem_autor(): void
    {
        $id = $this->incidente();

        $this->rodarBackfill();

        $aberto = $this->eventos($id, 'aberto')->sole();
        $this->assertNull($aberto->user_id);
        $this->assertNull($aberto->autor_customer_id);
        $this->assertNull($aberto->autor_grupo_solucao_id);
        $this->assertTrue($aberto->retroativo);
    }

    public function test_aberto_com_encaminhamento_posterior_fica_sem_grupo_inicial(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        $agente = User::factory()->create(['grupo_solucao_id' => $n1->id]);
        $id = $this->incidente(['criado_por_id' => $agente->id, 'grupo_solucao_id' => $n2->id]);
        $this->evento($id, 'encaminhado_grupo', [
            'user_id' => $agente->id, 'alvo_type' => GrupoSolucao::class, 'alvo_id' => $n2->id,
        ]);

        $this->rodarBackfill();

        $aberto = $this->eventos($id, 'aberto')->sole();
        $this->assertNull($aberto->grupo_solucao_id);
        $this->assertSame($n1->id, $aberto->autor_grupo_solucao_id);
    }

    public function test_cancelado_e_reaberto_vem_do_log_de_status_com_autor_do_log(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        $n3 = GrupoSolucao::factory()->create();
        $criador = User::factory()->create(['grupo_solucao_id' => $n1->id]);
        $quemCancelou = User::factory()->create(['grupo_solucao_id' => $n2->id]);
        $quemReabriu = User::factory()->create(['grupo_solucao_id' => $n3->id]);
        $id = $this->incidente(['criado_por_id' => $criador->id, 'grupo_solucao_id' => $n3->id, 'status' => 'em_andamento']);

        // Encaminhado pro N2 antes do cancelamento e pro N3 depois dele.
        $this->evento($id, 'encaminhado_grupo', [
            'user_id' => $criador->id, 'alvo_type' => GrupoSolucao::class, 'alvo_id' => $n2->id, 'created_at' => '2026-10-02 12:00:00',
        ]);
        $this->evento($id, 'encaminhado_grupo', [
            'user_id' => $quemReabriu->id, 'alvo_type' => GrupoSolucao::class, 'alvo_id' => $n3->id, 'created_at' => '2026-10-04 12:00:00',
        ]);
        $this->logStatus($id, $quemCancelou->id, 'em_andamento', 'cancelado', '2026-10-03 12:00:00');
        $this->logStatus($id, $quemReabriu->id, 'cancelado', 'em_andamento', '2026-10-05 12:00:00');
        // Transições que não geram nada aqui.
        $this->logStatus($id, $quemReabriu->id, 'em_andamento', 'pendente', '2026-10-06 12:00:00');
        $this->logStatus($id, $quemReabriu->id, 'pendente', 'resolvido', '2026-10-07 12:00:00');
        $this->logStatus($id, $quemReabriu->id, 'resolvido', 'fechado', '2026-10-08 12:00:00');
        $this->logStatus($id, $quemReabriu->id, 'fechado', 'em_andamento', '2026-10-09 12:00:00');
        DB::table('incidente_descricoes')->insert([
            'incidente_id' => $id, 'user_id' => $quemReabriu->id, 'tipo' => 'comentario',
            'descricao' => "Campo 'Status' alterado de 'em_andamento' para 'cancelado' às 10:00 do dia 01/10/2026.",
            'created_at' => '2026-10-10 12:00:00', 'updated_at' => '2026-10-10 12:00:00',
        ]);

        $this->rodarBackfill();

        $cancelado = $this->eventos($id, 'cancelado')->sole();
        $this->assertSame($quemCancelou->id, $cancelado->user_id);
        $this->assertSame($n2->id, $cancelado->autor_grupo_solucao_id);
        $this->assertSame($n2->id, $cancelado->grupo_solucao_id);
        $this->assertSame('2026-10-03 12:00:00', $cancelado->created_at->format('Y-m-d H:i:s'));
        $this->assertTrue($cancelado->retroativo);

        $reabertos = $this->eventos($id, 'reaberto');
        $this->assertCount(2, $reabertos);
        $this->assertSame($quemReabriu->id, $reabertos[0]->user_id);
        $this->assertSame($n3->id, $reabertos[0]->autor_grupo_solucao_id);
        $this->assertSame($n3->id, $reabertos[0]->grupo_solucao_id);
        $this->assertSame('2026-10-05 12:00:00', $reabertos[0]->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-09 12:00:00', $reabertos[1]->created_at->format('Y-m-d H:i:s'));
        $this->assertTrue($reabertos[0]->retroativo);

        $this->assertCount(0, $this->eventos($id, 'resolvido'));
        $this->assertCount(0, $this->eventos($id, 'fechado'));
    }

    public function test_cancelado_do_log_usa_grupo_atual_quando_nao_houve_encaminhamento(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $agente = User::factory()->create(['grupo_solucao_id' => $n1->id]);
        $id = $this->incidente(['grupo_solucao_id' => $n1->id, 'status' => 'cancelado', 'concluido_em' => '2026-10-03 12:00:00']);
        $this->logStatus($id, $agente->id, 'aberto', 'cancelado', '2026-10-03 12:00:00');

        $this->rodarBackfill();

        $cancelado = $this->eventos($id, 'cancelado')->sole();
        $this->assertSame($agente->id, $cancelado->user_id);
        $this->assertSame($n1->id, $cancelado->grupo_solucao_id);
    }

    public function test_cancelado_sem_log_usa_concluido_em_e_autor_nulo(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $comConcluido = $this->incidente(['grupo_solucao_id' => $n1->id, 'status' => 'cancelado', 'concluido_em' => '2026-10-03 12:00:00']);
        $semConcluido = $this->incidente(['status' => 'cancelado', 'concluido_em' => null, 'updated_at' => '2026-10-04 12:00:00']);

        $this->rodarBackfill();

        $cancelado = $this->eventos($comConcluido, 'cancelado')->sole();
        $this->assertNull($cancelado->user_id);
        $this->assertNull($cancelado->autor_customer_id);
        $this->assertNull($cancelado->autor_grupo_solucao_id);
        $this->assertSame($n1->id, $cancelado->grupo_solucao_id);
        $this->assertSame('2026-10-03 12:00:00', $cancelado->created_at->format('Y-m-d H:i:s'));
        $this->assertTrue($cancelado->retroativo);

        $cancelado2 = $this->eventos($semConcluido, 'cancelado')->sole();
        $this->assertNull($cancelado2->grupo_solucao_id);
        $this->assertSame('2026-10-04 12:00:00', $cancelado2->created_at->format('Y-m-d H:i:s'));
    }

    public function test_cancelado_sem_log_e_sem_concluido_nem_updated_at_usa_created_at(): void
    {
        $id = $this->incidente(['status' => 'cancelado', 'concluido_em' => null, 'updated_at' => null]);

        $this->rodarBackfill();

        $cancelado = $this->eventos($id, 'cancelado')->sole();
        $this->assertSame('2026-10-01 12:00:00', $cancelado->created_at->format('Y-m-d H:i:s'));
    }

    public function test_log_de_status_sem_created_at_e_ignorado(): void
    {
        $agente = User::factory()->create();
        $id = $this->incidente(['status' => 'em_andamento']);
        DB::table('incidente_descricoes')->insert([
            'incidente_id' => $id,
            'user_id' => $agente->id,
            'tipo' => 'alteracao',
            'descricao' => "Campo 'Status' alterado de 'cancelado' para 'em_andamento' às 10:00 do dia 01/10/2026.",
            'created_at' => null,
            'updated_at' => null,
        ]);

        $this->rodarBackfill();

        $this->assertCount(0, $this->eventos($id, 'reaberto'));
        $this->assertCount(1, $this->eventos($id, 'aberto'));
    }

    public function test_marca_eventos_anteriores_a_fase_1_como_retroativos(): void
    {
        $agente = User::factory()->create();
        $id = $this->incidente();
        $antes = [];
        $depois = [];
        foreach (['resolvido', 'fechado', 'encaminhado_grupo', 'encaminhado_responsavel'] as $tipo) {
            $antes[] = $this->evento($id, $tipo, ['user_id' => $agente->id, 'created_at' => '2026-11-11 14:49:33']);
            $depois[] = $this->evento($id, $tipo, ['user_id' => $agente->id, 'created_at' => '2026-11-11 14:49:35']);
        }

        $this->rodarBackfill();

        foreach ($antes as $eventoId) {
            $this->assertTrue(IncidenteEvento::find($eventoId)->retroativo, "evento {$eventoId} antes da Fase 1");
        }
        foreach ($depois as $eventoId) {
            $this->assertFalse(IncidenteEvento::find($eventoId)->retroativo, "evento {$eventoId} depois da Fase 1");
        }
    }

    public function test_rodar_duas_vezes_nao_duplica(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $agente = User::factory()->create(['grupo_solucao_id' => $n1->id]);
        $comLog = $this->incidente(['criado_por_id' => $agente->id, 'status' => 'cancelado', 'concluido_em' => '2026-10-03 12:00:00']);
        $this->logStatus($comLog, $agente->id, 'aberto', 'cancelado', '2026-10-03 12:00:00');
        $this->logStatus($comLog, $agente->id, 'cancelado', 'aberto', '2026-10-04 12:00:00');
        $this->logStatus($comLog, $agente->id, 'aberto', 'cancelado', '2026-10-05 12:00:00');
        $semLog = $this->incidente(['status' => 'cancelado', 'concluido_em' => '2026-10-03 12:00:00']);
        // Já tem `aberto` (gravado ao vivo): não ganha outro.
        $comAberto = $this->incidente(['criado_por_id' => $agente->id]);
        $this->evento($comAberto, 'aberto', ['user_id' => $agente->id, 'created_at' => '2026-10-01 12:00:00']);

        $this->rodarBackfill();
        $this->rodarBackfill();

        $this->assertCount(1, $this->eventos($comLog, 'aberto'));
        $this->assertCount(2, $this->eventos($comLog, 'cancelado'));
        $this->assertCount(1, $this->eventos($comLog, 'reaberto'));
        $this->assertCount(1, $this->eventos($semLog, 'aberto'));
        $this->assertCount(1, $this->eventos($semLog, 'cancelado'));
        $aberto = $this->eventos($comAberto, 'aberto')->sole();
        $this->assertFalse($aberto->retroativo);
        $this->assertSame(7, IncidenteEvento::query()->count());
    }
}
