<?php

namespace App\Relatorios\Fluxos;

use App\Relatorios\Indicadores\ConsultaIndicadores;
use App\Relatorios\RotulosRelatorio;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Título, rótulos dos nós especiais, legenda e aviso do mapa de fluxos.
 * Ficam no backend para que a tela, a imagem e a planilha digam o mesmo.
 */
final class TextosFluxos
{
    /** Rótulo de cada nó especial, pela chave (que também é o `tipo` do nó). */
    public const ROTULOS = [
        FonteFluxos::CLIENTE => 'Cliente',
        FonteFluxos::SEM_GRUPO => 'Sem grupo',
        FonteFluxos::ORIGEM_DESCONHECIDA => 'Origem desconhecida',
        FonteFluxos::OUTROS => 'Outros grupos',
    ];

    public const LEGENDA = [
        'Abertos pelo grupo: chamados criados no período por integrantes do grupo.',
        'Recebidos: chamados que entraram no grupo vindos do cliente ou de outro grupo, por encaminhamento ou abertos diretamente para ele.',
        'Encaminhados: encaminhamentos que saíram do grupo para outro grupo. Um chamado que vai e volta conta cada passagem.',
        'Resolvidos: chamados resolvidos enquanto estavam no grupo, por qualquer analista. A produção por analista está em Indicadores por grupo.',
        'As setas contam passagens no período. Recebidos menos encaminhados menos resolvidos não é o número de chamados em aberto.',
    ];

    public static function titulo(ConsultaIndicadores $c): string
    {
        $titulo = 'Mapa de fluxos — de '.self::data($c->inicio).' a '.self::data($c->fim);

        if ($c->grupos !== []) {
            $nomes = RotulosRelatorio::grupos($c->grupos);
            $titulo .= ' — grupos: '.implode(', ', array_map(fn (int $id) => $nomes[$id] ?? "#{$id}", $c->grupos));
        }

        return $titulo;
    }

    /** @param  CarbonInterface|null  $ultimo  instante (UTC) do retroativo mais recente no período */
    public static function aviso(?CarbonInterface $ultimo): ?string
    {
        if ($ultimo === null) {
            return null;
        }

        $data = Carbon::instance($ultimo)->setTimezone(config('app.timezone_exibicao'))->format('d/m/Y');

        return "Dados aproximados: registros até {$data} foram reconstruídos; nesses casos a origem de um encaminhamento pode aparecer como desconhecida e o grupo de quem abriu é o grupo atual da pessoa.";
    }

    private static function data(string $ymd): string
    {
        return Carbon::createFromFormat('Y-m-d', $ymd)->format('d/m/Y');
    }
}
