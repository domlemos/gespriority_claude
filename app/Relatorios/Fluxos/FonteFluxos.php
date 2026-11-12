<?php

namespace App\Relatorios\Fluxos;

use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use App\Relatorios\Indicadores\ConsultaIndicadores;
use App\Relatorios\Indicadores\FonteIndicadores;
use App\Relatorios\LinhaBase;
use App\Support\PeriodoRelatorio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eventos do mapa de fluxos, em consultas enxutas sobre `incidente_eventos`
 * (sem hidratar models). Ver BACKEND_SPECS §3.4.9 "Mapa de fluxos" para as
 * regras de passagem, origem, visibilidade e filtro de grupos.
 */
final class FonteFluxos
{
    public const CLIENTE = 'cliente';

    public const SEM_GRUPO = 'sem_grupo';

    public const ORIGEM_DESCONHECIDA = 'origem_desconhecida';

    public const OUTROS = 'outros';

    /**
     * Passagens do período, em ordem de data (depois id do evento):
     * - pontas iguais (X = Y) são descartadas;
     * - com `grupos`, só as que têm uma ponta num grupo pedido;
     * - para quem não é admin, só as que têm uma ponta num grupo visível, e
     *   a ponta de grupo invisível vira `outros`.
     *
     * @return Collection<int, Passagem>
     */
    public static function passagens(ConsultaIndicadores $c, User $u): Collection
    {
        $pedidos = array_map('strval', $c->grupos);
        $visiveis = self::visiveis($u);
        $ehGrupo = fn (string $chave) => ctype_digit($chave);
        $visivel = fn (string $chave) => $ehGrupo($chave) && ($visiveis === null || in_array($chave, $visiveis, true));

        return self::encaminhamentos($c)
            ->concat(self::aberturas($c))
            ->filter(fn (array $p) => $p['origem'] !== $p['destino'])
            ->filter(fn (array $p) => $pedidos === []
                || in_array($p['origem'], $pedidos, true)
                || in_array($p['destino'], $pedidos, true))
            ->filter(fn (array $p) => $visivel($p['origem']) || $visivel($p['destino']))
            ->sortBy([fn (array $a, array $b) => [$a['e']->created_at, (int) $a['e']->id] <=> [$b['e']->created_at, (int) $b['e']->id]])
            ->map(function (array $p) use ($ehGrupo, $visivel) {
                [$origem, $destino] = array_map(
                    fn (string $chave) => $ehGrupo($chave) && ! $visivel($chave) ? self::OUTROS : $chave,
                    [$p['origem'], $p['destino']],
                );

                return new Passagem(
                    origem: $origem,
                    destino: $destino,
                    via: $p['via'],
                    base: new LinhaBase(
                        chave: "{$origem}>{$destino}",
                        incidenteId: (int) $p['e']->incidente_id,
                        eventoId: (int) $p['e']->id,
                        autorId: $p['e']->user_id === null ? null : (int) $p['e']->user_id,
                        data: CarbonImmutable::parse($p['e']->created_at, 'UTC'),
                    ),
                );
            })
            ->values();
    }

    /**
     * Eventos `aberto` do período. Chave = id do grupo do autor no momento
     * (string), ou `cliente` para o portal:
     * - agente: só com grupo de autor, e grupo visível para quem não é admin;
     * - portal: chamado visível hoje; com `grupos`, primeiro grupo atribuído ∈ grupos.
     * O filtro de grupos não recorta os agentes: o agregador escolhe os nós.
     *
     * @return Collection<int, LinhaBase>
     */
    public static function abertos(ConsultaIndicadores $c, User $u): Collection
    {
        $visiveis = $u->visibleGrupoSolucaoIds();

        return self::noPeriodo($c, IncidenteEvento::TIPO_ABERTO)
            ->select(['id', 'incidente_id', 'user_id', 'autor_customer_id', 'autor_grupo_solucao_id', 'created_at'])
            ->where(fn (Builder $ramos) => $ramos
                ->where(fn (Builder $q) => $q
                    ->whereNull('autor_customer_id')
                    ->whereNotNull('user_id')
                    ->whereNotNull('autor_grupo_solucao_id')
                    ->when($visiveis !== null, fn (Builder $q) => $q->whereIn('autor_grupo_solucao_id', $visiveis)))
                ->orWhere(fn (Builder $q) => $q
                    ->whereNotNull('autor_customer_id')
                    ->when($visiveis !== null, fn (Builder $q) => $q->whereIn(
                        'incidente_id',
                        Incidente::query()->visiveisPara($u)->select('id'),
                    ))
                    ->when($c->grupos !== [], fn (Builder $q) => FonteIndicadores::primeiroGrupoEm($q, $c->grupos))))
            ->get()
            ->map(fn (object $e) => self::linha(
                $e,
                $e->autor_customer_id !== null ? self::CLIENTE : (string) $e->autor_grupo_solucao_id,
            ));
    }

    /**
     * Eventos `resolvido` do período, no grupo em que o chamado estava
     * (`grupo_solucao_id` do evento), qualquer que seja o grupo de quem
     * resolveu. Chave = esse id (string); só grupos visíveis.
     *
     * @return Collection<int, LinhaBase>
     */
    public static function resolvidos(ConsultaIndicadores $c, User $u): Collection
    {
        $visiveis = $u->visibleGrupoSolucaoIds();

        return self::noPeriodo($c, IncidenteEvento::TIPO_RESOLVIDO)
            ->select(['id', 'incidente_id', 'user_id', 'grupo_solucao_id', 'created_at'])
            ->whereNotNull('grupo_solucao_id')
            ->when($visiveis !== null, fn (Builder $q) => $q->whereIn('grupo_solucao_id', $visiveis))
            ->get()
            ->map(fn (object $e) => self::linha($e, (string) $e->grupo_solucao_id));
    }

    /**
     * Instante (UTC) do evento `aberto`, `encaminhado_grupo` ou `resolvido`
     * retroativo mais recente do período, ou `null`. Ignora grupos pedidos e
     * visibilidade, como o aviso dos Indicadores.
     */
    public static function retroativos(ConsultaIndicadores $c): ?CarbonInterface
    {
        $ultimo = DB::table('incidente_eventos')
            ->whereIn('tipo', [IncidenteEvento::TIPO_ABERTO, IncidenteEvento::TIPO_ENCAMINHADO_GRUPO, IncidenteEvento::TIPO_RESOLVIDO])
            ->where('created_at', '>=', PeriodoRelatorio::inicioUtc($c->inicio))
            ->where('created_at', '<', PeriodoRelatorio::fimExclusivoUtc($c->fim))
            ->where('retroativo', true)
            ->max('created_at');

        return $ultimo === null ? null : CarbonImmutable::parse($ultimo, 'UTC');
    }

    /**
     * `encaminhado_grupo` do período com a origem lida pela regra:
     * `origem_grupo_id` → esse grupo; primeiro encaminhamento de chamado
     * aberto pelo portal sem grupo → `cliente`; `retroativo` → origem
     * desconhecida; senão → `sem_grupo`. O "primeiro do portal" é uma
     * subconsulta correlacionada (por `created_at`, depois `id`).
     *
     * @return Collection<int, array{origem: string, destino: string, via: string, e: object}>
     */
    private static function encaminhamentos(ConsultaIndicadores $c): Collection
    {
        $encaminhamento = IncidenteEvento::TIPO_ENCAMINHADO_GRUPO;

        $primeiroDoPortal = DB::table('incidente_eventos as abertura')
            ->selectRaw('count(*)')
            ->whereColumn('abertura.incidente_id', 'incidente_eventos.incidente_id')
            ->where('abertura.tipo', IncidenteEvento::TIPO_ABERTO)
            ->whereNotNull('abertura.autor_customer_id')
            ->whereNull('abertura.grupo_solucao_id')
            ->whereNotExists(fn (Builder $anterior) => $anterior
                ->from('incidente_eventos as anterior')
                ->whereColumn('anterior.incidente_id', 'incidente_eventos.incidente_id')
                ->where('anterior.tipo', $encaminhamento)
                ->where(fn (Builder $q) => $q
                    ->whereColumn('anterior.created_at', '<', 'incidente_eventos.created_at')
                    ->orWhere(fn (Builder $q) => $q
                        ->whereColumn('anterior.created_at', '=', 'incidente_eventos.created_at')
                        ->whereColumn('anterior.id', '<', 'incidente_eventos.id'))));

        return self::noPeriodo($c, $encaminhamento)
            ->select(['id', 'incidente_id', 'user_id', 'alvo_id', 'origem_grupo_id', 'retroativo', 'created_at'])
            ->selectSub($primeiroDoPortal, 'primeiro_do_portal')
            ->whereNotNull('alvo_id')
            ->get()
            ->map(fn (object $e) => [
                'origem' => match (true) {
                    $e->origem_grupo_id !== null => (string) $e->origem_grupo_id,
                    (int) $e->primeiro_do_portal > 0 => self::CLIENTE,
                    (bool) $e->retroativo => self::ORIGEM_DESCONHECIDA,
                    default => self::SEM_GRUPO,
                },
                'destino' => (string) $e->alvo_id,
                'via' => Passagem::VIA_ENCAMINHAMENTO,
                'e' => $e,
            ]);
    }

    /**
     * `aberto` com grupo de destino, aberto pelo portal (origem `cliente`) ou
     * por agente de outro grupo (origem = grupo do autor). Aberto pelo próprio
     * grupo, ou por autor sem grupo, não é passagem.
     *
     * @return Collection<int, array{origem: string, destino: string, via: string, e: object}>
     */
    private static function aberturas(ConsultaIndicadores $c): Collection
    {
        return self::noPeriodo($c, IncidenteEvento::TIPO_ABERTO)
            ->select(['id', 'incidente_id', 'user_id', 'autor_customer_id', 'autor_grupo_solucao_id', 'grupo_solucao_id', 'created_at'])
            ->whereNotNull('grupo_solucao_id')
            ->where(fn (Builder $q) => $q
                ->whereNotNull('autor_customer_id')
                ->orWhere(fn (Builder $q) => $q
                    ->whereNotNull('autor_grupo_solucao_id')
                    ->whereColumn('autor_grupo_solucao_id', '!=', 'grupo_solucao_id')))
            ->get()
            ->map(fn (object $e) => [
                'origem' => $e->autor_customer_id !== null ? self::CLIENTE : (string) $e->autor_grupo_solucao_id,
                'destino' => (string) $e->grupo_solucao_id,
                'via' => Passagem::VIA_ABERTURA,
                'e' => $e,
            ]);
    }

    private static function noPeriodo(ConsultaIndicadores $c, string $tipo): Builder
    {
        return DB::table('incidente_eventos')
            ->where('tipo', $tipo)
            ->where('created_at', '>=', PeriodoRelatorio::inicioUtc($c->inicio))
            ->where('created_at', '<', PeriodoRelatorio::fimExclusivoUtc($c->fim));
    }

    private static function linha(object $e, string $chave): LinhaBase
    {
        return new LinhaBase(
            chave: $chave,
            incidenteId: (int) $e->incidente_id,
            eventoId: (int) $e->id,
            autorId: $e->user_id === null ? null : (int) $e->user_id,
            data: CarbonImmutable::parse($e->created_at, 'UTC'),
        );
    }

    /** @return list<string>|null ids visíveis como string; `null` = admin */
    private static function visiveis(User $u): ?array
    {
        $ids = $u->visibleGrupoSolucaoIds();

        return $ids === null ? null : array_values(array_map('strval', array_filter($ids, fn ($id) => $id !== null)));
    }
}
