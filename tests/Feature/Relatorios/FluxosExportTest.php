<?php

namespace Tests\Feature\Relatorios;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Relatorios\Fluxos\TextosFluxos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** `GET /relatorios/fluxos?formato=xlsx` e a lista em planilha, lidos de volta do arquivo gerado. */
class FluxosExportTest extends TestCase
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

    /** @return list<list<mixed>> */
    private function planilha(string $url, ?array $header = null): array
    {
        $header ??= $this->adminHeader();
        $this->app['auth']->forgetGuards();
        $response = $this->get($url, $header)->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray(null, false, false, false);
    }

    public function test_xlsx_tem_nos_fluxos_legenda_e_aviso(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $chamado = $this->abrePortal(null, '2026-10-02 12:00:00');
        $this->encaminha($chamado, null, $n1, '2026-10-03 12:00:00', ['retroativo' => true]);
        $this->encaminha($chamado, $n1, $n2, '2026-10-04 12:00:00');
        $this->resolve($chamado, $n2, $this->agente($n2), '2026-10-05 12:00:00');

        $linhas = $this->planilha('/api/relatorios/fluxos?'.self::OUTUBRO.'&formato=xlsx');
        $primeira = array_column($linhas, 0);

        $this->assertSame('Mapa de fluxos — de 01/10/2026 a 31/10/2026', $primeira[0]);
        $this->assertStringStartsWith('Gerado em ', $primeira[1]);
        $this->assertStringEndsWith('(horário de Brasília) por '.User::query()->orderByDesc('id')->value('name'), $primeira[1]);
        $this->assertNull($primeira[2]);
        $this->assertSame('Nós', $primeira[3]);
        $this->assertSame(['Grupo', 'Abertos pelo grupo', 'Recebidos', 'Encaminhados', 'Resolvidos'], $linhas[4]);
        // Cliente: só Abertos; as outras células ficam vazias.
        $this->assertSame(['Cliente', 1, null, null, null], array_slice($linhas[5], 0, 5));
        $this->assertSame(['N1', 0, 1, 1, 0], array_slice($linhas[6], 0, 5));
        $this->assertSame(['N2', 0, 1, 0, 1], array_slice($linhas[7], 0, 5));
        $this->assertNull($primeira[8]);
        $this->assertSame('Fluxos', $primeira[9]);
        $this->assertSame(['Origem', 'Destino', 'Quantidade'], array_slice($linhas[10], 0, 3));
        $this->assertEqualsCanonicalizing(
            [['Cliente', 'N1', 1], ['N1', 'N2', 1]],
            [array_slice($linhas[11], 0, 3), array_slice($linhas[12], 0, 3)],
        );
        $this->assertNull($primeira[13]);
        $this->assertSame('Legenda', $primeira[14]);
        $this->assertSame(TextosFluxos::LEGENDA, array_slice($primeira, 15, 5));
        $this->assertStringStartsWith('Dados aproximados: registros até 03/10/2026', $primeira[20]);
    }

    public function test_xlsx_sem_retroativos_nao_tem_aviso_e_neighbor_tem_celulas_vazias(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $chamado = $this->abreAgente($this->agente($n1), $n1);
        $this->encaminha($chamado, $n1, $n2, '2026-10-11 12:00:00');

        $linhas = $this->planilha('/api/relatorios/fluxos?'.self::OUTUBRO."&grupos[]={$n1->id}&formato=xlsx");

        $this->assertSame('Mapa de fluxos — de 01/10/2026 a 31/10/2026 — grupos: N1', $linhas[0][0]);
        $n2Linha = collect($linhas)->first(fn ($l) => $l[0] === 'N2');
        $this->assertSame(['N2', null, null, null, null], array_slice($n2Linha, 0, 5));
        $this->assertSame(TextosFluxos::LEGENDA[4], $linhas[array_key_last($linhas)][0]);
    }

    public function test_xlsx_texto_com_igual_nao_vira_formula(): void
    {
        $n1 = $this->grupo('=1+1');
        $chamado = $this->abreAgente($this->agente($n1), $n1);

        $header = $this->adminHeader();
        $this->app['auth']->forgetGuards();
        $response = $this->get('/api/relatorios/fluxos?'.self::OUTUBRO.'&formato=xlsx', $header)->assertOk();

        $folha = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $celula = collect(range(5, 12))->map(fn ($r) => $folha->getCell("A{$r}"))->first(fn ($c) => $c->getValue() === '=1+1');

        $this->assertNotNull($celula);
        $this->assertSame('s', $celula->getDataType());
        $this->assertNotNull($chamado);
    }

    public function test_download_tem_o_nome_do_arquivo(): void
    {
        $header = $this->adminHeader();
        $this->app['auth']->forgetGuards();

        $this->get('/api/relatorios/fluxos?'.self::OUTUBRO.'&formato=xlsx', $header)
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=mapa-de-fluxos.xlsx');
    }

    public function test_lista_xlsx_de_uma_seta_e_de_um_numero(): void
    {
        $n1 = $this->grupo('N1');
        $n2 = $this->grupo('N2');
        $chamado = $this->abreAgente($this->agente($n1), $n1);
        $chamado->update(['titulo' => 'Impressora']);
        $this->encaminha($chamado, $n1, $n2, '2026-10-11 12:00:00');

        $seta = $this->planilha('/api/relatorios/fluxos/chamados?'.self::OUTUBRO."&origem={$n1->id}&destino={$n2->id}&formato=xlsx");
        $this->assertSame('N1 → N2', $seta[1][0]);
        $this->assertSame('Destino', $seta[4][6]);
        $this->assertSame('Impressora', $seta[5][1]);
        $this->assertSame('N2', $seta[5][6]);

        $numero = $this->planilha('/api/relatorios/fluxos/chamados?'.self::OUTUBRO."&no={$n1->id}&metrica=abertos&formato=xlsx");
        $this->assertSame('N1 · Abertos', $numero[1][0]);
        $this->assertSame('Impressora', $numero[5][1]);
    }

    public function test_exportacoes_registram_auditoria(): void
    {
        $n1 = $this->grupo('N1');
        $this->abreAgente($this->agente($n1), $n1);

        $this->planilha('/api/relatorios/fluxos?'.self::OUTUBRO.'&formato=xlsx');
        $this->assertSame(1, $this->contagem('exportado'));

        $this->planilha('/api/relatorios/fluxos/chamados?'.self::OUTUBRO."&no={$n1->id}&metrica=abertos&formato=xlsx");
        $this->assertSame(2, $this->contagem('exportado'));

        $log = AuditLog::query()->where('acao', 'exportado')->latest('id')->first();
        $this->assertSame('relatorio', $log->entidade_type);
        $this->assertStringContainsString('N1 · Abertos', $log->entidade_label);
    }

    private function contagem(string $acao): int
    {
        return DB::table('audit_logs')->where('acao', $acao)->count();
    }
}
