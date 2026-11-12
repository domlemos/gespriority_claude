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
 * `GET /relatorios/fluxos`: passagens origem → destino (encaminhamentos e
 * aberturas diretas para outro grupo), nós com abertos/recebidos/
 * encaminhados/resolvidos, nós especiais e visibilidade.
 */
class FluxosTest extends TestCase
{
    use RefreshDatabase;

    private const OUTUBRO = 'data_inicio=2026-10-01&data_fim=2026-10-31';

    private ?array $headerAdmin = null;

    /** @return array{0: string, 1: User} */
    private function staffToken(?GrupoSolucao $grupoSolucao = null, bool $asAdmin = false): array
    {
        // Slug explícito também para o não admin: nunca depender do nome gerado.
        $role = Role::factory()->create(['slug' => $asAdmin ? 'admin' : 'gestor-teste-'.Role::query()->count()]);
        $role->permissions()->attach(Permission::firstWhere('slug', 'relatorios.view') ?? Permission::factory()->create(['slug' => 'relatorios.view']));

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        return [$user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken, $user];
    }

    private function adminHeader(): array
    {
        if ($this->headerAdmin === null) {
            [$token] = $this->staffToken(asAdmin: true);
            $this->headerAdmin = ['Authorization' => "Bearer {$token}"];
        }

        return $this->headerAdmin;
    }

    private function gestorHeader(GrupoSolucao $grupo): array
    {
        [$token] = $this->staffToken($grupo);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function grupo(string $nome, array $extra = []): GrupoSolucao
    {
        return GrupoSolucao::factory()->create(['nome' => $nome, ...$extra]);
    }

    private function agente(GrupoSolucao $grupo): User
    {
        return User::factory()->create(['grupo_solucao_id' => $grupo->id]);
    }

    /** Evento com todos os snapshots explícitos (o hook não preenche nada). */
    private function evento(Incidente $incidente, string $tipo, string $quando, array $atributos = []): IncidenteEvento
    {
        return IncidenteEvento::query()->forceCreate([
            'incidente_id' => $incidente->id,
            'tipo' => $tipo,
            'created_at' => $quando,
            'user_id' => null,
            'grupo_solucao_id' => null,
            'autor_grupo_solucao_id' => null,
            ...$atributos,
        ]);
    }

    private function abrePortal(?GrupoSolucao $destino, string $quando = '2026-10-10 12:00:00', array $extra = []): Incidente
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $destino?->id]);
        $this->evento($incidente, IncidenteEvento::TIPO_ABERTO, $quando, [
            'autor_customer_id' => $incidente->customer_id ?? Customer::factory()->create()->id,
            'grupo_solucao_id' => $destino?->id,
            ...$extra,
        ]);

        return $incidente;
    }

    private function abreAgente(User $agente, ?GrupoSolucao $destino, string $quando = '2026-10-10 12:00:00', array $extra = []): Incidente
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $destino?->id]);
        $this->evento($incidente, IncidenteEvento::TIPO_ABERTO, $quando, [
            'user_id' => $agente->id,
            'autor_grupo_solucao_id' => $agente->grupo_solucao_id,
            'grupo_solucao_id' => $destino?->id,
            ...$extra,
        ]);

        return $incidente;
    }

    private function encaminha(Incidente $incidente, ?GrupoSolucao $origem, GrupoSolucao $destino, string $quando, array $extra = []): IncidenteEvento
    {
        $incidente->update(['grupo_solucao_id' => $destino->id]);

        return $this->evento($incidente, IncidenteEvento::TIPO_ENCAMINHADO_GRUPO, $quando, [
            'alvo_type' => GrupoSolucao::class,
            'alvo_id' => $destino->id,
            'origem_grupo_id' => $origem?->id,
            'grupo_solucao_id' => $destino->id,
            ...$extra,
        ]);
    }

    private function removeGrupo(Incidente $incidente, GrupoSolucao $grupo, string $quando): void
    {
        $incidente->update(['grupo_solucao_id' => null]);
        $this->evento($incidente, IncidenteEvento::TIPO_REMOVIDO_GRUPO, $quando, ['origem_grupo_id' => $grupo->id]);
    }

    private function resolve(Incidente $incidente, GrupoSolucao $noGrupo, User $autor, string $quando, array $extra = []): void
    {
        $this->evento($incidente, IncidenteEvento::TIPO_RESOLVIDO, $quando, [
            'user_id' => $autor->id,
            'autor_grupo_solucao_id' => $autor->grupo_solucao_id,
            'grupo_solucao_id' => $noGrupo->id,
            ...$extra,
        ]);
    }

    private function consultar(string $query, ?array $header = null): TestResponse
    {
        $header ??= $this->adminHeader();
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/relatorios/fluxos?{$query}", $header);
    }

    private function no(TestResponse $r, GrupoSolucao|string $chave): ?array
    {
        $chave = $chave instanceof GrupoSolucao ? (string) $chave->id : $chave;

        return collect($r->json('nos'))->firstWhere('chave', $chave);
    }

    /** @return array{abertos: ?int, recebidos: ?int, encaminhados: ?int, resolvidos: ?int} */
    private function metricas(TestResponse $r, GrupoSolucao|string $chave): array
    {
        $no = $this->no($r, $chave);
        $this->assertNotNull($no, 'Nó ausente: '.($chave instanceof GrupoSolucao ? $chave->nome : $chave));

        return array_intersect_key($no, array_flip(['abertos', 'recebidos', 'encaminhados', 'resolvidos']));
    }

    private function m(?int $abertos, ?int $recebidos, ?int $encaminhados, ?int $resolvidos): array
    {
        return compact('abertos', 'recebidos', 'encaminhados', 'resolvidos');
    }

    /** @return array<string, int> `"origem>destino" => quantidade`, na ordem da resposta */
    private function arestas(TestResponse $r): array
    {
        $arestas = [];
        foreach ($r->json('arestas') as $a) {
            $arestas["{$a['origem']}>{$a['destino']}"] = $a['quantidade'];
        }

        return $arestas;
    }

    /** Compara as arestas sem depender da ordem (que segue a chave em texto, ver o teste de ordenação). */
    private function assertArestas(array $esperadas, TestResponse $r): void
    {
        $atuais = $this->arestas($r);
        ksort($esperadas);
        ksort($atuais);
        $this->assertSame($esperadas, $atuais);
    }

    private function k(GrupoSolucao|string ...$pontas): string
    {
        return implode('>', array_map(fn ($p) => $p instanceof GrupoSolucao ? (string) $p->id : $p, $pontas));
    }

    public function test_exige_periodo(): void
    {
        $this->consultar('')->assertStatus(422)->assertJsonValidationErrors(['periodo' => 'Informe um período.']);
        $this->consultar('periodo=este_mes')->assertOk();
    }

    public function test_portal_triado_e_reencaminhado(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $chamado = $this->abrePortal(null, '2026-10-10 12:00:00');
        $this->encaminha($chamado, null, $n1, '2026-10-11 12:00:00');
        $this->encaminha($chamado, $n1, $n2, '2026-10-12 12:00:00');

        // Fora do período: não conta.
        $antigo = $this->abrePortal(null, '2026-09-10 12:00:00');
        $this->encaminha($antigo, null, $n1, '2026-09-11 12:00:00');

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertArestas([$this->k('cliente', $n1) => 1, $this->k($n1, $n2) => 1], $r);
        $this->assertSame($this->m(1, null, null, null), $this->metricas($r, 'cliente'));
        $this->assertSame($this->m(0, 1, 1, 0), $this->metricas($r, $n1));
        $this->assertSame($this->m(0, 1, 0, 0), $this->metricas($r, $n2));
        $this->assertNull($this->no($r, 'sem_grupo'));
        $this->assertSame(
            ['chave' => 'cliente', 'rotulo' => 'Cliente', 'tipo' => 'cliente', 'vizinho' => false],
            array_intersect_key($r->json('nos.0'), array_flip(['chave', 'rotulo', 'tipo', 'vizinho'])),
        );
        $this->assertSame(['chave' => (string) $n1->id, 'rotulo' => 'N1', 'tipo' => 'grupo', 'vizinho' => false],
            array_intersect_key($this->no($r, $n1), array_flip(['chave', 'rotulo', 'tipo', 'vizinho'])));
        $this->assertSame(['inicio' => '2026-10-01', 'fim' => '2026-10-31'], $r->json('periodo'));
        $this->assertSame([], $r->json('grupos'));
    }

    public function test_ida_e_volta_conta_cada_passagem(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $chamado = $this->abreAgente($this->agente($n1), $n1);
        $this->encaminha($chamado, $n1, $n2, '2026-10-11 12:00:00');
        $this->encaminha($chamado, $n2, $n1, '2026-10-12 12:00:00');

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertArestas([$this->k($n1, $n2) => 1, $this->k($n2, $n1) => 1], $r);
        $this->assertSame($this->m(1, 1, 1, 0), $this->metricas($r, $n1));
        $this->assertSame($this->m(0, 1, 1, 0), $this->metricas($r, $n2));

        // Segunda ida: a seta soma eventos, não chamados.
        $this->encaminha($chamado, $n1, $n2, '2026-10-13 12:00:00');
        $r = $this->consultar(self::OUTUBRO)->assertOk();
        $this->assertArestas([$this->k($n1, $n2) => 2, $this->k($n2, $n1) => 1], $r);
        $this->assertSame($this->m(1, 1, 2, 0), $this->metricas($r, $n1));
    }

    public function test_resolvido_conta_no_grupo_do_chamado(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $analistaN1 = $this->agente($n1);

        $chamado = Incidente::factory()->create(['grupo_solucao_id' => $n2->id]);
        $this->resolve($chamado, $n2, $analistaN1, '2026-10-11 12:00:00');
        // Reaberto e resolvido de novo: chamados distintos.
        $this->resolve($chamado, $n2, $analistaN1, '2026-10-12 12:00:00');

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertSame($this->m(0, 0, 0, 1), $this->metricas($r, $n2));
        $this->assertNull($this->no($r, $n1));
        $this->assertSame([], $r->json('arestas'));
    }

    public function test_gestor_ve_outros_grupos_agregados(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $n3 = $this->grupo('Grupo Secreto', ['id' => 987654]);
        $agenteN3 = $this->agente($n3);

        $a = $this->abreAgente($this->agente($n1), $n1);
        $this->encaminha($a, $n1, $n3, '2026-10-11 12:00:00');
        $this->encaminha($a, $n3, $n1, '2026-10-12 12:00:00');
        $this->encaminha($a, $n1, $n3, '2026-10-13 12:00:00');

        // Sem ponta visível para o gestor do N1: fica de fora (nem como "outros").
        $b = $this->abrePortal(null);
        $this->encaminha($b, null, $n3, '2026-10-11 12:00:00');
        $this->encaminha($b, $n3, $n2, '2026-10-12 12:00:00');
        $this->abreAgente($agenteN3, $n2);
        $this->resolve($b, $n3, $agenteN3, '2026-10-14 12:00:00');

        $gestor = $this->consultar(self::OUTUBRO, $this->gestorHeader($n1))->assertOk();

        $this->assertArestas([$this->k($n1, 'outros') => 2, $this->k('outros', $n1) => 1], $gestor);
        $this->assertSame(['cliente', (string) $n1->id, 'outros'], array_column($gestor->json('nos'), 'chave'));
        $this->assertSame(
            ['chave' => 'outros', 'rotulo' => 'Outros grupos', 'tipo' => 'outros', 'vizinho' => false, ...$this->m(null, null, null, null)],
            $this->no($gestor, 'outros'),
        );
        $this->assertSame($this->m(1, 1, 2, 0), $this->metricas($gestor, $n1));

        $json = json_encode($gestor->json());
        $this->assertStringNotContainsString('Grupo Secreto', $json);
        $this->assertStringNotContainsString('987654', $json);

        // O admin vê o N3 e o N2.
        $admin = $this->consultar(self::OUTUBRO)->assertOk();
        $this->assertSame($this->m(1, 3, 3, 1), $this->metricas($admin, $n3));
        $this->assertNull($this->no($admin, 'outros'));
    }

    public function test_remocao_de_grupo_gera_sem_grupo(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $chamado = $this->abreAgente($this->agente($n1), $n1);
        $this->removeGrupo($chamado, $n1, '2026-10-11 12:00:00');
        $this->encaminha($chamado, null, $n2, '2026-10-12 12:00:00');

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertArestas([$this->k('sem_grupo', $n2) => 1], $r);
        $this->assertSame($this->m(1, 0, 0, 0), $this->metricas($r, $n1));
        $this->assertSame($this->m(0, 1, 0, 0), $this->metricas($r, $n2));
        $this->assertSame(
            ['chave' => 'sem_grupo', 'rotulo' => 'Sem grupo', 'tipo' => 'sem_grupo', 'vizinho' => false, ...$this->m(null, null, null, null)],
            $this->no($r, 'sem_grupo'),
        );
    }

    public function test_abertura_direta_para_outro_grupo_e_passagem_e_conta_como_encaminhado_da_origem(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $this->abreAgente($this->agente($n1), $n2);

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertArestas([$this->k($n1, $n2) => 1], $r);
        $this->assertSame($this->m(1, 0, 1, 0), $this->metricas($r, $n1));
        $this->assertSame($this->m(0, 1, 0, 0), $this->metricas($r, $n2));
        $this->assertSame($this->m(0, null, null, null), $this->metricas($r, 'cliente'));
    }

    public function test_abertura_para_o_proprio_grupo_nao_e_passagem(): void
    {
        $n1 = $this->grupo('N1');

        $this->abreAgente($this->agente($n1), $n1);
        // Autor sem grupo no momento (grupo excluído) abrindo para N1: também não é passagem.
        $this->abreAgente($this->agente($n1), $n1, extra: ['autor_grupo_solucao_id' => null]);

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertSame([], $r->json('arestas'));
        $this->assertSame($this->m(1, 0, 0, 0), $this->metricas($r, $n1));
        $this->assertSame(['cliente', (string) $n1->id], array_column($r->json('nos'), 'chave'));
    }

    public function test_origem_desconhecida_para_encaminhamento_retroativo_sem_origem(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $chamado = $this->abreAgente($this->agente($n1), null, '2026-10-10 12:00:00', ['retroativo' => true]);
        $this->encaminha($chamado, null, $n2, '2026-10-11 12:00:00', ['retroativo' => true]);

        // Primeiro encaminhamento de chamado do portal sai do Cliente mesmo retroativo.
        $portal = $this->abrePortal(null);
        $this->encaminha($portal, null, $n2, '2026-10-11 12:00:00', ['retroativo' => true]);

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertArestas([$this->k('cliente', $n2) => 1, $this->k('origem_desconhecida', $n2) => 1], $r);
        $this->assertSame(
            ['chave' => 'origem_desconhecida', 'rotulo' => 'Origem desconhecida', 'tipo' => 'origem_desconhecida', 'vizinho' => false, ...$this->m(null, null, null, null)],
            $this->no($r, 'origem_desconhecida'),
        );
        $this->assertSame($this->m(0, 2, 0, 0), $this->metricas($r, $n2));
    }

    public function test_soma_das_setas_bate_com_recebidos_e_encaminhados(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $n3 = $this->grupo('N3');

        $a = $this->abrePortal(null);
        $this->encaminha($a, null, $n1, '2026-10-11 12:00:00');
        $this->encaminha($a, $n1, $n2, '2026-10-12 12:00:00');
        $this->encaminha($a, $n2, $n1, '2026-10-13 12:00:00');
        $this->resolve($a, $n1, $this->agente($n2), '2026-10-14 12:00:00');

        $b = $this->abreAgente($this->agente($n1), $n3);
        $this->encaminha($b, $n3, $n2, '2026-10-12 12:00:00');
        $this->removeGrupo($b, $n2, '2026-10-13 12:00:00');
        $this->encaminha($b, null, $n3, '2026-10-14 12:00:00');

        $c = $this->abrePortal($n2);
        $this->encaminha($c, $n2, $n3, '2026-10-12 12:00:00');

        $d = $this->abreAgente($this->agente($n3), null, '2026-10-10 12:00:00', ['retroativo' => true]);
        $this->encaminha($d, null, $n1, '2026-10-11 12:00:00', ['retroativo' => true]);

        $this->abreAgente($this->agente($n2), $n2);

        foreach ([null, $this->gestorHeader($n2)] as $header) {
            $r = $this->consultar(self::OUTUBRO, $header)->assertOk();
            $arestas = collect($r->json('arestas'));

            foreach ($r->json('nos') as $no) {
                if ($no['tipo'] !== 'grupo') {
                    continue;
                }
                $this->assertSame($no['recebidos'], $arestas->where('destino', $no['chave'])->sum('quantidade'), "recebidos {$no['rotulo']}");
                $this->assertSame($no['encaminhados'], $arestas->where('origem', $no['chave'])->sum('quantidade'), "encaminhados {$no['rotulo']}");
            }
        }

        $r = $this->consultar(self::OUTUBRO)->assertOk();
        $this->assertArestas([
            $this->k('cliente', $n1) => 1,
            $this->k('cliente', $n2) => 1,
            $this->k('origem_desconhecida', $n1) => 1,
            $this->k('sem_grupo', $n3) => 1,
            $this->k($n1, $n2) => 1,
            $this->k($n1, $n3) => 1,
            $this->k($n2, $n1) => 1,
            $this->k($n2, $n3) => 1,
            $this->k($n3, $n2) => 1,
        ], $r);
        $this->assertSame(
            ['cliente', 'sem_grupo', 'origem_desconhecida', (string) $n1->id, (string) $n2->id, (string) $n3->id],
            array_column($r->json('nos'), 'chave'),
        );
        $this->assertSame($this->m(2, null, null, null), $this->metricas($r, 'cliente'));
        $this->assertSame($this->m(1, 3, 2, 1), $this->metricas($r, $n1));
    }

    public function test_arestas_ordenadas_por_quantidade_e_nos_por_nome(): void
    {
        $beta = $this->grupo('beta');
        $apice = $this->grupo('Ápice');
        $acesso = $this->grupo('Acesso');

        foreach (range(1, 2) as $_) {
            $this->encaminha($this->abrePortal(null), null, $beta, '2026-10-11 12:00:00');
        }
        $this->abreAgente($this->agente($acesso), $apice);

        $r = $this->consultar(self::OUTUBRO)->assertOk();

        $this->assertSame(['cliente', (string) $acesso->id, (string) $apice->id, (string) $beta->id], array_column($r->json('nos'), 'chave'));
        $this->assertSame([$this->k('cliente', $beta), $this->k($acesso, $apice)], array_keys($this->arestas($r)));
    }

    public function test_filtro_de_grupos_mostra_grupos_selecionados_zerados_e_vizinhos(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $n3 = $this->grupo('N3');
        $n4 = $this->grupo('N4');
        $n5 = $this->grupo('N5');

        $a = $this->abreAgente($this->agente($n1), $n1);
        $this->encaminha($a, $n1, $n2, '2026-10-11 12:00:00');

        // Não toca a seleção: fica de fora, e o N3 não vira nó.
        $b = $this->abreAgente($this->agente($n3), $n3);
        $this->encaminha($b, $n3, $n2, '2026-10-11 12:00:00');

        // Números do vizinho não aparecem (seriam parciais).
        $c = $this->abreAgente($this->agente($n2), $n2);
        $this->resolve($c, $n2, $this->agente($n2), '2026-10-12 12:00:00');

        // N5 tem números mas não toca a seleção.
        $this->abreAgente($this->agente($n5), $n5);

        $r = $this->consultar(self::OUTUBRO."&grupos[]={$n4->id}&grupos[]={$n1->id}")->assertOk();

        $this->assertArestas([$this->k($n1, $n2) => 1], $r);
        $this->assertSame(['cliente', (string) $n1->id, (string) $n2->id, (string) $n4->id], array_column($r->json('nos'), 'chave'));
        $this->assertSame($this->m(0, 0, 0, 0), $this->metricas($r, $n4));
        $this->assertSame($this->m(1, 0, 1, 0), $this->metricas($r, $n1));
        $this->assertSame(
            ['chave' => (string) $n2->id, 'rotulo' => 'N2', 'tipo' => 'grupo', 'vizinho' => true, ...$this->m(null, null, null, null)],
            $this->no($r, $n2),
        );
        $this->assertSame(
            ['cliente' => false, (string) $n1->id => false, (string) $n2->id => true, (string) $n4->id => false],
            array_column($r->json('nos'), 'vizinho', 'chave'),
        );
        $this->assertSame([$n4->id, $n1->id], $r->json('grupos'));

        // Sem filtro ninguém é vizinho, e o N2 tem os seus números.
        $todos = $this->consultar(self::OUTUBRO)->assertOk();
        $this->assertSame([false], array_values(array_unique(array_column($todos->json('nos'), 'vizinho'))));
        $this->assertSame($this->m(1, 2, 0, 1), $this->metricas($todos, $n2));

        // Vizinho invisível para o gestor vira "outros".
        $gestor = $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}", $this->gestorHeader($n1))->assertOk();
        $this->assertArestas([$this->k($n1, 'outros') => 1], $gestor);
        $this->assertSame(['cliente', (string) $n1->id, 'outros'], array_column($gestor->json('nos'), 'chave'));
    }

    public function test_cliente_abertos_com_filtro_usa_primeiro_grupo_atribuido(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $a = $this->abrePortal(null);
        $this->encaminha($a, null, $n1, '2026-10-11 12:00:00');
        $this->encaminha($a, $n1, $n2, '2026-10-12 12:00:00');

        $b = $this->abrePortal(null);
        $this->encaminha($b, null, $n2, '2026-10-11 12:00:00');

        // Aberto já com grupo (ex.: backfill): vale o grupo do `aberto`.
        $this->abrePortal($n2);

        $r = $this->consultar(self::OUTUBRO."&grupos[]={$n2->id}")->assertOk();
        $this->assertSame(2, $this->no($r, 'cliente')['abertos']);
        $this->assertArestas([$this->k('cliente', $n2) => 2, $this->k($n1, $n2) => 1], $r);

        $r = $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}")->assertOk();
        $this->assertSame(1, $this->no($r, 'cliente')['abertos']);

        $r = $this->consultar(self::OUTUBRO)->assertOk();
        $this->assertSame(3, $this->no($r, 'cliente')['abertos']);
    }

    public function test_cliente_abertos_do_gestor_conta_so_chamados_visiveis(): void
    {
        $n1 = $this->grupo('N1');
        $n3 = $this->grupo('N3');

        $this->abrePortal($n1);
        $this->abrePortal(null);
        $this->abrePortal($n3);

        $r = $this->consultar(self::OUTUBRO, $this->gestorHeader($n1))->assertOk();
        $this->assertSame(2, $this->no($r, 'cliente')['abertos']);
        $this->assertArestas([$this->k('cliente', $n1) => 1], $r);
    }

    public function test_grupo_invisivel_no_filtro_e_422(): void
    {
        $n1 = $this->grupo('N1');
        $n3 = $this->grupo('N3');

        $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}&grupos[]={$n3->id}", $this->gestorHeader($n1))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grupos.1' => 'Grupo fora dos seus grupos visíveis.']);
    }

    public function test_aviso_ignora_filtro_e_usa_data_mais_recente(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $agenteN2 = $this->agente($n2);

        $chamado = $this->abreAgente($agenteN2, null, '2026-10-05 12:00:00', ['retroativo' => true]);
        // 01:00 UTC de 11/10 = 22:00 de 10/10 em Brasília.
        $this->encaminha($chamado, null, $n2, '2026-10-11 01:00:00', ['retroativo' => true]);
        $this->resolve($chamado, $n2, $agenteN2, '2026-10-08 12:00:00', ['retroativo' => true]);
        // Fechado retroativo e retroativo fora do período não contam.
        $this->evento($chamado, IncidenteEvento::TIPO_FECHADO, '2026-10-20 12:00:00', ['retroativo' => true]);
        $this->abreAgente($agenteN2, $n2, '2026-11-05 12:00:00', ['retroativo' => true]);

        $esperado = 'Dados aproximados: registros até 10/10/2026 foram reconstruídos; nesses casos a origem de um encaminhamento pode aparecer como desconhecida e o grupo de quem abriu é o grupo atual da pessoa.';

        $this->assertSame($esperado, $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}")->assertOk()->json('aviso'));
        $this->assertSame($esperado, $this->consultar(self::OUTUBRO, $this->gestorHeader($n1))->assertOk()->json('aviso'));
    }

    public function test_aviso_nulo_sem_retroativos(): void
    {
        $n1 = $this->grupo('N1');
        $this->abreAgente($this->agente($n1), $n1);
        $this->abreAgente($this->agente($n1), $n1, '2026-09-05 12:00:00', ['retroativo' => true]);

        $r = $this->consultar(self::OUTUBRO)->assertOk();
        $this->assertArrayHasKey('aviso', $r->json());
        $this->assertNull($r->json('aviso'));
    }

    public function test_titulo_e_legenda(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');

        $this->assertSame('Mapa de fluxos — de 01/10/2026 a 31/10/2026', $this->consultar(self::OUTUBRO)->json('titulo'));

        $r = $this->consultar(self::OUTUBRO."&grupos[]={$n1->id}&grupos[]={$n2->id}")->assertOk();
        $this->assertSame('Mapa de fluxos — de 01/10/2026 a 31/10/2026 — grupos: N1, N2', $r->json('titulo'));
        $this->assertSame([
            'Abertos pelo grupo: chamados criados no período por integrantes do grupo.',
            'Recebidos: chamados que entraram no grupo vindos do cliente ou de outro grupo, por encaminhamento ou abertos diretamente para ele.',
            'Encaminhados: encaminhamentos que saíram do grupo para outro grupo. Um chamado que vai e volta conta cada passagem.',
            'Resolvidos: chamados resolvidos enquanto estavam no grupo, por qualquer analista. A produção por analista está em Indicadores por grupo.',
            'As setas contam passagens no período. Recebidos menos encaminhados menos resolvidos não é o número de chamados em aberto.',
        ], $r->json('legenda'));
        $this->assertSame(
            ['titulo', 'periodo', 'grupos', 'nos', 'arestas', 'legenda', 'aviso'],
            array_keys($r->json()),
        );
    }
}
