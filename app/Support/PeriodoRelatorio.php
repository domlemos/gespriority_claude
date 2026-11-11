<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Converte `data_inicio`/`data_fim` (Y-m-d, inclusivos) dos relatórios em
 * limites UTC. As datas são dias de Brasília (config('app.timezone_exibicao')),
 * mas as colunas são gravadas em UTC — `whereDate` compararia a data UTC e
 * deslocaria 3h, jogando eventos de 21h–23h59 BRT pro dia seguinte. Uso:
 * `where($col, '>=', inicioUtc($d))` e `where($col, '<', fimExclusivoUtc($d))`.
 */
final class PeriodoRelatorio
{
    /** Atalhos de período aceitos em `periodo`. */
    public const ATALHOS = ['este_mes', 'mes_passado', 'ultimos_7_dias'];

    /** Quebras de coluna aceitas em `quebra`. */
    public const QUEBRAS = ['nenhuma', 'dia', 'semana', 'mes'];

    /** Máximo de colunas por quebra, para a resposta e a planilha não explodirem. */
    public const LIMITE_COLUNAS = ['dia' => 62, 'semana' => 53, 'mes' => 24];

    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    /**
     * Resolve um atalho em [início, fim] (Y-m-d). "Hoje" é o dia de Brasília,
     * não o de UTC: às 23h30 BRT o servidor já está no dia seguinte em UTC.
     *
     * @return array{0: string, 1: string}
     */
    public static function resolverAtalho(string $atalho, ?CarbonInterface $agora = null): array
    {
        $tz = config('app.timezone_exibicao');
        $hoje = Carbon::instance($agora ?? Carbon::now())->setTimezone($tz)->startOfDay();

        return match ($atalho) {
            'este_mes' => [$hoje->copy()->startOfMonth()->toDateString(), $hoje->toDateString()],
            'mes_passado' => [
                $hoje->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $hoje->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'ultimos_7_dias' => [$hoje->copy()->subDays(6)->toDateString(), $hoje->toDateString()],
        };
    }

    /**
     * Colunas cronológicas do período: chave = Y-m-d do início do balde (semana
     * começa na segunda), valor = rótulo. Sem quebra, uma única coluna "total".
     *
     * @return array<string, string>
     */
    public static function colunas(string $inicio, string $fim, string $quebra): array
    {
        if ($quebra === 'nenhuma') {
            return ['total' => 'Total'];
        }

        $tz = config('app.timezone_exibicao');
        $cursor = self::inicioDoBalde(Carbon::parse($inicio, $tz), $quebra);
        $ultimo = Carbon::parse($fim, $tz)->startOfDay();
        $colunas = [];

        while ($cursor->lte($ultimo)) {
            $colunas[$cursor->toDateString()] = self::rotulo($cursor, $quebra);
            match ($quebra) {
                'dia' => $cursor->addDay(),
                'semana' => $cursor->addWeek(),
                'mes' => $cursor->addMonthNoOverflow(),
            };
        }

        return $colunas;
    }

    /** Chave do balde (como em `colunas`) de um instante UTC, medido no dia de Brasília. */
    public static function colunaDe(CarbonInterface $dataUtc, string $quebra): string
    {
        if ($quebra === 'nenhuma') {
            return 'total';
        }

        $local = Carbon::instance($dataUtc)->setTimezone(config('app.timezone_exibicao'));

        return self::inicioDoBalde($local, $quebra)->toDateString();
    }

    private static function inicioDoBalde(Carbon $data, string $quebra): Carbon
    {
        $data = $data->copy()->startOfDay();

        return match ($quebra) {
            'semana' => $data->startOfWeek(Carbon::MONDAY),
            'mes' => $data->startOfMonth(),
            default => $data,
        };
    }

    private static function rotulo(Carbon $data, string $quebra): string
    {
        return match ($quebra) {
            'dia' => $data->format('d/m'),
            'semana' => 'Sem '.$data->format('d/m'),
            'mes' => self::MESES[$data->month - 1].'/'.$data->year,
        };
    }

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
