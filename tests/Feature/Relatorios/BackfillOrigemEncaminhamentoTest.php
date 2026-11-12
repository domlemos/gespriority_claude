<?php

namespace Tests\Feature\Relatorios;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roda o up() da migration que preenche `origem_grupo_id` dos
 * `encaminhado_grupo` já gravados. Os dados são montados com query builder
 * (sem hooks do model), simulando o histórico de antes desta entrega.
 */
class BackfillOrigemEncaminhamentoTest extends TestCase
{
    use RefreshDatabase;

    private function rodarBackfill(): void
    {
        (require base_path('database/migrations/2026_11_13_000000_add_origem_grupo_to_incidente_eventos_table.php'))->up();
    }

    private function incidente(): int
    {
        return Incidente::factory()->create()->id;
    }

    private function evento(int $incidenteId, string $tipo, string $quando, array $colunas = []): int
    {
        return DB::table('incidente_eventos')->insertGetId($colunas + [
            'incidente_id' => $incidenteId,
            'tipo' => $tipo,
            'created_at' => $quando,
        ]);
    }

    private function encaminhamento(int $incidenteId, int $alvoId, string $quando, array $colunas = []): int
    {
        return $this->evento($incidenteId, 'encaminhado_grupo', $quando, $colunas + [
            'alvo_type' => GrupoSolucao::class,
            'alvo_id' => $alvoId,
            'grupo_solucao_id' => $alvoId,
        ]);
    }

    private function lido(int $eventoId): IncidenteEvento
    {
        return IncidenteEvento::query()->findOrFail($eventoId);
    }

    public function test_origem_vem_do_encaminhamento_anterior(): void
    {
        [$n1, $n2, $n3] = GrupoSolucao::factory()->count(3)->create()->all();
        $id = $this->incidente();
        $this->evento($id, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => $n1->id]);
        $primeiro = $this->encaminhamento($id, $n2->id, '2026-10-02 12:00:00');
        // Mesmo instante: desempata pelo id.
        $segundo = $this->encaminhamento($id, $n3->id, '2026-10-02 12:00:00');
        $terceiro = $this->encaminhamento($id, $n1->id, '2026-10-03 12:00:00');

        $this->rodarBackfill();

        $this->assertSame($n1->id, $this->lido($primeiro)->origem_grupo_id);
        $this->assertSame($n2->id, $this->lido($segundo)->origem_grupo_id);
        $this->assertSame($n3->id, $this->lido($terceiro)->origem_grupo_id);
        $this->assertFalse($this->lido($terceiro)->retroativo);
    }

    public function test_encaminhamento_anterior_para_grupo_excluido_marca_retroativo(): void
    {
        [$n1, $n2] = GrupoSolucao::factory()->count(2)->create()->all();
        $id = $this->incidente();
        $this->evento($id, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => $n1->id]);
        $this->encaminhamento($id, 999999, '2026-10-02 12:00:00', ['grupo_solucao_id' => null]);
        $depois = $this->encaminhamento($id, $n2->id, '2026-10-03 12:00:00');

        $this->rodarBackfill();

        $this->assertNull($this->lido($depois)->origem_grupo_id);
        $this->assertTrue($this->lido($depois)->retroativo);
    }

    public function test_origem_vem_do_grupo_da_abertura(): void
    {
        [$n1, $n2] = GrupoSolucao::factory()->count(2)->create()->all();
        $id = $this->incidente();
        $this->evento($id, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => $n1->id, 'retroativo' => true]);
        $evento = $this->encaminhamento($id, $n2->id, '2026-10-02 12:00:00');

        $this->rodarBackfill();

        $this->assertSame($n1->id, $this->lido($evento)->origem_grupo_id);
        $this->assertFalse($this->lido($evento)->retroativo);
    }

    public function test_portal_fica_nulo_sem_retroativo(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $id = $this->incidente();
        $this->evento($id, 'aberto', '2026-10-01 12:00:00', [
            'autor_customer_id' => Customer::factory()->create()->id,
            'grupo_solucao_id' => null,
            'retroativo' => true,
        ]);
        $evento = $this->encaminhamento($id, $n1->id, '2026-10-02 12:00:00');

        $this->rodarBackfill();

        $this->assertNull($this->lido($evento)->origem_grupo_id);
        $this->assertFalse($this->lido($evento)->retroativo);
    }

    public function test_abertura_reconstruida_sem_grupo_marca_retroativo(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $id = $this->incidente();
        $this->evento($id, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => null, 'retroativo' => true]);
        $evento = $this->encaminhamento($id, $n1->id, '2026-10-02 12:00:00');

        $this->rodarBackfill();

        $this->assertNull($this->lido($evento)->origem_grupo_id);
        $this->assertTrue($this->lido($evento)->retroativo);
    }

    public function test_abertura_ao_vivo_sem_grupo_fica_nulo_sem_retroativo(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $id = $this->incidente();
        $this->evento($id, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => null, 'retroativo' => false]);
        $evento = $this->encaminhamento($id, $n1->id, '2026-10-02 12:00:00');

        $this->rodarBackfill();

        $this->assertNull($this->lido($evento)->origem_grupo_id);
        $this->assertFalse($this->lido($evento)->retroativo);
    }

    public function test_sem_abertura_marca_retroativo(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $id = $this->incidente();
        $evento = $this->encaminhamento($id, $n1->id, '2026-10-02 12:00:00');

        $this->rodarBackfill();

        $this->assertNull($this->lido($evento)->origem_grupo_id);
        $this->assertTrue($this->lido($evento)->retroativo);
    }

    public function test_nao_toca_encaminhamento_com_origem_preenchida(): void
    {
        [$n1, $n2, $n3] = GrupoSolucao::factory()->count(3)->create()->all();
        $id = $this->incidente();
        $this->evento($id, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => $n1->id]);
        $evento = $this->encaminhamento($id, $n2->id, '2026-10-02 12:00:00', ['origem_grupo_id' => $n3->id]);

        $this->rodarBackfill();

        $this->assertSame($n3->id, $this->lido($evento)->origem_grupo_id);
    }

    public function test_rodar_duas_vezes_nao_muda_nada(): void
    {
        [$n1, $n2] = GrupoSolucao::factory()->count(2)->create()->all();
        $a = $this->incidente();
        $this->evento($a, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => $n1->id]);
        $this->encaminhamento($a, $n2->id, '2026-10-02 12:00:00');
        $this->encaminhamento($a, $n1->id, '2026-10-03 12:00:00');
        $b = $this->incidente();
        $this->evento($b, 'aberto', '2026-10-01 12:00:00', ['grupo_solucao_id' => null, 'retroativo' => true]);
        $this->encaminhamento($b, $n2->id, '2026-10-02 12:00:00');
        $c = $this->incidente();
        $this->encaminhamento($c, $n1->id, '2026-10-02 12:00:00');

        $this->rodarBackfill();
        $primeira = DB::table('incidente_eventos')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
        $this->rodarBackfill();
        $segunda = DB::table('incidente_eventos')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();

        $this->assertSame($primeira, $segunda);
    }
}
