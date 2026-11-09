<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Converte `data_inicio`/`data_fim` (Y-m-d, inclusivos) dos relatórios em
 * limites UTC. As datas são dias de Brasília (config('app.timezone_exibicao')),
 * mas as colunas são gravadas em UTC — `whereDate` compararia a data UTC e
 * deslocaria 3h, jogando eventos de 21h–23h59 BRT pro dia seguinte. Uso:
 * `where($col, '>=', inicioUtc($d))` e `where($col, '<', fimExclusivoUtc($d))`.
 */
final class PeriodoRelatorio
{
    /** 00:00 de Brasília do dia informado, em UTC. */
    public static function inicioUtc(string $data): Carbon
    {
        return Carbon::parse($data, config('app.timezone_exibicao'))->startOfDay()->utc();
    }

    /** 00:00 de Brasília do dia seguinte ao informado, em UTC (limite exclusivo, torna `data_fim` inclusivo). */
    public static function fimExclusivoUtc(string $data): Carbon
    {
        return Carbon::parse($data, config('app.timezone_exibicao'))->startOfDay()->addDay()->utc();
    }
}
