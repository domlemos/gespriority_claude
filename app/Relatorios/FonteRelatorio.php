<?php

namespace App\Relatorios;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * De onde vêm as linhas de um `contar`: `incidentes` (abertos, cancelados)
 * ou `incidente_eventos` (concluídos, resolvidos, fechados, encaminhamentos).
 * A fonte decide base, data, filtros e visibilidade; o agregador só conta.
 */
interface FonteRelatorio
{
    /**
     * Linhas-base já filtradas pelo período, filtros e visibilidade do usuário.
     *
     * @return Collection<int, LinhaBase>
     */
    public function linhas(ConsultaRelatorio $c, User $u): Collection;

    /**
     * Rótulo de cada chave do agrupamento (inclui `RotulosRelatorio::CHAVE_VAZIA`).
     *
     * @param  array<int, int|string>  $chaves
     * @return array<int|string, string>
     */
    public function rotulos(ConsultaRelatorio $c, array $chaves): array;

    /** `true` quando a célula conta eventos (ações), não chamados distintos. */
    public function contaEventos(): bool;
}
