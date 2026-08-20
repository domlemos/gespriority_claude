<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Espelha exatamente as linhas {chave, rotulo, total} que
 * RelatorioController::agregar() já monta pro formato JSON — mesma
 * agregação, dois formatos de saída, sem duplicar a query.
 */
class RelatorioIncidentesExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly Collection $linhas) {}

    public function collection(): Collection
    {
        return $this->linhas->map(fn (array $linha) => [$linha['rotulo'], $linha['total']]);
    }

    public function headings(): array
    {
        return ['Categoria', 'Total'];
    }
}
