<?php

namespace App\Exports;

use App\Models\User;
use App\Relatorios\TituloRelatorio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Planilha da lista de chamados por trás de uma linha/célula do relatório.
 * Mesmo estilo de `RelatorioTabelaExport`: título, linha/coluna e autoria no
 * topo para o arquivo fazer sentido sozinho; só valores.
 */
class RelatorioChamadosExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithStrictNullComparison
{
    /**
     * @param  list<array<string, mixed>>  $itens  itens de `ListaChamados::montar()`
     */
    public function __construct(
        private readonly string $titulo,
        private readonly string $descricaoLinha,
        private readonly array $itens,
        private readonly bool $comDestino,
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
        $fuso = config('app.timezone_exibicao');
        $cabecalho = ['Nº', 'Título', 'Status', 'Data', 'Analista', 'Grupo de Solução'];

        $linhas = [
            [$this->titulo],
            [$this->descricaoLinha],
            ['Gerado em '.$this->geradoEm->copy()->setTimezone($fuso)->format('d/m/Y H:i')." (horário de Brasília) por {$this->geradoPor->name}"],
            [''],
            $this->comDestino ? [...$cabecalho, 'Destino'] : $cabecalho,
        ];

        foreach ($this->itens as $item) {
            $linha = [
                $item['incidente_id'],
                $item['titulo'],
                TituloRelatorio::STATUS[$item['status']] ?? $item['status'],
                CarbonImmutable::parse($item['data'])->setTimezone($fuso)->format('d/m/Y H:i'),
                $item['autor']['name'] ?? '',
                $item['grupo_chamado']['nome'] ?? '',
            ];
            $linhas[] = $this->comDestino ? [...$linha, $item['destino'] ?? ''] : $linha;
        }

        return $linhas;
    }
}
