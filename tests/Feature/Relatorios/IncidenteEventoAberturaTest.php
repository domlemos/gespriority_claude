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

class IncidenteEventoAberturaTest extends TestCase
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

    private function abrirComoAgente(string $token, GrupoSolucao $destino)
    {
        return $this->postJson('/api/incidentes', [
            'customer_id' => Customer::factory()->create()->id,
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'alta'])->id,
            'grupo_solucao_id' => $destino->id,
            'titulo' => 'Impressora não liga',
            'descricao' => 'Nada acontece.',
            'origem' => 'telefone',
        ], ['Authorization' => "Bearer {$token}"]);
    }

    public function test_store_interno_grava_evento_aberto_com_grupo_do_autor(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        [$token, $agente] = $this->agente($n1);

        $id = $this->abrirComoAgente($token, $n2)->assertCreated()->json('data.id');

        $eventos = IncidenteEvento::where('incidente_id', $id)->where('tipo', 'aberto')->get();
        $this->assertCount(1, $eventos);
        $evento = $eventos->first();
        $this->assertSame($agente->id, $evento->user_id);
        $this->assertSame($n1->id, $evento->autor_grupo_solucao_id);
        $this->assertSame($n2->id, $evento->grupo_solucao_id);
        $this->assertNull($evento->autor_customer_id);
        $this->assertFalse($evento->retroativo);
        $this->assertTrue(Incidente::find($id)->created_at->equalTo($evento->created_at));
    }

    public function test_portal_grava_evento_aberto_com_autor_cliente(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;

        $id = $this->postJson('/api/customer/incidentes', [
            'titulo' => 'Sistema fora do ar',
            'descricao' => 'Desde as 9h.',
        ], ['Authorization' => "Bearer {$token}"])->assertCreated()->json('data.id');

        $evento = IncidenteEvento::where('incidente_id', $id)->where('tipo', 'aberto')->sole();
        $this->assertNull($evento->user_id);
        $this->assertSame($customer->id, $evento->autor_customer_id);
        $this->assertNull($evento->autor_grupo_solucao_id);
        // O portal não escolhe grupo: a triagem encaminha depois (`encaminhado_grupo`).
        $this->assertNull($evento->grupo_solucao_id);
        $this->assertTrue(Incidente::find($id)->created_at->equalTo($evento->created_at));
    }

    public function test_trocar_de_grupo_depois_nao_altera_o_evento(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        [$token, $agente] = $this->agente($n1);

        $id = $this->abrirComoAgente($token, $n2)->assertCreated()->json('data.id');
        $agente->update(['grupo_solucao_id' => $n2->id]);

        $evento = IncidenteEvento::where('incidente_id', $id)->where('tipo', 'aberto')->sole();
        $this->assertSame($n1->id, $evento->fresh()->autor_grupo_solucao_id);
    }

    public function test_salvar_de_novo_nao_duplica_aberto(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        [$token] = $this->agente($n1);

        $id = $this->abrirComoAgente($token, $n1)->assertCreated()->json('data.id');
        Incidente::find($id)->update(['titulo' => 'Outro título']);

        $this->assertSame(1, IncidenteEvento::where('incidente_id', $id)->where('tipo', 'aberto')->count());
    }

    public function test_incidente_sem_criador_nao_gera_aberto(): void
    {
        $incidente = Incidente::factory()->create();

        $this->assertSame(0, IncidenteEvento::where('incidente_id', $incidente->id)->count());
    }
}
