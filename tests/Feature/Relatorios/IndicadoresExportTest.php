<?php

namespace Tests\Feature\Relatorios;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Relatorios\Indicadores\TextosIndicadores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** `GET /relatorios/indicadores?formato=xlsx` e a lista em planilha, lidos de volta do arquivo gerado. */
class IndicadoresExportTest extends TestCase
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

    /** @return list<list<mixed>> linhas da planilha baixada, sem nulos finais */
    private function planilha(string $url): array
    {
        $header = $this->adminHeader();
        $this->app['auth']->forgetGuards();
        $response = $this->get($url, $header)->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);
    }

    public function test_xlsx_tem_titulo_legenda_aviso_e_distintos(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $reaberto = Incidente::factory()->create();
        $this->evento($reaberto, $this->agente($n1), 'resolvido', '2026-10-01 12:00:00', ['autor_grupo_solucao_id' => $n1->id, 'retroativo' => true]);
        $this->evento($reaberto, $this->agente($n2), 'resolvido', '2026-10-15 12:00:00', ['autor_grupo_solucao_id' => $n2->id]);
        $this->aberturaPortal($n1);

        $linhas = $this->planilha('/api/relatorios/indicadores?'.self::OUTUBRO.'&grupos[]='.$n1->id.'&grupos[]='.$n2->id.'&formato=xlsx');
        $primeira = array_column($linhas, 0);

        $this->assertSame('Indicadores por grupo de solução — de 01/10/2026 a 31/10/2026 — grupos: N1, N2', $primeira[0]);
        $this->assertStringStartsWith('Gerado em ', $primeira[1]);
        $this->assertStringEndsWith('(horário de Brasília) por '.User::query()->orderByDesc('id')->value('name'), $primeira[1]);
        $this->assertSame(['Grupo', 'Abertos — Total', 'Resolvidos — Total', 'Fechados — Total', 'Cancelados — Total'], $linhas[3]);
        $this->assertSame(['N1', 0, 1, 0, 0], array_slice($linhas[4], 0, 5));
        $this->assertSame(['N2', 0, 1, 0, 0], array_slice($linhas[5], 0, 5));
        $this->assertSame(['Cliente (portal)', 1, 0, 0, 0], array_slice($linhas[6], 0, 5));
        $this->assertSame(['Total', 1, 2, 0, 0], array_slice($linhas[7], 0, 5));
        $this->assertSame(['Chamados distintos', 1, 1, 0, 0], array_slice($linhas[8], 0, 5));

        $this->assertSame('Legenda', $primeira[10]);
        $this->assertSame(TextosIndicadores::LEGENDA, array_slice($primeira, 11, 5));
        $this->assertStringStartsWith('Dados aproximados em Resolvidos: registros até 01/10/2026', $primeira[16]);
    }

    public function test_xlsx_com_quebra_tem_uma_coluna_por_periodo(): void
    {
        $n1 = $this->grupo('N1');
        $this->evento(Incidente::factory()->create(), $this->agente($n1), 'aberto', '2026-10-15 12:00:00', ['autor_grupo_solucao_id' => $n1->id]);

        $linhas = $this->planilha('/api/relatorios/indicadores?data_inicio=2026-10-01&data_fim=2026-11-30&quebra=mes&por_integrante=1&grupos[]='.$n1->id.'&formato=xlsx');

        $this->assertSame(['Integrante', 'Out/2026', 'Nov/2026', 'Abertos — Total'], array_slice($linhas[3], 0, 4));
        $distintos = collect($linhas)->first(fn ($l) => $l[0] === 'Chamados distintos');
        $this->assertSame(['Chamados distintos', null, null, 1], array_slice($distintos, 0, 4));
    }

    public function test_xlsx_texto_com_igual_nao_vira_formula(): void
    {
        $this->grupo('=1+1');
        $header = $this->adminHeader();
        $this->app['auth']->forgetGuards();
        $response = $this->get('/api/relatorios/indicadores?'.self::OUTUBRO.'&formato=xlsx', $header)->assertOk();

        $folha = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $celula = collect(range(5, 12))->map(fn ($r) => $folha->getCell("A{$r}"))->first(fn ($c) => $c->getValue() === '=1+1');

        $this->assertNotNull($celula);
        $this->assertSame('s', $celula->getDataType());
    }

    public function test_xlsx_registra_auditoria(): void
    {
        $this->grupo('N1');
        $this->planilha('/api/relatorios/indicadores?'.self::OUTUBRO.'&formato=xlsx');

        $this->assertDatabaseHas('audit_logs', ['acao' => 'exportado', 'entidade_type' => 'relatorio']);
    }

    public function test_lista_xlsx_do_chamados_de_uma_celula(): void
    {
        $n1 = $this->grupo('N1');
        $incidente = Incidente::factory()->create(['titulo' => 'Impressora']);
        $this->evento($incidente, $this->agente($n1, 'Ana'), 'cancelado', extra: ['autor_grupo_solucao_id' => $n1->id]);

        $linhas = $this->planilha("/api/relatorios/indicadores/chamados?chave={$n1->id}&indicador=cancelados&".self::OUTUBRO.'&formato=xlsx');

        $this->assertSame('Linha: N1 · Cancelados', $linhas[1][0]);
        $this->assertSame('Impressora', $linhas[5][1]);
        $this->assertSame('Ana', $linhas[5][4]);
    }
}
