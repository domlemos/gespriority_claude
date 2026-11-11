<?php

namespace App\Relatorios;

use Carbon\CarbonImmutable;

/**
 * Uma ocorrência contável de um relatório, já reduzida ao mínimo que o
 * agregador precisa: a chave do agrupamento, o chamado (para "chamados
 * distintos"), o evento (para fontes que contam ações) e a data (para a
 * coluna do período). As fontes nunca entregam models hidratados — só isto.
 */
final readonly class LinhaBase
{
    public function __construct(
        public int|string|null $chave,
        public int $incidenteId,
        public ?int $eventoId,
        public ?int $autorId,
        public CarbonImmutable $data,
    ) {}
}
