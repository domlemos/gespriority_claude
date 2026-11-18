<?php

namespace Tests\Feature\I18n;

use App\Exports\RelatorioIncidentesExport;
use App\Models\AuditLog;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** Rótulos de relatório, auditoria e planilha: PT sem header, EN com `Accept-Language: en`. */
class RotulosTraduzidosTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $slugs, bool $admin = false): array
    {
        $role = Role::factory()->create($admin ? ['slug' => 'admin'] : []);
        foreach ($slugs as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return ['Authorization' => 'Bearer '.$user->createToken('spa', ['staff'], now()->addHour())->plainTextToken];
    }

    private function chamadoSemGrupo(): void
    {
        $n1 = GrupoSolucao::factory()->create(['nome' => 'N1']);
        $n2 = GrupoSolucao::factory()->create(['nome' => 'N2']);
        $agente = User::factory()->create(['grupo_solucao_id' => $n1->id]);
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $n2->id]);

        $evento = fn (string $tipo, string $quando, array $extra) => IncidenteEvento::query()->forceCreate([
            'incidente_id' => $incidente->id, 'tipo' => $tipo, 'created_at' => $quando,
            'user_id' => null, 'grupo_solucao_id' => null, 'autor_grupo_solucao_id' => null, ...$extra,
        ]);
        $evento(IncidenteEvento::TIPO_ABERTO, '2026-10-10 12:00:00', ['user_id' => $agente->id, 'autor_grupo_solucao_id' => $n1->id, 'grupo_solucao_id' => $n1->id]);
        $evento(IncidenteEvento::TIPO_REMOVIDO_GRUPO, '2026-10-11 12:00:00', ['origem_grupo_id' => $n1->id]);
        $evento(IncidenteEvento::TIPO_ENCAMINHADO_GRUPO, '2026-10-12 12:00:00', [
            'alvo_type' => GrupoSolucao::class, 'alvo_id' => $n2->id, 'origem_grupo_id' => null, 'grupo_solucao_id' => $n2->id,
        ]);
    }

    public function test_mapa_de_fluxos_traduz_rotulos_e_titulo(): void
    {
        $this->chamadoSemGrupo();
        $h = $this->headers(['relatorios.view'], admin: true);
        $url = '/api/relatorios/fluxos?data_inicio=2026-10-01&data_fim=2026-10-31';

        $pt = $this->getJson($url, $h)->assertOk();
        $this->assertSame('Sem grupo', collect($pt->json('nos'))->firstWhere('chave', 'sem_grupo')['rotulo']);
        $this->assertStringStartsWith('Mapa de fluxos — de 01/10/2026 a 31/10/2026', $pt->json('titulo'));

        $this->app['auth']->forgetGuards();
        $en = $this->getJson($url, [...$h, 'Accept-Language' => 'en'])->assertOk();
        $no = collect($en->json('nos'))->firstWhere('chave', 'sem_grupo');
        $this->assertSame('No group', $no['rotulo']);
        $this->assertSame('sem_grupo', $no['tipo']); // identificador intacto
        $this->assertStringStartsWith('Flow map — from 10/01/2026 to 10/31/2026', $en->json('titulo'));
        $this->assertNotSame($pt->json('legenda'), $en->json('legenda'));
    }

    public function test_auditoria_traduz_acao_e_tipo_da_entidade(): void
    {
        AuditLog::query()->forceCreate([
            'acao' => 'login', 'ator_type' => 'user', 'ator_id' => 1, 'ator_nome' => 'Ana',
            'entidade_type' => 'grupo_solucao', 'entidade_id' => 3, 'entidade_label' => 'N1',
            'created_at' => '2026-11-10 15:00:00',
        ]);
        $h = $this->headers(['auditoria.view']);
        $url = '/api/audit-logs?data_inicio=2026-11-01&data_fim=2026-11-11';

        $pt = $this->getJson($url, $h)->assertOk();
        $this->assertSame('Entrou no sistema', $pt->json('data.0.acao_rotulo'));
        $this->assertSame('Grupo de Solução', $pt->json('data.0.entidade.type_rotulo'));

        $this->app['auth']->forgetGuards();
        $en = $this->getJson($url, [...$h, 'Accept-Language' => 'en'])->assertOk();
        $this->assertSame('login', $en->json('data.0.acao')); // slug intacto
        $this->assertSame('Signed in', $en->json('data.0.acao_rotulo'));
        $this->assertSame('Solution Group', $en->json('data.0.entidade.type_rotulo'));
        $this->assertSame('N1', $en->json('data.0.entidade.label')); // dado cadastrado

        $this->app['auth']->forgetGuards();
        $opcoes = $this->getJson('/api/audit-logs/opcoes', [...$h, 'Accept-Language' => 'en'])->assertOk();
        $this->assertContains('Signed in', array_column($opcoes->json('acoes'), 'title'));
    }

    public function test_cabecalho_da_planilha_e_traduzido(): void
    {
        Excel::fake();
        $h = $this->headers(['relatorios.view']);

        $this->get('/api/relatorios/incidentes?agrupar_por=concluido_por&formato=xlsx', [...$h, 'Accept-Language' => 'en'])->assertOk();

        Excel::assertDownloaded(
            'relatorio-concluido_por.xlsx',
            fn (RelatorioIncidentesExport $e) => $e->headings() === ['Completed by', 'Total']
        );
    }

    public function test_json_do_relatorio_legado_traduz_rotulo_de_sla(): void
    {
        $h = $this->headers(['relatorios.view']);

        $en = $this->getJson('/api/relatorios/incidentes?agrupar_por=status_sla', [...$h, 'Accept-Language' => 'en'])->assertOk();
        $this->assertSame(['Within SLA', 'SLA breached', 'No applicable SLA'], array_column($en->json('data'), 'rotulo'));
    }
}
