<?php

namespace App\Relatorios;

use App\Support\PeriodoRelatorio;
use Collator;
use Illuminate\Support\Collection;

/**
 * Transforma linhas-base em tabela linha × período, igual para todas as
 * fontes. As regras de total (spec 6A.5) ficam só aqui:
 * - célula = chamados distintos da linha naquele período (ou eventos, quando
 *   a fonte conta ações);
 * - total da linha/coluna/geral = somas das células;
 * - % = total da linha ÷ total geral.
 */
final class AgregadorRelatorio
{
    /**
     * @param  Collection<int, LinhaBase>  $linhas
     * @param  Collection<int|string, string>|null  $zerados  `[chave => rótulo]` que entram com 0 se não aparecerem
     *                                                        (membros ociosos do grupo do analista)
     */
    public static function agregar(ConsultaRelatorio $c, Collection $linhas, FonteRelatorio $fonte, ?Collection $zerados = null): ResultadoRelatorio
    {
        $colunas = $c->temPeriodo()
            ? PeriodoRelatorio::colunas($c->inicio, $c->fim, $c->quebra)
            : ['total' => 'Total'];
        $zeroPorColuna = array_fill_keys(array_keys($colunas), 0);

        // [chave][coluna][id contado] = true — conjunto, para contar distintos.
        $contados = [];
        $chamados = [];
        $contaEventos = $fonte->contaEventos();

        foreach ($linhas as $linha) {
            $chave = $linha->chave ?? RotulosRelatorio::CHAVE_VAZIA;
            $coluna = PeriodoRelatorio::colunaDe($linha->data, $c->quebra);

            // A fonte já recorta pelo período; isto só protege contra borda.
            if (! isset($zeroPorColuna[$coluna])) {
                continue;
            }

            $id = $contaEventos ? $linha->eventoId : $linha->incidenteId;
            $contados[$chave][$coluna][$id] = true;
            $chamados[$linha->incidenteId] = true;
        }

        $rotulos = $contados === [] ? [] : $fonte->rotulos($c, array_keys($contados));
        foreach ($zerados ?? [] as $chave => $rotulo) {
            if (! isset($contados[$chave])) {
                $contados[$chave] = [];
                $rotulos[$chave] = $rotulo;
            }
        }

        $tabela = [];
        foreach ($contados as $chave => $porColuna) {
            $celulas = $zeroPorColuna;
            foreach ($porColuna as $coluna => $ids) {
                $celulas[$coluna] = count($ids);
            }

            $tabela[] = [
                'chave' => $chave,
                'rotulo' => $rotulos[$chave] ?? "#{$chave}",
                'celulas' => $celulas,
                'total' => array_sum($celulas),
            ];
        }

        $totalColunas = $zeroPorColuna;
        foreach ($tabela as $linha) {
            foreach ($linha['celulas'] as $coluna => $n) {
                $totalColunas[$coluna] += $n;
            }
        }
        $totalGeral = array_sum($totalColunas);

        $tabela = array_map(fn (array $linha) => [
            ...$linha,
            'percentual' => $totalGeral === 0 ? 0.0 : round($linha['total'] / $totalGeral * 100, 1),
        ], $tabela);

        return new ResultadoRelatorio(
            consulta: $c,
            colunas: array_map(fn ($chave, $rotulo) => ['chave' => (string) $chave, 'rotulo' => $rotulo], array_keys($colunas), $colunas),
            linhas: self::ordenar($tabela),
            totais: ['celulas' => $totalColunas, 'total' => $totalGeral],
            chamadosDistintos: count($chamados),
        );
    }

    /**
     * Total desc, depois rótulo sem diferenciar maiúsculas/acentos ("Ápice"
     * entre "Acesso" e "banco"). `Collator` vem da extensão `intl`; sem ela,
     * cai para `strcasecmp`, que ao menos ignora maiúsculas.
     */
    private static function ordenar(array $tabela): array
    {
        $collator = class_exists(Collator::class) ? new Collator('pt_BR') : null;
        $collator?->setStrength(Collator::PRIMARY);

        usort($tabela, fn (array $a, array $b) => $b['total'] <=> $a['total']
            ?: ($collator ? $collator->compare($a['rotulo'], $b['rotulo']) : strcasecmp($a['rotulo'], $b['rotulo'])));

        return $tabela;
    }
}
