<?php

namespace App\Relatorios;

/**
 * Tabela pronta do caminho novo: a mesma estrutura serve à resposta JSON, à
 * planilha e ao título, para que as três saídas mostrem os mesmos números.
 */
final readonly class ResultadoRelatorio
{
    /**
     * @param  array<int, array{chave: string, rotulo: string}>  $colunas  em ordem cronológica
     * @param  array<int, array{chave: int|string, rotulo: string, celulas: array<string, int>, total: int, percentual: float}>  $linhas  já ordenadas
     * @param  array{celulas: array<string, int>, total: int}  $totais
     */
    public function __construct(
        public ConsultaRelatorio $consulta,
        private array $colunas,
        private array $linhas,
        private array $totais,
        private int $chamadosDistintos,
        public string $titulo = '',
    ) {}

    /** @return array<int, array{chave: string, rotulo: string}> */
    public function colunas(): array
    {
        return $this->colunas;
    }

    /** @return array<int, array{chave: int|string, rotulo: string, celulas: array<string, int>, total: int, percentual: float}> */
    public function linhas(): array
    {
        return $this->linhas;
    }

    /** @return array{celulas: array<string, int>, total: int} */
    public function totais(): array
    {
        return $this->totais;
    }

    /**
     * Chamados distintos em todas as linhas-base. Difere do total geral quando
     * um chamado cai em mais de uma célula (spec 6A.5) — a UI só mostra o
     * rodapé nesse caso, mas o número vai sempre.
     */
    public function chamadosDistintos(): int
    {
        return $this->chamadosDistintos;
    }

    /** Cópia com a frase de título (montada à parte, depois da agregação). */
    public function comTitulo(string $titulo): self
    {
        return new self($this->consulta, $this->colunas, $this->linhas, $this->totais, $this->chamadosDistintos, $titulo);
    }

    public function toArray(): array
    {
        $c = $this->consulta;

        return [
            'titulo' => $this->titulo,
            'contar' => $c->contar,
            'agrupar_por' => $c->agruparPor,
            'quebra' => $c->quebra,
            'periodo' => $c->temPeriodo() ? ['inicio' => $c->inicio, 'fim' => $c->fim] : null,
            'colunas' => $this->colunas,
            'linhas' => $this->linhas,
            'totais' => $this->totais,
            'chamados_distintos' => $this->chamadosDistintos,
        ];
    }
}
