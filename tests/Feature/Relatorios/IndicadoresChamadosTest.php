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

/** `GET /relatorios/indicadores/chamados`: a lista por trás de cada célula do relatório de indicadores. */
class IndicadoresChamadosTest extends TestCase
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

    private function chamados(string $query, ?array $header = null): TestResponse
    {
        $header ??= $this->adminHeader();
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/relatorios/indicadores/chamados?{$query}", $header);
    }

    public function test_lista_bate_com_cada_celula(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $a1 = $this->agente($n1);
        $a2 = $this->agente($n2);

        // Reabertura: resolvido pelo N1 e depois pelo N2; N1 também resolve duas vezes.
        $reaberto = Incidente::factory()->create();
        $this->evento($reaberto, $a1, 'resolvido', '2026-10-01 12:00:00', ['autor_grupo_solucao_id' => $n1->id]);
        $this->evento($reaberto, $a1, 'resolvido', '2026-10-03 12:00:00', ['autor_grupo_solucao_id' => $n1->id]);
        $this->evento($reaberto, $a2, 'resolvido', '2026-10-15 12:00:00', ['autor_grupo_solucao_id' => $n2->id]);
        $this->evento($reaberto, $a2, 'fechado', '2026-10-16 12:00:00', ['autor_grupo_solucao_id' => $n2->id]);
        $outro = Incidente::factory()->create();
        $this->evento($outro, $a1, 'aberto', '2026-10-05 12:00:00', ['autor_grupo_solucao_id' => $n1->id]);
        $this->evento($outro, $a1, 'cancelado', '2026-10-20 12:00:00', ['autor_grupo_solucao_id' => $n1->id]);
        $this->aberturaPortal($n1);
        $this->aberturaPortal(null);

        $query = self::OUTUBRO.'&quebra=semana';
        $corpo = $this->consultar($query)->assertOk()->json();
        $verificadas = 0;

        foreach ($corpo['linhas'] as $linha) {
            foreach ($corpo['indicadores'] as $ind) {
                $celula = $linha['valores'][$ind['chave']];
                $base = 'chave='.urlencode($linha['chave'])."&indicador={$ind['chave']}&{$query}";

                // Por coluna: a lista tem o tamanho da célula.
                foreach ($celula['celulas'] as $coluna => $n) {
                    $this->chamados("{$base}&coluna={$coluna}")->assertOk()->assertJsonPath('meta.total', $n);
                    $verificadas++;
                }
            }
        }

        // Sem quebra a lista (um por chamado) bate com o total da linha.
        $semQuebra = $this->consultar(self::OUTUBRO)->json();
        foreach ($semQuebra['linhas'] as $linha) {
            foreach ($semQuebra['indicadores'] as $ind) {
                $this->chamados('chave='.urlencode($linha['chave'])."&indicador={$ind['chave']}&".self::OUTUBRO)
                    ->assertOk()
                    ->assertJsonPath('meta.total', $linha['valores'][$ind['chave']]['total']);
                $verificadas++;
            }
        }

        $this->assertGreaterThan(30, $verificadas);
    }

    public function test_um_item_por_chamado_com_o_evento_mais_recente(): void
    {
        $n1 = $this->grupo('N1');
        $ana = $this->agente($n1, 'Ana');
        $bia = $this->agente($n1, 'Bia');
        $incidente = Incidente::factory()->create(['titulo' => 'Impressora']);
        $this->evento($incidente, $ana, 'resolvido', '2026-10-01 12:00:00', ['autor_grupo_solucao_id' => $n1->id]);
        $this->evento($incidente, $bia, 'resolvido', '2026-10-09 12:00:00', ['autor_grupo_solucao_id' => $n1->id]);

        $response = $this->chamados("chave={$n1->id}&indicador=resolvidos&".self::OUTUBRO)->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.incidente_id', $incidente->id)
            ->assertJsonPath('data.0.autor.name', 'Bia')
            ->assertJsonPath('data.0.acessivel', true)
            ->assertJsonPath('linha.rotulo', 'N1')
            ->assertJsonPath('indicador', ['chave' => 'resolvidos', 'rotulo' => 'Resolvidos'])
            ->assertJsonPath('coluna', null);
    }

    public function test_lista_de_chamado_fora_dos_visiveis_vem_sem_acesso(): void
    {
        $n2 = $this->grupo('N2');
        $n3 = $this->grupo('N3');
        $noN3 = Incidente::factory()->create(['grupo_solucao_id' => $n3->id]);
        $this->evento($noN3, $this->agente($n2), 'resolvido', extra: ['autor_grupo_solucao_id' => $n2->id]);

        $response = $this->chamados("chave={$n2->id}&indicador=resolvidos&".self::OUTUBRO, $this->gestorHeader($n2))->assertOk();

        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.incidente_id', $noN3->id)
            ->assertJsonPath('data.0.acessivel', false);

        $this->chamados("chave={$n3->id}&indicador=resolvidos&grupos[]={$n3->id}&".self::OUTUBRO, $this->gestorHeader($n2))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grupos.0' => 'Grupo fora dos seus grupos visíveis.']);
    }

    public function test_coluna_inexistente_e_422(): void
    {
        $n1 = $this->grupo('N1');

        $this->chamados("chave={$n1->id}&indicador=abertos&coluna=2026-11&quebra=mes&".self::OUTUBRO)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['coluna' => 'Coluna inexistente neste período.']);
    }

    public function test_indicador_e_chave_sao_obrigatorios(): void
    {
        $this->chamados(self::OUTUBRO.'&indicador=outro')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['chave', 'indicador']);
    }
}
