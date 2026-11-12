<?php

namespace App\Relatorios\Indicadores;

use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use App\Relatorios\LinhaBase;
use App\Support\PeriodoRelatorio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eventos que entram no relatório de indicadores, numa única consulta enxuta
 * sobre `incidente_eventos` (sem hidratar models). O número de um evento vai
 * sempre para o grupo do autor no momento (`autor_grupo_solucao_id`), nunca
 * para o grupo atual do chamado ou da pessoa.
 */
final class FonteIndicadores
{
    /** Indicador → tipo de evento, na ordem fixa das colunas. */
    public const TIPOS = [
        'abertos' => IncidenteEvento::TIPO_ABERTO,
        'resolvidos' => IncidenteEvento::TIPO_RESOLVIDO,
        'fechados' => IncidenteEvento::TIPO_FECHADO,
        'cancelados' => IncidenteEvento::TIPO_CANCELADO,
    ];

    /** Aberturas feitas por usuário do portal (`autor_customer_id`). */
    public const CHAVE_PORTAL = 'portal';

    /** Eventos sem autor, ou de autor cujo grupo no momento é nulo (grupo excluído). */
    public const CHAVE_SEM_AUTOR = '__sem_autor__';

    /** @return Collection<int, LinhaIndicador> */
    public static function linhas(ConsultaIndicadores $c, User $u): Collection
    {
        $indicadorDoTipo = array_flip(self::TIPOS);

        return self::consulta($c, $u)
            ->get()
            ->map(fn (object $e) => new LinhaIndicador(
                indicador: $indicadorDoTipo[$e->tipo],
                chave: self::chaveDo($e, $c->porIntegrante),
                retroativo: (bool) $e->retroativo,
                base: new LinhaBase(
                    chave: null,
                    incidenteId: (int) $e->incidente_id,
                    eventoId: (int) $e->id,
                    autorId: $e->user_id === null ? null : (int) $e->user_id,
                    data: CarbonImmutable::parse($e->created_at, 'UTC'),
                ),
            ));
    }

    /**
     * Três ramos, cada um com a sua regra de grupo e de visibilidade:
     * - agente: grupo do autor ∈ grupos pedidos (ou ∈ visíveis, sem pedido);
     * - portal: só `aberto`; com grupos, pelo grupo do chamado no momento;
     * - sem autor: só sem grupos pedidos.
     * Portal e sem autor não têm grupo de autor: valem se o chamado é visível hoje.
     */
    private static function consulta(ConsultaIndicadores $c, User $u): Builder
    {
        $visiveis = $u->visibleGrupoSolucaoIds();
        $grupos = $c->grupos;
        $chamadoVisivel = fn (Builder $q) => $q->when($visiveis !== null, fn (Builder $q) => $q->whereIn(
            'incidente_id',
            Incidente::query()->visiveisPara($u)->select('id')
        ));

        return DB::table('incidente_eventos')
            ->select(['id', 'incidente_id', 'user_id', 'autor_customer_id', 'autor_grupo_solucao_id', 'tipo', 'retroativo', 'created_at'])
            ->whereIn('tipo', array_values(self::TIPOS))
            ->where('created_at', '>=', PeriodoRelatorio::inicioUtc($c->inicio))
            ->where('created_at', '<', PeriodoRelatorio::fimExclusivoUtc($c->fim))
            ->where(fn (Builder $ramos) => $ramos
                ->where(fn (Builder $q) => self::semPortal($q)
                    ->whereNotNull('user_id')
                    ->whereNotNull('autor_grupo_solucao_id')
                    ->when($grupos !== [], fn (Builder $q) => $q->whereIn('autor_grupo_solucao_id', $grupos))
                    ->when($grupos === [] && $visiveis !== null, fn (Builder $q) => $q->whereIn('autor_grupo_solucao_id', $visiveis)))
                ->orWhere(fn (Builder $q) => $chamadoVisivel($q
                    ->where('tipo', IncidenteEvento::TIPO_ABERTO)
                    ->whereNotNull('autor_customer_id')
                    ->when($grupos !== [], fn (Builder $q) => $q->whereIn('grupo_solucao_id', $grupos))))
                ->when($grupos === [], fn (Builder $ramos) => $ramos
                    ->orWhere(fn (Builder $q) => $chamadoVisivel(self::semPortal($q)
                        ->where(fn (Builder $q) => $q
                            ->where(fn (Builder $q) => $q->whereNull('user_id')->whereNull('autor_customer_id'))
                            ->orWhere(fn (Builder $q) => $q->whereNotNull('user_id')->whereNull('autor_grupo_solucao_id')))))));
    }

    /** Exclui as aberturas do portal, que têm linha própria (mantém os ramos disjuntos). */
    private static function semPortal(Builder $q): Builder
    {
        return $q->where(fn (Builder $q) => $q
            ->whereNull('autor_customer_id')
            ->orWhere('tipo', '!=', IncidenteEvento::TIPO_ABERTO));
    }

    private static function chaveDo(object $e, bool $porIntegrante): int|string
    {
        return match (true) {
            $e->tipo === IncidenteEvento::TIPO_ABERTO && $e->autor_customer_id !== null => self::CHAVE_PORTAL,
            $e->user_id === null || $e->autor_grupo_solucao_id === null => self::CHAVE_SEM_AUTOR,
            $porIntegrante => (int) $e->user_id,
            default => (int) $e->autor_grupo_solucao_id,
        };
    }
}
