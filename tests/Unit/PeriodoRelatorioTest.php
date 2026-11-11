<?php

namespace Tests\Unit;

use App\Support\PeriodoRelatorio;
use Carbon\Carbon;
use Tests\TestCase;

class PeriodoRelatorioTest extends TestCase
{
    public function test_atalhos_resolve_in_brasilia(): void
    {
        $agora = Carbon::parse('2026-11-01 02:30', 'UTC');

        $this->assertSame(['2026-10-01', '2026-10-31'], PeriodoRelatorio::resolverAtalho('este_mes', $agora));
        $this->assertSame(['2026-09-01', '2026-09-30'], PeriodoRelatorio::resolverAtalho('mes_passado', $agora));
        $this->assertSame(['2026-10-25', '2026-10-31'], PeriodoRelatorio::resolverAtalho('ultimos_7_dias', $agora));
    }

    public function test_colunas_por_semana_start_on_monday(): void
    {
        $this->assertSame(
            ['2026-09-28' => 'Sem 28/09', '2026-10-05' => 'Sem 05/10', '2026-10-12' => 'Sem 12/10'],
            PeriodoRelatorio::colunas('2026-10-01', '2026-10-14', 'semana'),
        );
    }

    public function test_colunas_por_mes_and_dia(): void
    {
        $this->assertSame(
            ['2026-09-01' => 'Set/2026', '2026-10-01' => 'Out/2026', '2026-11-01' => 'Nov/2026'],
            PeriodoRelatorio::colunas('2026-09-15', '2026-11-02', 'mes'),
        );
        $this->assertSame(
            ['2026-10-30' => '30/10', '2026-10-31' => '31/10', '2026-11-01' => '01/11'],
            PeriodoRelatorio::colunas('2026-10-30', '2026-11-01', 'dia'),
        );
        $this->assertSame(['total' => 'Total'], PeriodoRelatorio::colunas('2026-10-30', '2026-11-01', 'nenhuma'));
    }

    public function test_coluna_de_uses_brasilia_day(): void
    {
        $d = Carbon::parse('2026-11-01 02:30', 'UTC');

        $this->assertSame('2026-10-01', PeriodoRelatorio::colunaDe($d, 'mes'));
        $this->assertSame('2026-10-31', PeriodoRelatorio::colunaDe($d, 'dia'));
        $this->assertSame('2026-10-26', PeriodoRelatorio::colunaDe($d, 'semana'));
        $this->assertSame('total', PeriodoRelatorio::colunaDe($d, 'nenhuma'));
    }
}
