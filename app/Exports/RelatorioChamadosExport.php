<?php

namespace App\Exports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;

/**
 * Planilha da lista de chamados por trás de uma linha/célula do relatório.
 * Mesmo estilo de `RelatorioTabelaExport`: título, linha/coluna e autoria no
 * topo para o arquivo fazer sentido sozinho; só valores.
 */
class RelatorioChamadosExport implements FromArray
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

    public function array(): array
    {
        $fuso = config('app.timezone_exibicao');
        $cabecalho = ['Nº', 'Título', 'Status', 'Data', 'Analista', 'Grupo do chamado'];

        $linhas = [
            [$this->titulo],
            [$this->descricaoLinha],
            ['Gerado em '.$this->geradoEm->copy()->setTimezone($fuso)->format('d/m/Y H:i')." (horário de Brasília) por {$this->geradoPor->name}"],
            [],
            $this->comDestino ? [...$cabecalho, 'Destino'] : $cabecalho,
        ];

        foreach ($this->itens as $item) {
            $linha = [
                $item['incidente_id'],
                $item['titulo'],
                $item['status'],
                CarbonImmutable::parse($item['data'])->setTimezone($fuso)->format('d/m/Y H:i'),
                $item['autor']['name'] ?? '',
                $item['grupo_chamado']['nome'] ?? '',
            ];
            $linhas[] = $this->comDestino ? [...$linha, $item['destino'] ?? ''] : $linha;
        }

        return $linhas;
    }
}
