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
 * Planilha do mapa de fluxos: os mesmos números e textos do JSON
 * (`AgregadorFluxos::agregar`), em duas tabelas (nós e fluxos). Só valores.
 */
class FluxosExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithStrictNullComparison
{
    /**
     * @param  array<string, mixed>  $corpo  retorno de `AgregadorFluxos::agregar()`
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
        $emBrasilia = $this->geradoEm->copy()->setTimezone(config('app.timezone_exibicao'));
        $rotulos = array_column($b['nos'], 'rotulo', 'chave');

        $linhas = [
            [$b['titulo']],
            ['Gerado em '.$emBrasilia->format('d/m/Y H:i')." (horário de Brasília) por {$this->geradoPor->name}"],
            [''],
            ['Nós'],
            ['Grupo', 'Abertos pelo grupo', 'Recebidos', 'Encaminhados', 'Resolvidos'],
        ];

        // Métrica `null` (nó especial ou vizinho) = célula vazia.
        foreach ($b['nos'] as $no) {
            $linhas[] = [$no['rotulo'], ...array_map(fn (string $m) => $no[$m] ?? '', ['abertos', 'recebidos', 'encaminhados', 'resolvidos'])];
        }

        $linhas[] = [''];
        $linhas[] = ['Fluxos'];
        $linhas[] = ['Origem', 'Destino', 'Quantidade'];
        foreach ($b['arestas'] as $aresta) {
            $linhas[] = [$rotulos[$aresta['origem']] ?? $aresta['origem'], $rotulos[$aresta['destino']] ?? $aresta['destino'], $aresta['quantidade']];
        }

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
