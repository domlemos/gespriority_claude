<?php

namespace Tests\Feature\Relatorios;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /relatorios/indicadores`: abertos/resolvidos/fechados/cancelados por
 * grupo do autor no momento do evento, com as linhas especiais "Cliente
 * (portal)" e "Sem autor registrado", detalhamento por integrante e aviso de
 * dados reconstruídos.
 */
class IndicadoresTest extends TestCase
{
    use RefreshDatabase;

    private const OUTUBRO = 'data_inicio=2026-10-01&data_fim=2026-10-31';

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null, bool $asAdmin = false): array
    {
        $role = Role::factory()->create($asAdmin ? ['slug' => 'admin'] : []);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::firstWhere('slug', $slug) ?? Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private ?array $headerAdmin = null;

    private function adminHeader(): array
    {
        if ($this->headerAdmin === null) {
            [$token] = $this->staffToken(['relatorios.view'], asAdmin: true);
            $this->headerAdmin = ['Authorization' => "Bearer {$token}"];
        }

        return $this->headerAdmin;
    }

    private function gestorHeader(GrupoSolucao $grupo): array
    {
        [$token] = $this->staffToken(['relatorios.view'], $grupo);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function grupo(string $nome, bool $ativo = true): GrupoSolucao
    {
        return GrupoSolucao::factory()->create(['nome' => $nome, 'ativo' => $ativo]);
    }

    private function agente(GrupoSolucao $grupo, string $nome = 'Agente'): User
    {
        return User::factory()->create(['name' => $nome, 'grupo_solucao_id' => $grupo->id]);
    }

    /** Evento com autor agente; o grupo do autor vem do snapshot do hook (grupo atual da pessoa). */
    private function evento(Incidente $incidente, ?User $autor, string $tipo, string $quando = '2026-10-15 12:00:00', array $extra = []): IncidenteEvento
    {
        return IncidenteEvento::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $autor?->id,
            'tipo' => $tipo,
            'created_at' => $quando,
            ...$extra,
        ]);
    }

    private function aberturaPortal(?GrupoSolucao $destino, string $quando = '2026-10-15 12:00:00'): Incidente
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $destino?->id]);
        $this->evento($incidente, null, IncidenteEvento::TIPO_ABERTO, $quando, [
            'autor_customer_id' => $incidente->customer_id ?? Customer::factory()->create()->id,
            'grupo_solucao_id' => $destino?->id,
        ]);

        return $incidente;
    }

    private function consultar(string $query, ?array $header = null): TestResponse
    {
        // Criar o admin passa pela auditoria, que já resolve o usuário; por isso o header vem antes.
        $header ??= $this->adminHeader();
        // O guard guarda o usuário da requisição anterior; um teste pode alternar admin e gestor.
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/relatorios/indicadores?{$query}", $header);
    }

    private function linha(TestResponse $response, int|string $chave): ?array
    {
        return collect($response->json('linhas'))->firstWhere('chave', (string) $chave);
    }

    /** @return array<string, int> total de cada indicador na linha */
    private function valores(TestResponse $response, int|string $chave): array
    {
        $linha = $this->linha($response, $chave);
        $this->assertNotNull($linha, "Linha {$chave} ausente.");

        return array_map(fn (array $v) => $v['total'], $linha['valores']);
    }

    private function zeros(): array
    {
        return ['abertos' => 0, 'resolvidos' => 0, 'fechados' => 0, 'cancelados' => 0];
    }

    public function test_exige_periodo(): void
    {
        $this->consultar('')->assertStatus(422)->assertJsonValidationErrors(['periodo' => 'Informe um período.']);
        $this->consultar('periodo=este_mes')->assertOk();
    }

    public function test_conta_cada_indicador_pelo_grupo_do_autor_no_momento(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $a1 = $this->agente($n1);
        $a2 = $this->agente($n2);
        [$i1, $i2] = Incidente::factory()->count(2)->create()->all();

        $this->evento($i1, $a1, 'aberto', '2026-10-02 12:00:00');
        $this->evento($i2, $a1, 'aberto', '2026-10-03 12:00:00');
        $this->evento($i1, $a2, 'resolvido');
        $this->evento($i1, $a1, 'fechado');
        $this->evento($i2, $a2, 'cancelado');

        $response = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertSame(['abertos' => 2, 'resolvidos' => 0, 'fechados' => 1, 'cancelados' => 0], $this->valores($response, $n1->id));
        $this->assertSame(['abertos' => 0, 'resolvidos' => 1, 'fechados' => 0, 'cancelados' => 1], $this->valores($response, $n2->id));

        $response
            ->assertJsonPath('periodo', ['inicio' => '2026-10-01', 'fim' => '2026-10-31'])
            ->assertJsonPath('quebra', 'nenhuma')
            ->assertJsonPath('grupos', [])
            ->assertJsonPath('por_integrante', false)
            ->assertJsonPath('indicadores', [
                ['chave' => 'abertos', 'rotulo' => 'Abertos'],
                ['chave' => 'resolvidos', 'rotulo' => 'Resolvidos'],
                ['chave' => 'fechados', 'rotulo' => 'Fechados'],
                ['chave' => 'cancelados', 'rotulo' => 'Cancelados'],
            ])
            ->assertJsonPath('colunas', [['chave' => 'total', 'rotulo' => 'Total']])
            ->assertJsonPath('totais.abertos', ['celulas' => ['total' => 2], 'total' => 2])
            ->assertJsonPath('distintos', ['abertos' => 2, 'resolvidos' => 1, 'fechados' => 1, 'cancelados' => 1])
            ->assertJsonPath('aviso', null);

        $linhaN1 = $this->linha($response, $n1->id);
        $this->assertSame('N1', $linhaN1['rotulo']);
        $this->assertSame('grupo', $linhaN1['tipo']);
        $this->assertSame(['celulas' => ['total' => 2], 'total' => 2], $linhaN1['valores']['abertos']);
        $this->assertContainsOnlyString(array_column($response->json('linhas'), 'chave'));
    }

    public function test_abertura_na_fronteira_do_dia_conta_no_mes_de_brasilia(): void
    {
        $n1 = $this->grupo('N1');
        // 31/10 23:30 em Brasília.
        $this->evento(Incidente::factory()->create(), $this->agente($n1), 'aberto', '2026-11-01 02:30:00');

        $this->assertSame(1, $this->valores($this->consultar(self::OUTUBRO), $n1->id)['abertos']);
        $this->assertSame(0, $this->valores($this->consultar('data_inicio=2026-11-01&data_fim=2026-11-30'), $n1->id)['abertos']);
    }

    public function test_resolucao_por_dois_grupos_conta_uma_vez_em_cada(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $incidente = Incidente::factory()->create();
        $this->evento($incidente, $this->agente($n1), 'resolvido', '2026-10-01 12:00:00');
        $this->evento($incidente, $this->agente($n2), 'reaberto', '2026-10-10 12:00:00');
        $this->evento($incidente, $this->agente($n2), 'resolvido', '2026-10-15 12:00:00');

        $response = $this->consultar(self::OUTUBRO);
        $this->assertSame(1, $this->valores($response, $n1->id)['resolvidos']);
        $this->assertSame(1, $this->valores($response, $n2->id)['resolvidos']);
        $response->assertJsonPath('totais.resolvidos.total', 2)->assertJsonPath('distintos.resolvidos', 1);

        $semanal = $this->consultar(self::OUTUBRO.'&quebra=semana')->assertOk();
        $this->assertSame('2026-09-28', $semanal->json('colunas.0.chave'));
        $this->assertSame(1, $this->linha($semanal, $n1->id)['valores']['resolvidos']['celulas']['2026-09-28']);
        $this->assertSame(0, $this->linha($semanal, $n1->id)['valores']['resolvidos']['celulas']['2026-10-12']);
        $this->assertSame(1, $this->linha($semanal, $n2->id)['valores']['resolvidos']['celulas']['2026-10-12']);
        $this->assertSame(0, $this->linha($semanal, $n2->id)['valores']['resolvidos']['celulas']['2026-09-28']);
    }

    public function test_mesmo_grupo_resolve_duas_vezes_conta_uma(): void
    {
        $n1 = $this->grupo('N1');
        $incidente = Incidente::factory()->create();
        $this->evento($incidente, $this->agente($n1, 'Ana'), 'resolvido', '2026-10-02 12:00:00');
        $this->evento($incidente, $this->agente($n1, 'Beto'), 'resolvido', '2026-10-20 12:00:00');

        $response = $this->consultar(self::OUTUBRO);
        $this->assertSame(1, $this->valores($response, $n1->id)['resolvidos']);
        $response->assertJsonPath('totais.resolvidos.total', 1);
    }

    public function test_portal_aparece_separado_e_filtra_por_destino_com_grupos(): void
    {
        $n1 = $this->grupo('N1');
        $this->aberturaPortal($n1);
        $this->aberturaPortal(null);
        $this->evento(Incidente::factory()->create(), $this->agente($n1), 'aberto');

        $comGrupo = $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}")->assertOk();
        $portal = $this->linha($comGrupo, 'portal');
        $this->assertSame('Cliente (portal)', $portal['rotulo']);
        $this->assertSame('portal', $portal['tipo']);
        $this->assertSame([...$this->zeros(), 'abertos' => 1], $this->valores($comGrupo, 'portal'));
        $this->assertSame('portal', collect($comGrupo->json('linhas'))->last()['chave']);
        $this->assertSame(1, $this->valores($comGrupo, $n1->id)['abertos']);

        $semGrupo = $this->consultar(self::OUTUBRO);
        $this->assertSame([...$this->zeros(), 'abertos' => 2], $this->valores($semGrupo, 'portal'));
        $semGrupo->assertJsonPath('totais.abertos.total', 3);
    }

    public function test_sem_grupos_lista_ativos_visiveis_mais_inativos_com_evento(): void
    {
        $ativo = $this->grupo('Ativo');
        $inativoSem = $this->grupo('Inativo sem', ativo: false);
        $inativoCom = $this->grupo('Inativo com', ativo: false);
        $this->evento(Incidente::factory()->create(), $this->agente($inativoCom), 'fechado');

        $response = $this->consultar(self::OUTUBRO);

        $this->assertSame($this->zeros(), $this->valores($response, $ativo->id));
        $this->assertSame([...$this->zeros(), 'fechados' => 1], $this->valores($response, $inativoCom->id));
        $this->assertNull($this->linha($response, $inativoSem->id));
        // Total desc primeiro; os demais (inclusive o grupo do admin, criado pela factory) vêm zerados.
        $chaves = array_column($response->json('linhas'), 'chave');
        $this->assertSame((string) $inativoCom->id, $chaves[0]);
        $this->assertSame('portal', end($chaves));
    }

    public function test_grupos_selecionados_aparecem_zerados(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2', ativo: false);
        $n3 = $this->grupo('N3');
        $this->evento(Incidente::factory()->create(), $this->agente($n3), 'aberto');

        $response = $this->consultar(self::OUTUBRO."&grupos[]={$n2->id}&grupos[]={$n1->id}")->assertOk();

        $this->assertSame(
            [(string) $n1->id, (string) $n2->id, 'portal'],
            array_column($response->json('linhas'), 'chave'),
        );
        $this->assertSame($this->zeros(), $this->valores($response, $n1->id));
        $response->assertJsonPath('grupos', [$n2->id, $n1->id]);
    }

    public function test_sem_autor_so_aparece_sem_selecao_e_quando_nao_zerada(): void
    {
        $n1 = $this->grupo('N1');
        $this->evento(Incidente::factory()->create(), $this->agente($n1), 'aberto');

        $this->assertNull($this->linha($this->consultar(self::OUTUBRO), '__sem_autor__'));

        // Sem autor nenhum, e autor cujo grupo foi excluído (snapshot nulo).
        $this->evento(Incidente::factory()->create(), null, 'aberto');
        $this->evento(Incidente::factory()->create(), $this->agente($n1), 'resolvido', extra: ['autor_grupo_solucao_id' => null]);

        $response = $this->consultar(self::OUTUBRO);
        $linha = $this->linha($response, '__sem_autor__');
        $this->assertSame('Sem autor registrado', $linha['rotulo']);
        $this->assertSame('sem_autor', $linha['tipo']);
        $this->assertSame([...$this->zeros(), 'abertos' => 1, 'resolvidos' => 1], $this->valores($response, '__sem_autor__'));
        $this->assertSame(['portal', '__sem_autor__'], array_slice(array_column($response->json('linhas'), 'chave'), -2));

        $this->assertNull($this->linha($this->consultar(self::OUTUBRO."&grupos[]={$n1->id}"), '__sem_autor__'));
        $this->assertNull($this->linha($this->consultar(self::OUTUBRO."&grupos[]={$n1->id}&por_integrante=1"), '__sem_autor__'));
    }

    public function test_por_integrante_exige_um_grupo(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $mensagem = 'Selecione um único grupo para detalhar por integrante.';

        $this->consultar(self::OUTUBRO.'&por_integrante=1')
            ->assertStatus(422)->assertJsonValidationErrors(['por_integrante' => $mensagem]);
        $this->consultar(self::OUTUBRO."&por_integrante=1&grupos[]={$n1->id}&grupos[]={$n2->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['por_integrante' => $mensagem]);
        $this->consultar(self::OUTUBRO."&por_integrante=0&grupos[]={$n1->id}&grupos[]={$n2->id}")->assertOk();
    }

    public function test_por_integrante_inclui_quem_agiu_e_saiu_do_grupo_e_membros_zerados(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $migrou = $this->agente($n1, 'Migrou');
        $ocioso = $this->agente($n1, 'Ocioso');
        $saiu = $this->agente($n1, 'Saiu');
        $outro = $this->agente($n2, 'Outro');

        $this->evento(Incidente::factory()->create(), $migrou, 'aberto', '2026-10-05 12:00:00');
        $migrou->update(['grupo_solucao_id' => $n2->id]);
        $this->evento(Incidente::factory()->create(), $saiu, 'resolvido', '2026-10-06 12:00:00');
        $saiu->delete();
        $this->evento(Incidente::factory()->create(), $outro, 'aberto');
        $this->aberturaPortal($n1);

        $porGrupo = $this->consultar(self::OUTUBRO);
        $this->assertSame(1, $this->valores($porGrupo, $n1->id)['abertos']);
        $this->assertSame(1, $this->valores($porGrupo, $n2->id)['abertos']);

        $response = $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}&por_integrante=1")->assertOk();
        $response->assertJsonPath('por_integrante', true);

        $this->assertSame([...$this->zeros(), 'abertos' => 1], $this->valores($response, $migrou->id));
        $this->assertSame([...$this->zeros(), 'resolvidos' => 1], $this->valores($response, $saiu->id));
        $this->assertSame($this->zeros(), $this->valores($response, $ocioso->id));
        $this->assertNull($this->linha($response, $outro->id));
        $this->assertSame('Saiu (excluído)', $this->linha($response, $saiu->id)['rotulo']);
        $this->assertSame('usuario', $this->linha($response, $migrou->id)['tipo']);
        $this->assertSame([...$this->zeros(), 'abertos' => 1], $this->valores($response, 'portal'));
        $this->assertSame(
            [(string) $migrou->id, (string) $saiu->id, (string) $ocioso->id, 'portal'],
            array_column($response->json('linhas'), 'chave'),
        );
    }

    public function test_gestor_nao_admin_conta_acao_do_seu_grupo_em_chamado_de_outro_grupo(): void
    {
        $n2 = $this->grupo('N2');
        $n3 = $this->grupo('N3');
        $noN3 = Incidente::factory()->create(['grupo_solucao_id' => $n3->id]);
        $this->evento($noN3, $this->agente($n2), 'resolvido');
        $this->evento($noN3, $this->agente($n3), 'fechado');
        $this->aberturaPortal($n3);
        $this->evento(Incidente::factory()->create(['grupo_solucao_id' => $n3->id]), null, 'cancelado');

        $response = $this->consultar(self::OUTUBRO, $this->gestorHeader($n2))->assertOk();

        $this->assertSame([...$this->zeros(), 'resolvidos' => 1], $this->valores($response, $n2->id));
        $this->assertNull($this->linha($response, $n3->id));
        $this->assertSame($this->zeros(), $this->valores($response, 'portal'));
        $this->assertNull($this->linha($response, '__sem_autor__'));

        // O admin vê tudo.
        $admin = $this->consultar(self::OUTUBRO);
        $this->assertSame([...$this->zeros(), 'fechados' => 1], $this->valores($admin, $n3->id));
        $this->assertSame(1, $this->valores($admin, 'portal')['abertos']);
        $this->assertSame(1, $this->valores($admin, '__sem_autor__')['cancelados']);
    }

    public function test_grupo_fora_dos_visiveis_e_422(): void
    {
        $n2 = $this->grupo('N2');
        $n3 = $this->grupo('N3');

        $this->consultar(self::OUTUBRO."&grupos[]={$n2->id}&grupos[]={$n3->id}", $this->gestorHeader($n2))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grupos.1' => 'Grupo fora dos seus grupos visíveis.'])
            ->assertJsonMissingValidationErrors(['grupos.0']);
    }

    public function test_aviso_lista_colunas_com_retroativos_e_data_mais_recente(): void
    {
        $n1 = $this->grupo('N1');
        $a1 = $this->agente($n1);
        $this->evento(Incidente::factory()->create(), $a1, 'aberto', '2026-10-05 12:00:00', ['retroativo' => true]);
        // 10/10 22:00 em Brasília.
        $this->evento(Incidente::factory()->create(), $a1, 'aberto', '2026-10-11 01:00:00', ['retroativo' => true]);
        $this->evento(Incidente::factory()->create(), $a1, 'resolvido', '2026-10-20 12:00:00');
        $this->evento(Incidente::factory()->create(), $a1, 'resolvido', '2026-11-10 12:00:00');

        $this->consultar(self::OUTUBRO)->assertJsonPath(
            'aviso',
            'Dados aproximados em Abertos: registros até 10/10/2026 foram reconstruídos e usam o grupo atual da pessoa, não o da época.'
        );
        $this->consultar('data_inicio=2026-11-01&data_fim=2026-11-30')->assertJsonPath('aviso', null);
    }

    public function test_aviso_junta_colunas_na_ordem_fixa(): void
    {
        $a1 = $this->agente($this->grupo('N1'));
        foreach (['cancelado', 'aberto', 'fechado'] as $tipo) {
            $this->evento(Incidente::factory()->create(), $a1, $tipo, '2026-10-03 12:00:00', ['retroativo' => true]);
        }

        $this->consultar(self::OUTUBRO)->assertJsonPath(
            'aviso',
            'Dados aproximados em Abertos, Fechados e Cancelados: registros até 03/10/2026 foram reconstruídos e usam o grupo atual da pessoa, não o da época.'
        );
    }

    public function test_titulo_e_legenda(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}&grupos[]={$n2->id}")
            ->assertJsonPath('titulo', 'Indicadores por grupo de solução — de 01/10/2026 a 31/10/2026 — grupos: N1, N2')
            ->assertJsonPath('legenda', [
                'Abertos: chamados criados no período, contados no grupo de quem abriu no momento da abertura. Chamados abertos pelo portal ficam na linha "Cliente (portal)".',
                'Resolvidos: chamados com ao menos uma resolução no período, contados no grupo de quem resolveu no momento.',
                'Fechados: chamados com ao menos um fechamento no período, contados no grupo de quem fechou no momento.',
                'Cancelados: chamados cancelados no período, contados no grupo de quem cancelou no momento.',
                'As colunas são independentes: um chamado resolvido e fechado no período conta em Resolvidos e em Fechados. Um chamado resolvido por dois grupos conta uma vez em cada um.',
            ]);

        $this->consultar(self::OUTUBRO)
            ->assertJsonPath('titulo', 'Indicadores por grupo de solução — de 01/10/2026 a 31/10/2026');

        $this->consultar(self::OUTUBRO."&quebra=mes&grupos[]={$n1->id}&por_integrante=1")
            ->assertJsonPath('titulo', 'Indicadores por grupo de solução — de 01/10/2026 a 31/10/2026, por mês — grupos: N1 — por integrante');
    }

    public function test_quebra_segue_as_regras_da_consulta_de_relatorio(): void
    {
        $this->consultar('data_inicio=2020-01-01&data_fim=2026-10-31&quebra=dia')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quebra' => 'Período longo demais para quebra por dia: máximo de 62 colunas.']);
        $this->consultar('periodo=este_mes&data_inicio=2026-10-01&data_fim=2026-10-31')
            ->assertStatus(422)->assertJsonValidationErrors(['periodo']);
    }
}
