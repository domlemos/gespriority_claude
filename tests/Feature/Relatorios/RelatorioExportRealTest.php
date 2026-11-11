<?php

namespace Tests\Feature\Relatorios;

use App\Exports\RelatorioChamadosExport;
use App\Exports\RelatorioTabelaExport;
use App\Models\User;
use App\Relatorios\ConsultaRelatorio;
use App\Relatorios\ResultadoRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Gera o xlsx de verdade (sem `Excel::fake`) e lê de volta: o que o usuário abre. */
class RelatorioExportRealTest extends TestCase
{
    use RefreshDatabase;

    private function ler(object $export)
    {
        $caminho = tempnam(sys_get_temp_dir(), 'rel').'.xlsx';
        file_put_contents($caminho, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
        $planilha = IOFactory::load($caminho)->getActiveSheet();
        unlink($caminho);

        return $planilha;
    }

    public function test_lista_xlsx_real(): void
    {
        $user = User::factory()->create(['name' => 'Gestor']);
        $itens = [[
            'incidente_id' => 7,
            'titulo' => '=1+1',
            'status' => 'em_andamento',
            'data' => '2026-10-15 12:00:00',
            'autor' => ['name' => 'Ana'],
            'grupo_chamado' => ['nome' => 'N2'],
        ]];
        $f = $this->ler(new RelatorioChamadosExport('Titulo', 'Linha: Ana', $itens, false, $user, now()));

        $this->assertSame('Nº', $f->getCell('A5')->getValue());
        $this->assertSame('s', $f->getCell('B6')->getDataType());
        $this->assertSame('=1+1', $f->getCell('B6')->getValue());
        $this->assertSame('Em andamento', $f->getCell('C6')->getValue());
        $this->assertSame(7, (int) $f->getCell('A6')->getValue());
    }

    public function test_tabela_xlsx_real_zero_e_cabecalho(): void
    {
        $user = User::factory()->create(['name' => 'Gestor']);
        $consulta = new ConsultaRelatorio('concluidos', 'analista', 'nenhuma', null, null, null, []);
        $resultado = new ResultadoRelatorio(
            $consulta,
            [['chave' => 'total', 'rotulo' => 'Total']],
            [['chave' => 1, 'rotulo' => 'Ana', 'celulas' => ['total' => 0], 'total' => 0, 'percentual' => 0.0]],
            ['celulas' => ['total' => 0], 'total' => 0],
            0,
        );
        $f = $this->ler(new RelatorioTabelaExport('=1+1', $resultado, $user, now()));

        $this->assertSame('s', $f->getCell('A1')->getDataType());
        $this->assertSame('Analista', $f->getCell('A4')->getValue());
        $this->assertSame('Ana', $f->getCell('A5')->getValue());
        $this->assertNotNull($f->getCell('B5')->getValue());
        $this->assertSame(0, (int) $f->getCell('B5')->getValue());
        $this->assertSame('n', $f->getCell('B5')->getDataType());
    }
}
