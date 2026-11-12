<?php

namespace Tests\Feature\Relatorios;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Origem gravada no `encaminhado_grupo` e evento `removido_grupo`, via `PUT /api/incidentes/{id}`. */
class IncidenteEventoOrigemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $role = Role::factory()->create(['slug' => 'admin']);
        foreach (['tickets.manage', 'relatorios.view'] as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return [$user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken, $user];
    }

    private function incidente(?GrupoSolucao $grupo): Incidente
    {
        return Incidente::factory()->create([
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'media'])->id,
            'prioridade' => 'media',
            'status' => 'em_andamento',
            'grupo_solucao_id' => $grupo?->id,
        ]);
    }

    private function mudarGrupo(string $token, Incidente $incidente, ?int $grupoId): void
    {
        $this->putJson("/api/incidentes/{$incidente->id}", ['grupo_solucao_id' => $grupoId], ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    private function eventos(Incidente $incidente, string $tipo)
    {
        return IncidenteEvento::query()->where('incidente_id', $incidente->id)->where('tipo', $tipo)->get();
    }

    public function test_encaminhar_grava_grupo_de_origem(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        [$token] = $this->admin();
        $incidente = $this->incidente($n1);

        $this->mudarGrupo($token, $incidente, $n2->id);

        $evento = $this->eventos($incidente, IncidenteEvento::TIPO_ENCAMINHADO_GRUPO)->sole();
        $this->assertSame($n1->id, $evento->origem_grupo_id);
        $this->assertSame($n2->id, $evento->alvo_id);
        $this->assertSame($n2->id, $evento->grupo_solucao_id);
    }

    public function test_encaminhar_chamado_sem_grupo_grava_origem_nula(): void
    {
        $n2 = GrupoSolucao::factory()->create();
        [$token] = $this->admin();
        $incidente = $this->incidente(null);

        $this->mudarGrupo($token, $incidente, $n2->id);

        $evento = $this->eventos($incidente, IncidenteEvento::TIPO_ENCAMINHADO_GRUPO)->sole();
        $this->assertNull($evento->origem_grupo_id);
        $this->assertSame($n2->id, $evento->alvo_id);
    }

    public function test_remover_grupo_gera_removido_grupo_com_origem(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        [$token, $admin] = $this->admin();
        $incidente = $this->incidente($n1);
        $descricoesAntes = $incidente->descricoes()->count();

        $this->mudarGrupo($token, $incidente, null);

        $evento = $this->eventos($incidente, IncidenteEvento::TIPO_REMOVIDO_GRUPO)->sole();
        $this->assertSame($n1->id, $evento->origem_grupo_id);
        $this->assertNull($evento->alvo_type);
        $this->assertNull($evento->alvo_id);
        $this->assertNull($evento->grupo_solucao_id);
        $this->assertSame($admin->id, $evento->user_id);
        $this->assertCount(0, $this->eventos($incidente, IncidenteEvento::TIPO_ENCAMINHADO_GRUPO));
        // Sem entrada nova no feed.
        $this->assertSame($descricoesAntes, $incidente->descricoes()->count());
    }

    public function test_responsavel_continua_sem_origem(): void
    {
        [$token, $admin] = $this->admin();
        $incidente = $this->incidente(null);

        $this->putJson("/api/incidentes/{$incidente->id}", ['responsavel_id' => $admin->id], ['Authorization' => "Bearer {$token}"])->assertOk();

        $evento = $this->eventos($incidente, IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL)->sole();
        $this->assertNull($evento->origem_grupo_id);
        $this->assertSame($admin->id, $evento->alvo_id);
    }

    public function test_relatorios_existentes_ignoram_removido_grupo(): void
    {
        $n1 = GrupoSolucao::factory()->create();
        $n2 = GrupoSolucao::factory()->create();
        [$token] = $this->admin();
        $header = ['Authorization' => "Bearer {$token}"];
        $incidente = $this->incidente($n1);
        $this->mudarGrupo($token, $incidente, $n2->id);
        $url = '/api/relatorios/incidentes?contar=encaminhamentos&agrupar_por=para_grupo';

        $antes = $this->getJson($url, $header)->assertOk()->json('totais.total');
        $this->mudarGrupo($token, $incidente, null);
        $depois = $this->getJson($url, $header)->assertOk()->json('totais.total');

        $this->assertSame(1, $antes);
        $this->assertSame($antes, $depois);
    }
}
