<?php

namespace App\Relatorios\Indicadores;

use App\Relatorios\LinhaBase;

/**
 * Um evento contável do relatório de indicadores: em qual indicador entra,
 * em qual linha (`chave` = id do grupo, id do usuário, `'portal'` ou
 * `'__sem_autor__'`) e se foi reconstruído pelo backfill (`retroativo`).
 */
final readonly class LinhaIndicador
{
    public function __construct(
        public string $indicador,
        public int|string $chave,
        public bool $retroativo,
        public LinhaBase $base,
    ) {}
}
