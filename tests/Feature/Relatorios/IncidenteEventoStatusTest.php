<?php

namespace Tests\Feature\Relatorios;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteEventoStatusTest extends TestCase
{
    use RefreshDatabase;

    private function agente(GrupoSolucao $grupo): array
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => 'tickets.manage'], ['name' => 'tickets.manage']));
        $user = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $user->roles()->attach($role);

        return [$user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken, $user];
    }

    private function incidente(string $status, ?GrupoSolucao $grupo = null): Incidente
    {
        return Incidente::factory()->create([
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'media'])->id,
            'prioridade' => 'media',
            'status' => $status,
            'grupo_solucao_id' => $grupo?->id,
        ]);
    }

    private function mudar(string $token, Incidente|int $incidente, string $status)
    {
        $id = $incidente instanceof Incidente ? $incidente->id : $incidente;
        $payload = ['status' => $status];
        if ($status === 'fechado') {
            $payload['mensagem_encerramento'] = 'Encerrado.';
        }

        return $this->putJson("/api/incidentes/{$id}", $payload, ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    private function tipos(int $incidenteId): array
    {
        return IncidenteEvento::where('incidente_id', $incidenteId)->orderBy('id')->pluck('tipo')->all();
    }

    public function test_cancelar_grava_evento_cancelado_com_grupo_do_autor(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        [$token, $agente] = $this->agente($n2);
        $agente->gruposVisiveisExtra()->attach($n1);
        $incidente = $this->incidente('em_andamento', $n1);

        $this->mudar($token, $incidente, 'cancelado');

        $evento = IncidenteEvento::where('incidente_id', $incidente->id)->where('tipo', 'cancelado')->sole();
        $this->assertSame($agente->id, $evento->user_id);
        $this->assertSame($n2->id, $evento->autor_grupo_solucao_id);
        $this->assertSame($n1->id, $evento->grupo_solucao_id);
    }

    public function test_reabrir_grava_reaberto(): void
    {
        [$token] = $this->agente(GrupoSolucao::factory()->create());
        $resolvido = $this->incidente('resolvido');
        $cancelado = $this->incidente('cancelado');

        $this->mudar($token, $resolvido, 'em_andamento');
        $this->mudar($token, $cancelado, 'aberto');

        $this->assertSame(['reaberto'], $this->tipos($resolvido->id));
        $this->assertSame(['reaberto'], $this->tipos($cancelado->id));
    }

    public function test_resolvido_para_fechado_gera_so_fechado(): void
    {
        [$token] = $this->agente(GrupoSolucao::factory()->create());
        $incidente = $this->incidente('resolvido');

        $this->mudar($token, $incidente, 'fechado');

        $this->assertSame(['fechado'], $this->tipos($incidente->id));
    }

    public function test_status_igual_nao_gera_evento(): void
    {
        [$token] = $this->agente(GrupoSolucao::factory()->create());
        $incidente = $this->incidente('cancelado');

        $this->mudar($token, $incidente, 'cancelado');

        $this->assertSame([], $this->tipos($incidente->id));
    }

    public function test_ciclo_completo(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        [$token] = $this->agente($grupo);

        $id = $this->postJson('/api/incidentes', [
            'customer_id' => Customer::factory()->create()->id,
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'alta'])->id,
            'grupo_solucao_id' => $grupo->id,
            'titulo' => 'Impressora não liga',
            'descricao' => 'Nada acontece.',
            'origem' => 'telefone',
        ], ['Authorization' => "Bearer {$token}"])->assertCreated()->json('data.id');

        foreach (['resolvido', 'em_andamento', 'resolvido', 'fechado'] as $status) {
            $this->mudar($token, $id, $status);
        }

        $this->assertSame(['aberto', 'resolvido', 'reaberto', 'resolvido', 'fechado'], $this->tipos($id));
    }
}
