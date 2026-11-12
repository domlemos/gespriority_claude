<?php

namespace App\Relatorios\Indicadores;

use App\Relatorios\RotulosRelatorio;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Título, legenda e aviso do relatório de indicadores. Ficam no backend para
 * que a tela e a planilha digam exatamente a mesma coisa.
 */
final class TextosIndicadores
{
    /** Rótulo de cada indicador, na ordem fixa das colunas. */
    public const ROTULOS = [
        'abertos' => 'Abertos',
        'resolvidos' => 'Resolvidos',
        'fechados' => 'Fechados',
        'cancelados' => 'Cancelados',
    ];

    public const ROTULO_PORTAL = 'Cliente (portal)';

    public const ROTULO_SEM_AUTOR = 'Sem autor registrado';

    public const LEGENDA = [
        'Abertos: chamados criados no período, contados no grupo de quem abriu no momento da abertura. Chamados abertos pelo portal ficam na linha "Cliente (portal)".',
        'Resolvidos: chamados com ao menos uma resolução no período, contados no grupo de quem resolveu no momento.',
        'Fechados: chamados com ao menos um fechamento no período, contados no grupo de quem fechou no momento.',
        'Cancelados: chamados cancelados no período, contados no grupo de quem cancelou no momento.',
        'As colunas são independentes: um chamado resolvido e fechado no período conta em Resolvidos e em Fechados. Um chamado resolvido por dois grupos conta uma vez em cada um.',
    ];

    private const QUEBRAS = ['dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês'];

    public static function titulo(ConsultaIndicadores $c): string
    {
        $titulo = 'Indicadores por grupo de solução — de '.self::data($c->inicio).' a '.self::data($c->fim);

        if (isset(self::QUEBRAS[$c->quebra])) {
            $titulo .= ', por '.self::QUEBRAS[$c->quebra];
        }

        if ($c->grupos !== []) {
            $nomes = RotulosRelatorio::grupos($c->grupos);
            $titulo .= ' — grupos: '.implode(', ', array_map(fn (int $id) => $nomes[$id] ?? "#{$id}", $c->grupos));
        }

        if ($c->porIntegrante) {
            $titulo .= ' — por integrante';
        }

        return $titulo;
    }

    /**
     * Aviso de dados reconstruídos pelo backfill, ou `null` se não há evento
     * retroativo no período (de qualquer grupo, ver `FonteIndicadores::retroativos`).
     *
     * @param  array<int, string>  $indicadores  indicadores com evento retroativo no período (qualquer ordem)
     * @param  CarbonInterface|null  $ultimo  instante (UTC) do retroativo mais recente no período
     */
    public static function aviso(array $indicadores, ?CarbonInterface $ultimo): ?string
    {
        if ($indicadores === [] || $ultimo === null) {
            return null;
        }

        $rotulos = array_values(array_intersect_key(self::ROTULOS, array_flip($indicadores)));
        $ultimoRotulo = array_pop($rotulos);
        $colunas = $rotulos === [] ? $ultimoRotulo : implode(', ', $rotulos).' e '.$ultimoRotulo;
        $data = Carbon::instance($ultimo)->setTimezone(config('app.timezone_exibicao'))->format('d/m/Y');

        return "Dados aproximados em {$colunas}: registros até {$data} foram reconstruídos e usam o grupo atual da pessoa, não o da época.";
    }

    private static function data(string $ymd): string
    {
        return Carbon::createFromFormat('Y-m-d', $ymd)->format('d/m/Y');
    }
}
