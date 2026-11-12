<?php

namespace App\Relatorios\Indicadores;

use App\Models\GrupoSolucao;
use App\Models\User;
use App\Relatorios\AgregadorRelatorio;
use App\Relatorios\RotulosRelatorio;
use App\Support\PeriodoRelatorio;
use Illuminate\Support\Collection;

/**
 * Monta o corpo JSON do relatório de indicadores: linha × indicador × coluna
 * de período. Regras de contagem:
 * - célula = chamados distintos da linha, no indicador, naquela coluna;
 * - total da linha = soma das células; total da coluna = soma das linhas;
 * - `distintos[indicador]` = chamados distintos do indicador no resultado
 *   (um chamado resolvido por dois grupos conta 2 nos totais e 1 aqui).
 */
final class AgregadorIndicadores
{
    /**
     * @param  Collection<int, LinhaIndicador>  $linhas
     * @return array<string, mixed>
     */
    public static function agregar(ConsultaIndicadores $c, Collection $linhas, User $u): array
    {
        $colunas = PeriodoRelatorio::colunas($c->inicio, $c->fim, $c->quebra);
        $zeroPorColuna = array_fill_keys(array_keys($colunas), 0);
        $indicadores = array_keys(FonteIndicadores::TIPOS);

        // [chave][indicador][coluna][incidente] = true — conjuntos, para contar distintos.
        $contados = [];
        $distintos = array_fill_keys($indicadores, []);
        $retroativos = [];
        $ultimoRetroativo = null;

        foreach ($linhas as $linha) {
            $coluna = PeriodoRelatorio::colunaDe($linha->base->data, $c->quebra);

            // A fonte já recorta pelo período; isto só protege contra borda.
            if (! isset($zeroPorColuna[$coluna])) {
                continue;
            }

            $incidente = $linha->base->incidenteId;
            $contados[$linha->chave][$linha->indicador][$coluna][$incidente] = true;
            $distintos[$linha->indicador][$incidente] = true;

            if ($linha->retroativo) {
                $retroativos[$linha->indicador] = true;
                if ($ultimoRetroativo === null || $linha->base->data->greaterThan($ultimoRetroativo)) {
                    $ultimoRetroativo = $linha->base->data;
                }
            }
        }

        $valores = function (int|string $chave) use ($contados, $indicadores, $zeroPorColuna): array {
            $porIndicador = [];
            foreach ($indicadores as $indicador) {
                $celulas = $zeroPorColuna;
                foreach ($contados[$chave][$indicador] ?? [] as $coluna => $ids) {
                    $celulas[$coluna] = count($ids);
                }
                $porIndicador[$indicador] = ['celulas' => $celulas, 'total' => array_sum($celulas)];
            }

            return $porIndicador;
        };

        $tipo = $c->porIntegrante ? 'usuario' : 'grupo';
        $principais = [];
        foreach (self::rotulosPrincipais($c, $contados, $u) as $chave => $rotulo) {
            $v = $valores($chave);
            $principais[] = [
                'chave' => (string) $chave,
                'rotulo' => $rotulo,
                'tipo' => $tipo,
                'valores' => $v,
                // Só para ordenar (`AgregadorRelatorio::ordenar` usa `total` e `rotulo`).
                'total' => array_sum(array_column($v, 'total')),
            ];
        }
        $principais = array_map(function (array $l) {
            unset($l['total']);

            return $l;
        }, AgregadorRelatorio::ordenar($principais));

        $tabela = [...$principais, [
            'chave' => FonteIndicadores::CHAVE_PORTAL,
            'rotulo' => TextosIndicadores::ROTULO_PORTAL,
            'tipo' => 'portal',
            'valores' => $valores(FonteIndicadores::CHAVE_PORTAL),
        ]];

        // Com grupos pedidos a fonte nem traz eventos sem autor; sem pedido, só aparece se não zerada.
        if (isset($contados[FonteIndicadores::CHAVE_SEM_AUTOR])) {
            $tabela[] = [
                'chave' => FonteIndicadores::CHAVE_SEM_AUTOR,
                'rotulo' => TextosIndicadores::ROTULO_SEM_AUTOR,
                'tipo' => 'sem_autor',
                'valores' => $valores(FonteIndicadores::CHAVE_SEM_AUTOR),
            ];
        }

        $totais = [];
        foreach ($indicadores as $indicador) {
            $celulas = $zeroPorColuna;
            foreach ($tabela as $linha) {
                foreach ($linha['valores'][$indicador]['celulas'] as $coluna => $n) {
                    $celulas[$coluna] += $n;
                }
            }
            $totais[$indicador] = ['celulas' => $celulas, 'total' => array_sum($celulas)];
        }

        return [
            'titulo' => TextosIndicadores::titulo($c),
            'periodo' => ['inicio' => $c->inicio, 'fim' => $c->fim],
            'quebra' => $c->quebra,
            'grupos' => $c->grupos,
            'por_integrante' => $c->porIntegrante,
            'indicadores' => array_map(
                fn (string $chave) => ['chave' => $chave, 'rotulo' => TextosIndicadores::ROTULOS[$chave]],
                $indicadores,
            ),
            'colunas' => array_map(
                fn ($chave, $rotulo) => ['chave' => (string) $chave, 'rotulo' => $rotulo],
                array_keys($colunas),
                $colunas,
            ),
            'linhas' => $tabela,
            'totais' => $totais,
            'distintos' => array_map('count', $distintos),
            'legenda' => TextosIndicadores::LEGENDA,
            'aviso' => TextosIndicadores::aviso(array_keys($retroativos), $ultimoRetroativo),
        ];
    }

    /**
     * `[chave => rótulo]` das linhas de grupo (ou de integrante), antes de ordenar:
     * - por integrante: quem tem evento contado no grupo (inclusive excluídos e
     *   quem já saiu dele) + membros atuais não excluídos, zerados;
     * - com grupos: exatamente os pedidos, mesmo zerados;
     * - sem grupos: grupos visíveis ativos + os que têm evento contado (a fonte
     *   já aplicou a visibilidade).
     *
     * @param  array<int|string, mixed>  $contados
     * @return array<int, string>
     */
    private static function rotulosPrincipais(ConsultaIndicadores $c, array $contados, User $u): array
    {
        $comEvento = array_values(array_filter(array_keys($contados), 'is_int'));

        if ($c->porIntegrante) {
            $membros = User::query()->where('grupo_solucao_id', $c->grupos[0])->pluck('id')->all();

            return User::withTrashed()
                ->whereIn('id', array_unique([...$comEvento, ...$membros]))
                ->get(['id', 'name', 'deleted_at'])
                ->mapWithKeys(fn (User $p) => [$p->id => $p->trashed() ? "{$p->name} (excluído)" : $p->name])
                ->all();
        }

        if ($c->grupos !== []) {
            $nomes = RotulosRelatorio::grupos($c->grupos);

            return array_combine($c->grupos, array_map(fn (int $id) => $nomes[$id] ?? "#{$id}", $c->grupos));
        }

        $visiveis = $u->visibleGrupoSolucaoIds();
        $ativos = GrupoSolucao::query()
            ->where('ativo', true)
            ->when($visiveis !== null, fn ($q) => $q->whereIn('id', $visiveis))
            ->pluck('id')
            ->all();
        $ids = array_values(array_unique([...$ativos, ...$comEvento]));
        $nomes = RotulosRelatorio::grupos($ids);

        return array_combine($ids, array_map(fn (int $id) => $nomes[$id] ?? "#{$id}", $ids));
    }
}
