<?php

namespace App\Exports;

use App\Models\User;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Planilha do relatório de indicadores por grupo: os mesmos números e textos
 * do JSON (`AgregadorIndicadores::agregar`). Só valores, sem fórmulas.
 */
class IndicadoresExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithStrictNullComparison
{
    /**
     * @param  array<string, mixed>  $corpo  retorno de `AgregadorIndicadores::agregar()`
     */
    public function __construct(
        private readonly array $corpo,
        private readonly User $geradoPor,
        private readonly CarbonInterface $geradoEm,
    ) {}

    /** Todo texto vira string (um grupo `=1+1` não pode virar fórmula); números seguem numéricos. */
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
        $b = $this->corpo;
        $comQuebra = $b['quebra'] !== 'nenhuma';
        $colunas = $comQuebra ? $b['colunas'] : [];
        $emBrasilia = $this->geradoEm->copy()->setTimezone(config('app.timezone_exibicao'));

        $cabecalho = [$b['por_integrante'] ? 'Integrante' : 'Grupo'];
        foreach ($b['indicadores'] as $ind) {
            array_push($cabecalho, ...[...array_column($colunas, 'rotulo'), "{$ind['rotulo']} — Total"]);
        }

        // Linha de valores de um bloco {indicador => {celulas, total}}, na ordem do cabeçalho.
        $valores = function (array $porIndicador) use ($b, $colunas): array {
            $saida = [];
            foreach ($b['indicadores'] as $ind) {
                foreach ($colunas as $col) {
                    $saida[] = $porIndicador[$ind['chave']]['celulas'][$col['chave']] ?? 0;
                }
                $saida[] = $porIndicador[$ind['chave']]['total'];
            }

            return $saida;
        };

        $linhas = [
            [$b['titulo']],
            ['Gerado em '.$emBrasilia->format('d/m/Y H:i')." (horário de Brasília) por {$this->geradoPor->name}"],
            [''],
            $cabecalho,
        ];

        foreach ($b['linhas'] as $linha) {
            $linhas[] = [$linha['rotulo'], ...$valores($linha['valores'])];
        }

        $linhas[] = ['Total', ...$valores($b['totais'])];

        $distintos = [];
        foreach ($b['indicadores'] as $ind) {
            array_push($distintos, ...[...array_fill(0, count($colunas), ''), $b['distintos'][$ind['chave']]]);
        }
        $linhas[] = ['Chamados distintos', ...$distintos];

        $linhas[] = [''];
        $linhas[] = ['Legenda'];
        foreach ($b['legenda'] as $texto) {
            $linhas[] = [$texto];
        }

        if ($b['aviso'] !== null) {
            $linhas[] = [$b['aviso']];
        }

        return $linhas;
    }
}
