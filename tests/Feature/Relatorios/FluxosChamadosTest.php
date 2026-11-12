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

/** `GET /relatorios/fluxos/chamados`: a lista por trás de cada número e de cada seta do mapa de fluxos. */
class FluxosChamadosTest extends TestCase
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

    private function consultar(string $query): TestResponse
    {
        $header = $this->adminHeader();
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/relatorios/fluxos?{$query}", $header);
    }

    private function lista(string $query, ?array $header = null): TestResponse
    {
        $header ??= $this->adminHeader();
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/relatorios/fluxos/chamados?{$query}", $header);
    }

    private function cenario(): array
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $a1 = $this->agente($n1);
        $a2 = $this->agente($n2);

        // Portal -> triagem N1 -> N2 -> volta N1, resolvido no N1.
        $c1 = $this->abrePortal(null, '2026-10-02 12:00:00');
        $this->encaminha($c1, null, $n1, '2026-10-03 12:00:00');
        $this->encaminha($c1, $n1, $n2, '2026-10-04 12:00:00');
        $this->encaminha($c1, $n2, $n1, '2026-10-05 12:00:00');
        $this->resolve($c1, $n1, $a1, '2026-10-06 12:00:00');
        $this->resolve($c1, $n1, $a1, '2026-10-07 12:00:00');

        // Portal direto para o N2; agente do N1 abre direto para o N2; agente do N2 abre para si.
        $this->abrePortal($n2, '2026-10-08 12:00:00');
        $this->abreAgente($a1, $n2, '2026-10-09 12:00:00');
        $this->abreAgente($a2, $n2, '2026-10-10 12:00:00');

        // Sem grupo -> N2 depois de remover o grupo.
        $c2 = $this->abreAgente($a1, $n1, '2026-10-11 12:00:00');
        $this->removeGrupo($c2, $n1, '2026-10-12 12:00:00');
        $this->encaminha($c2, null, $n2, '2026-10-13 12:00:00');
        $this->resolve($c2, $n2, $a2, '2026-10-14 12:00:00');

        return [$n1, $n2];
    }

    public function test_lista_bate_com_cada_numero_e_cada_seta(): void
    {
        $this->cenario();
        $corpo = $this->consultar(self::OUTUBRO)->assertOk();
        $verificados = 0;

        foreach ($corpo->json('nos') as $no) {
            foreach (['abertos', 'recebidos', 'encaminhados', 'resolvidos'] as $metrica) {
                if ($no[$metrica] === null) {
                    continue;
                }
                $r = $this->lista(self::OUTUBRO."&no={$no['chave']}&metrica={$metrica}")->assertOk();
                $this->assertSame($no[$metrica], $r->json('meta.total'), "{$no['rotulo']} {$metrica}");
                $this->assertCount(min($no[$metrica], 50), $r->json('data'));
                $this->assertSame("{$no['rotulo']} · ".ucfirst($metrica), $r->json('filtro.rotulo'));
                $verificados++;
            }
        }
        $this->assertGreaterThan(8, $verificados);
        $this->assertNotEmpty($corpo->json('arestas'));

        foreach ($corpo->json('arestas') as $a) {
            $r = $this->lista(self::OUTUBRO."&origem={$a['origem']}&destino={$a['destino']}")->assertOk();
            $this->assertSame($a['quantidade'], $r->json('meta.total'), "{$a['origem']}>{$a['destino']}");
        }
    }

    public function test_lista_de_passagem_traz_destino_e_a_de_aberto_nao(): void
    {
        [$n1, $n2] = $this->cenario();

        $seta = $this->lista(self::OUTUBRO."&origem={$n1->id}&destino={$n2->id}")->assertOk();
        $this->assertSame('N1 → N2', $seta->json('filtro.rotulo'));
        $this->assertSame(['N2'], array_unique(array_column($seta->json('data'), 'destino')));
        $this->assertSame(['data', 'filtro', 'meta', 'titulo'], collect($seta->json())->keys()->sort()->values()->all());

        $abertos = $this->lista(self::OUTUBRO."&no={$n1->id}&metrica=abertos")->assertOk();
        $this->assertSame([null], array_unique(array_column($abertos->json('data'), 'destino')));

        $cliente = $this->lista(self::OUTUBRO.'&no=cliente&metrica=abertos')->assertOk();
        $this->assertSame('Cliente · Abertos', $cliente->json('filtro.rotulo'));
        $this->assertSame(2, $cliente->json('meta.total'));
    }

    public function test_paginacao(): void
    {
        [$n1] = $this->cenario();

        $r = $this->lista(self::OUTUBRO."&no={$n1->id}&metrica=recebidos&per_page=1&page=2")->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertSame(2, $r->json('meta.current_page'));
        $this->assertSame(2, $r->json('meta.last_page'));
        $this->lista(self::OUTUBRO."&no={$n1->id}&metrica=recebidos&per_page=201")->assertStatus(422);
    }

    public function test_modos_invalidos_sao_422(): void
    {
        [$n1, $n2] = $this->cenario();
        $mensagem = 'Informe um nó e uma métrica, ou uma origem e um destino.';

        $this->lista(self::OUTUBRO)->assertStatus(422)->assertJsonValidationErrors(['no' => $mensagem]);
        $this->lista(self::OUTUBRO."&no={$n1->id}")->assertStatus(422)->assertJsonValidationErrors(['no' => $mensagem]);
        $this->lista(self::OUTUBRO."&origem={$n1->id}")->assertStatus(422)->assertJsonValidationErrors(['no' => $mensagem]);
        $this->lista(self::OUTUBRO."&no={$n1->id}&metrica=recebidos&origem={$n1->id}&destino={$n2->id}")
            ->assertStatus(422)->assertJsonValidationErrors(['no' => $mensagem]);
        $this->lista(self::OUTUBRO."&no={$n1->id}&metrica=inexistente")->assertStatus(422)->assertJsonValidationErrors(['metrica']);
        $this->lista('')->assertStatus(422)->assertJsonValidationErrors(['periodo' => 'Informe um período.']);
    }

    public function test_metrica_inexistente_no_no_e_422(): void
    {
        [$n1, $n2] = $this->cenario();
        $mensagem = 'Este número não existe para este nó.';

        $this->lista(self::OUTUBRO.'&no=cliente&metrica=recebidos')->assertStatus(422)->assertJsonValidationErrors(['metrica' => $mensagem]);
        $this->lista(self::OUTUBRO.'&no=sem_grupo&metrica=abertos')->assertStatus(422)->assertJsonValidationErrors(['metrica' => $mensagem]);

        // Vizinho sob o filtro de grupos: métricas nulas; a seta continua listável.
        $filtro = self::OUTUBRO."&grupos[]={$n1->id}";
        $this->lista("{$filtro}&no={$n2->id}&metrica=recebidos")->assertStatus(422)->assertJsonValidationErrors(['metrica' => $mensagem]);
        $this->lista("{$filtro}&origem={$n1->id}&destino={$n2->id}")->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_grupo_fora_da_visibilidade_e_422(): void
    {
        $n1 = $this->grupo('N1');
        $n3 = $this->grupo('N3');

        $this->lista(self::OUTUBRO."&grupos[]={$n3->id}&no=cliente&metrica=abertos", $this->gestorHeader($n1))
            ->assertStatus(422)->assertJsonValidationErrors(['grupos.0' => 'Grupo fora dos seus grupos visíveis.']);
    }

    public function test_lista_de_seta_para_outros_nao_revela_grupo(): void
    {
        $n1 = $this->grupo('N1');
        $n3 = $this->grupo('N3 Secreto');
        $chamado = $this->abreAgente($this->agente($n1), $n1);
        $this->encaminha($chamado, $n1, $n3, '2026-10-11 12:00:00');
        $this->encaminha($chamado, $n3, $n1, '2026-10-12 12:00:00');

        $gestor = $this->gestorHeader($n1);
        foreach (["origem={$n1->id}&destino=outros", "origem=outros&destino={$n1->id}", 'no=outros&metrica=recebidos'] as $consulta) {
            $r = $this->lista(self::OUTUBRO.'&'.$consulta, $gestor);
            if ($consulta === 'no=outros&metrica=recebidos') {
                $r->assertStatus(422)->assertJsonValidationErrors(['metrica']);

                continue;
            }
            $r->assertOk()->assertJsonPath('meta.total', 1);
            foreach ($r->json('data') as $linha) {
                // O chamado hoje está no N1 (visível); o nome do N3 nunca aparece.
                $this->assertNotSame('N3 Secreto', $linha['destino']);
                $this->assertNotSame('N3 Secreto', $linha['grupo_chamado']['nome'] ?? null);
            }
            $this->assertStringNotContainsString('N3 Secreto', json_encode($r->json()));
        }

        // Chamado que ficou no N3: a linha existe, mas o gestor do N1 não o acessa e não vê o grupo.
        $preso = $this->abreAgente($this->agente($n1), $n1, '2026-10-13 12:00:00');
        $this->encaminha($preso, $n1, $n3, '2026-10-14 12:00:00');
        $r = $this->lista(self::OUTUBRO."&origem={$n1->id}&destino=outros", $gestor)->assertOk()->assertJsonPath('meta.total', 2);
        $linha = collect($r->json('data'))->firstWhere('incidente_id', $preso->id);
        $this->assertFalse($linha['acessivel']);
        $this->assertNull($linha['grupo_chamado']);
        $this->assertSame('Outros grupos', $linha['destino']);
    }
}
