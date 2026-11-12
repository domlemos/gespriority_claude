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
     * Indicadores com algum evento retroativo no período e o instante (UTC) do
     * mais recente deles. Ignora de propósito grupos pedidos e visibilidade:
     * o backfill põe o evento no grupo ATUAL da pessoa, então quem filtra o
     * grupo antigo dela fica subcontado justamente por não ver esses eventos.
     *
     * @return array{0: list<string>, 1: CarbonImmutable|null}
     */
    public static function retroativos(ConsultaIndicadores $c): array
    {
        $ultimoPorTipo = DB::table('incidente_eventos')
            ->whereIn('tipo', array_values(self::TIPOS))
            ->where('created_at', '>=', PeriodoRelatorio::inicioUtc($c->inicio))
            ->where('created_at', '<', PeriodoRelatorio::fimExclusivoUtc($c->fim))
            ->where('retroativo', true)
            ->groupBy('tipo')
            ->selectRaw('tipo, max(created_at) as ultimo')
            ->pluck('ultimo', 'tipo');

        $indicadores = array_keys(array_filter(self::TIPOS, fn (string $tipo) => $ultimoPorTipo->has($tipo)));
        $ultimo = $ultimoPorTipo->map(fn ($data) => CarbonImmutable::parse($data, 'UTC'))->max();

        return [$indicadores, $ultimo];
    }

    /**
     * Três ramos, cada um com a sua regra de grupo e de visibilidade:
     * - agente: grupo do autor ∈ grupos pedidos (ou ∈ visíveis, sem pedido);
     * - portal: só `aberto`; com grupos, pelo primeiro grupo atribuído ao chamado;
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
            ->select(['id', 'incidente_id', 'user_id', 'autor_customer_id', 'autor_grupo_solucao_id', 'tipo', 'created_at'])
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
                    ->when($grupos !== [], fn (Builder $q) => self::primeiroGrupoEm($q, $grupos))))
                ->when($grupos === [], fn (Builder $ramos) => $ramos
                    ->orWhere(fn (Builder $q) => $chamadoVisivel(self::semPortal($q)
                        ->where(fn (Builder $q) => $q
                            ->where(fn (Builder $q) => $q->whereNull('user_id')->whereNull('autor_customer_id'))
                            ->orWhere(fn (Builder $q) => $q->whereNotNull('user_id')->whereNull('autor_grupo_solucao_id')))))));
    }

    /**
     * Primeiro grupo atribuído ao chamado ∈ grupos. O portal abre sem grupo e
     * a triagem encaminha depois (`encaminhado_grupo`), então o `aberto` ao
     * vivo tem `grupo_solucao_id` nulo: vale o grupo do `aberto` se houver
     * (ex.: backfill de chamado nunca encaminhado), senão o destino do
     * `encaminhado_grupo` mais antigo (por `created_at`, depois `id`), em
     * qualquer data. Subconsulta correlacionada, não PHP por linha.
     *
     * @param  array<int, int>  $grupos
     */
    public static function primeiroGrupoEm(Builder $q, array $grupos): Builder
    {
        $encaminhamento = IncidenteEvento::TIPO_ENCAMINHADO_GRUPO;

        return $q->where(fn (Builder $q) => $q
            ->whereIn('grupo_solucao_id', $grupos)
            ->orWhere(fn (Builder $q) => $q
                ->whereNull('grupo_solucao_id')
                ->whereExists(fn (Builder $primeiro) => $primeiro
                    ->from('incidente_eventos as primeiro')
                    ->whereColumn('primeiro.incidente_id', 'incidente_eventos.incidente_id')
                    ->where('primeiro.tipo', $encaminhamento)
                    ->whereIn('primeiro.alvo_id', $grupos)
                    ->whereNotExists(fn (Builder $anterior) => $anterior
                        ->from('incidente_eventos as anterior')
                        ->whereColumn('anterior.incidente_id', 'primeiro.incidente_id')
                        ->where('anterior.tipo', $encaminhamento)
                        ->where(fn (Builder $q) => $q
                            ->whereColumn('anterior.created_at', '<', 'primeiro.created_at')
                            ->orWhere(fn (Builder $q) => $q
                                ->whereColumn('anterior.created_at', '=', 'primeiro.created_at')
                                ->whereColumn('anterior.id', '<', 'primeiro.id')))))));
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
