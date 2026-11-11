<?php

namespace App\Exports;

use App\Models\User;
use App\Relatorios\ResultadoRelatorio;
use App\Relatorios\TituloRelatorio;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Planilha do caminho novo: título, autoria e data no topo para que o
 * arquivo faça sentido sozinho, fora do sistema. Só valores — sem fórmulas —
 * e os mesmos números do JSON (um único `ResultadoRelatorio`).
 */
class RelatorioTabelaExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithStrictNullComparison
{
    public function __construct(
        private readonly string $titulo,
        private readonly ResultadoRelatorio $resultado,
        private readonly User $geradoPor,
        private readonly CarbonInterface $geradoEm,
    ) {}

    /** Todo texto vira string (um título `=1+1` não pode virar fórmula); números seguem numéricos. */
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function array(): array
    {
        $r = $this->resultado;
        $comQuebra = $r->consulta->quebra !== 'nenhuma';
        $colunas = $comQuebra ? $r->colunas() : [];
        $emBrasilia = $this->geradoEm->copy()->setTimezone(config('app.timezone_exibicao'));

        $rotulo = TituloRelatorio::AGRUPAMENTOS[$r->consulta->agruparPor];
        $linhas = [
            [$this->titulo],
            ['Gerado em '.$emBrasilia->format('d/m/Y H:i')." (horário de Brasília) por {$this->geradoPor->name}"],
            [''],
            [
                mb_strtoupper(mb_substr($rotulo, 0, 1)).mb_substr($rotulo, 1),
                ...array_column($colunas, 'rotulo'),
                'Total',
                '%',
            ],
        ];

        foreach ($r->linhas() as $linha) {
            $linhas[] = [
                $linha['rotulo'],
                ...array_map(fn (array $c) => $linha['celulas'][$c['chave']] ?? 0, $colunas),
                $linha['total'],
                $linha['percentual'],
            ];
        }

        $totais = $r->totais();
        $linhas[] = [
            'Total',
            ...array_map(fn (array $c) => $totais['celulas'][$c['chave']] ?? 0, $colunas),
            $totais['total'],
            $totais['total'] > 0 ? 100.0 : 0.0,
        ];

        // Mesma regra do rodapé da UI: só aparece quando difere do total geral.
        if ($r->chamadosDistintos() !== $totais['total']) {
            $linhas[] = ["Chamados distintos no resultado: {$r->chamadosDistintos()}"];
        }

        return $linhas;
    }
}
