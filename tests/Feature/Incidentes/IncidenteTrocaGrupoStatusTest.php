<?php

namespace Tests\Feature\Incidentes;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteTrocaGrupoStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin: enxerga todos os grupos (a regra aqui é a troca de grupo, não a visibilidade).
        $role = Role::factory()->create(['slug' => 'admin']);
        foreach (['tickets.view', 'tickets.manage', 'tickets.edit_all'] as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }

        $this->staff = User::factory()->create();
        $this->staff->roles()->attach($role);
        $this->token = $this->staff->createToken('spa', ['staff'], now()->addHour())->plainTextToken;
    }

    private function incidente(string $status, ?GrupoSolucao $grupo = null): Incidente
    {
        $incidente = Incidente::factory()->create([
            'status' => $status,
            'grupo_solucao_id' => ($grupo ?? GrupoSolucao::factory()->create())->id,
        ]);

        if (in_array($status, Incidente::STATUS_CONCLUIDOS, true)) {
            $incidente->forceFill(['concluido_em' => now()->subHour()])->saveQuietly();
        }

        return $incidente;
    }

    private function atualizar(Incidente $incidente, array $payload)
    {
        return $this->putJson("/api/incidentes/{$incidente->id}", $payload, ['Authorization' => "Bearer {$this->token}"]);
    }

    public function test_changing_group_reopens_active_and_resolved_tickets(): void
    {
        foreach (['aberto', 'em_andamento', 'pendente', 'resolvido'] as $status) {
            $incidente = $this->incidente($status);
            $novo = GrupoSolucao::factory()->create();

            $this->atualizar($incidente, ['grupo_solucao_id' => $novo->id])->assertOk()->assertJsonPath('data.status', 'aberto');

            $fresh = $incidente->fresh();
            $this->assertSame('aberto', $fresh->status, "a partir de {$status}");
            $this->assertSame($novo->id, $fresh->grupo_solucao_id);
        }
    }

    public function test_resolved_ticket_moved_to_another_group_goes_through_the_reopen_flow(): void
    {
        $incidente = $this->incidente('resolvido');

        $this->atualizar($incidente, ['grupo_solucao_id' => GrupoSolucao::factory()->create()->id])->assertOk();

        $fresh = $incidente->fresh();
        $this->assertNull($fresh->concluido_em);
        $this->assertSame(1, IncidenteEvento::query()->where('incidente_id', $incidente->id)->where('tipo', IncidenteEvento::TIPO_REABERTO)->count());
        $this->assertTrue($fresh->descricoes()->where('tipo', 'alteracao')->where('descricao', 'like', "Campo 'Status' alterado de 'resolvido' para 'aberto'%")->exists());
    }

    public function test_closed_and_cancelled_tickets_keep_their_status(): void
    {
        foreach (['fechado', 'cancelado'] as $status) {
            $incidente = $this->incidente($status);
            $novo = GrupoSolucao::factory()->create();

            $this->atualizar($incidente, ['grupo_solucao_id' => $novo->id])->assertOk();

            $fresh = $incidente->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame($novo->id, $fresh->grupo_solucao_id);
        }
    }

    public function test_group_change_wins_over_a_status_sent_in_the_same_request(): void
    {
        $incidente = $this->incidente('em_andamento');

        $this->atualizar($incidente, [
            'grupo_solucao_id' => GrupoSolucao::factory()->create()->id,
            'status' => 'resolvido',
        ])->assertOk();

        $this->assertSame('aberto', $incidente->fresh()->status);
    }

    public function test_group_change_wins_over_closing_and_no_closing_message_is_required(): void
    {
        $incidente = $this->incidente('em_andamento');

        $this->atualizar($incidente, [
            'grupo_solucao_id' => GrupoSolucao::factory()->create()->id,
            'status' => 'fechado',
        ])->assertOk();

        $this->assertSame('aberto', $incidente->fresh()->status);
        $this->assertFalse($incidente->descricoes()->where('tipo', 'encerramento')->exists());
    }

    public function test_removing_the_group_also_reopens(): void
    {
        $incidente = $this->incidente('resolvido');

        $this->atualizar($incidente, ['grupo_solucao_id' => null])->assertOk();

        $fresh = $incidente->fresh();
        $this->assertNull($fresh->grupo_solucao_id);
        $this->assertSame('aberto', $fresh->status);
    }

    public function test_resending_the_same_group_does_not_change_status(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $incidente = $this->incidente('resolvido', $grupo);

        // O formulário do staff reenvia todos os campos, inclusive o grupo
        // atual (às vezes como string): sem troca real, nada muda.
        $this->atualizar($incidente, ['grupo_solucao_id' => (string) $grupo->id, 'status' => 'resolvido'])->assertOk();

        $this->assertSame('resolvido', $incidente->fresh()->status);
    }

    public function test_changing_only_the_assignee_does_not_change_status(): void
    {
        $incidente = $this->incidente('em_andamento');

        $this->atualizar($incidente, ['responsavel_id' => User::factory()->create()->id])->assertOk();

        $this->assertSame('em_andamento', $incidente->fresh()->status);
    }
}
