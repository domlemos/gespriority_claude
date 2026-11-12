<?php

namespace App\Relatorios\Fluxos;

use App\Models\User;
use App\Relatorios\AgregadorRelatorio;
use App\Relatorios\Indicadores\ConsultaIndicadores;
use App\Relatorios\LinhaBase;
use App\Relatorios\RotulosRelatorio;

/**
 * Monta o corpo JSON do mapa de fluxos: nós (com abertos, recebidos,
 * encaminhados e resolvidos) e arestas (passagens origem → destino).
 * Recebidos e encaminhados de um grupo saem das mesmas passagens das
 * arestas, então a soma das setas que entram/saem bate com eles.
 */
final class AgregadorFluxos
{
    /** @return array<string, mixed> */
    public static function agregar(ConsultaIndicadores $c, User $u): array
    {
        $passagens = FonteFluxos::passagens($c, $u);

        $abertos = FonteFluxos::abertos($c, $u)->countBy(fn (LinhaBase $l) => $l->chave)->all();
        $resolvidos = FonteFluxos::resolvidos($c, $u)
            ->groupBy(fn (LinhaBase $l) => $l->chave)
            ->map(fn ($linhas) => $linhas->unique('incidenteId')->count())
            ->all();
        $recebidos = $passagens->countBy('destino')->all();
        $encaminhados = $passagens->countBy('origem')->all();
        $pontas = array_unique(array_map('strval', [...array_keys($recebidos), ...array_keys($encaminhados)]));

        $ehGrupo = fn (string $chave) => ctype_digit($chave);
        $grupos = array_values(array_unique(array_filter(
            $c->grupos !== []
                ? [...array_map('strval', $c->grupos), ...$pontas]
                : [...$pontas, ...array_map('strval', array_keys($abertos)), ...array_map('strval', array_keys($resolvidos))],
            $ehGrupo,
        )));

        $nomes = RotulosRelatorio::grupos(array_map('intval', $grupos));
        $nosDeGrupo = AgregadorRelatorio::ordenar(array_map(fn (string $id) => [
            'chave' => $id,
            'rotulo' => $nomes[(int) $id] ?? "#{$id}",
            'tipo' => 'grupo',
            'abertos' => $abertos[$id] ?? 0,
            'recebidos' => $recebidos[$id] ?? 0,
            'encaminhados' => $encaminhados[$id] ?? 0,
            'resolvidos' => $resolvidos[$id] ?? 0,
            // Só para ordenar por nome (`ordenar` usa `total` e `rotulo`).
            'total' => 0,
        ], $grupos));

        $especial = fn (string $chave, ?int $abertos = null) => [
            'chave' => $chave,
            'rotulo' => TextosFluxos::ROTULOS[$chave],
            'tipo' => $chave,
            'abertos' => $abertos,
            'recebidos' => null,
            'encaminhados' => null,
            'resolvidos' => null,
        ];

        $nos = [$especial(FonteFluxos::CLIENTE, $abertos[FonteFluxos::CLIENTE] ?? 0)];
        foreach ([FonteFluxos::SEM_GRUPO, FonteFluxos::ORIGEM_DESCONHECIDA] as $chave) {
            if (in_array($chave, $pontas, true)) {
                $nos[] = $especial($chave);
            }
        }
        foreach ($nosDeGrupo as $no) {
            unset($no['total']);
            $nos[] = $no;
        }
        if (in_array(FonteFluxos::OUTROS, $pontas, true)) {
            $nos[] = $especial(FonteFluxos::OUTROS);
        }

        $arestas = $passagens
            ->groupBy(fn (Passagem $p) => $p->base->chave)
            ->map(fn ($grupo) => [
                'origem' => $grupo->first()->origem,
                'destino' => $grupo->first()->destino,
                'quantidade' => $grupo->count(),
            ])
            ->values()
            ->all();
        usort($arestas, fn (array $a, array $b) => $b['quantidade'] <=> $a['quantidade']
            ?: strcmp($a['origem'], $b['origem'])
            ?: strcmp($a['destino'], $b['destino']));

        return [
            'titulo' => TextosFluxos::titulo($c),
            'periodo' => ['inicio' => $c->inicio, 'fim' => $c->fim],
            'grupos' => $c->grupos,
            'nos' => $nos,
            'arestas' => $arestas,
            'legenda' => TextosFluxos::LEGENDA,
            'aviso' => TextosFluxos::aviso(FonteFluxos::retroativos($c)),
        ];
    }
}
