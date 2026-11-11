<?php

namespace App\Relatorios;

use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use App\Support\PeriodoRelatorio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fonte sobre `incidente_eventos` — `concluidos` (resolvido + fechado),
 * `resolvidos`, `fechados` e `encaminhamentos`. A data é a do evento, não a
 * do chamado: reabrir e concluir de novo gera outro evento, e cada um cai no
 * período em que aconteceu. Filtros de grupo usam os snapshots do evento
 * (spec 6A.2); os demais, o estado atual do chamado.
 */
final class FonteEventos implements FonteRelatorio
{
    private const TIPOS = [
        'concluidos' => [IncidenteEvento::TIPO_RESOLVIDO, IncidenteEvento::TIPO_FECHADO],
        'resolvidos' => [IncidenteEvento::TIPO_RESOLVIDO],
        'fechados' => [IncidenteEvento::TIPO_FECHADO],
        'encaminhamentos' => [IncidenteEvento::TIPO_ENCAMINHADO_GRUPO, IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL],
    ];

    /** `encaminhamentos` conta ações; os demais, chamados distintos. */
    public function __construct(private readonly bool $contarEncaminhamentos = false) {}

    public function linhas(ConsultaRelatorio $c, User $u): Collection
    {
        $query = DB::table('incidente_eventos')
            ->whereIn('incidente_eventos.id', $this->idsFiltrados($c, $u))
            ->select(['incidente_eventos.id', 'incidente_eventos.incidente_id', 'incidente_eventos.user_id', 'incidente_eventos.created_at as data']);

        $this->selecionarChave($query, $c->agruparPor);

        return $query->get()->map(fn (object $linha) => new LinhaBase(
            chave: $this->chaveDa($linha, $c->agruparPor),
            incidenteId: (int) $linha->incidente_id,
            eventoId: (int) $linha->id,
            autorId: $linha->user_id === null ? null : (int) $linha->user_id,
            data: CarbonImmutable::parse($linha->data, 'UTC'),
        ));
    }

    public function rotulos(ConsultaRelatorio $c, array $chaves): array
    {
        return RotulosRelatorio::resolver($c->agruparPor, $chaves);
    }

    public function contaEventos(): bool
    {
        return $this->contarEncaminhamentos;
    }

    /**
     * Ids dos eventos que passam em tipo, período, filtros e visibilidade,
     * como subconsulta — pelo mesmo motivo de `FonteIncidentes`: os scopes
     * usam colunas sem prefixo que ficariam ambíguas ao lado dos joins.
     */
    private function idsFiltrados(ConsultaRelatorio $c, User $u): Builder
    {
        $filtros = $c->filtros;

        // Grupos são snapshots do evento; o resto é o estado atual do chamado.
        $grupoChamado = $filtros['grupo_solucao_id'] ?? null;
        $grupoAnalista = $filtros['grupo_analista_id'] ?? null;
        unset($filtros['grupo_solucao_id'], $filtros['grupo_analista_id']);

        return IncidenteEvento::query()
            ->visiveisParaRelatorio($u)
            ->whereIn('tipo', $this->tiposDe($c))
            ->when($c->temPeriodo(), fn (Builder $q) => $q
                ->where('created_at', '>=', PeriodoRelatorio::inicioUtc($c->inicio))
                ->where('created_at', '<', PeriodoRelatorio::fimExclusivoUtc($c->fim)))
            ->when($grupoChamado, fn (Builder $q, int $v) => $q->where('grupo_solucao_id', $v))
            ->when($grupoAnalista, fn (Builder $q, int $v) => $q->where('autor_grupo_solucao_id', $v))
            ->when($filtros !== [], fn (Builder $q) => $q->whereIn(
                'incidente_id',
                Incidente::query()->filtrosRelatorio($filtros)->select('id')
            ))
            ->select('id');
    }

    /**
     * "Para grupo" e "para responsável" só fazem sentido para o tipo de
     * encaminhamento que tem aquele destino; misturar os dois criaria uma
     * linha vazia inflando total e percentuais.
     */
    private function tiposDe(ConsultaRelatorio $c): array
    {
        return match ($c->agruparPor) {
            'para_grupo' => [IncidenteEvento::TIPO_ENCAMINHADO_GRUPO],
            'para_responsavel' => [IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL],
            default => self::TIPOS[$c->contar],
        };
    }

    private function selecionarChave(QueryBuilder $query, string $agruparPor): void
    {
        $precisaChamado = in_array($agruparPor, ['categoria', 'subcategoria', 'item', 'cliente', 'tipo', 'status_sla'], true);

        if ($precisaChamado) {
            $query->join('incidentes', 'incidentes.id', '=', 'incidente_eventos.incidente_id');
        }

        match ($agruparPor) {
            'analista', 'quem_encaminhou' => $query->addSelect('incidente_eventos.user_id as chave'),
            'grupo_analista' => $query->addSelect('incidente_eventos.autor_grupo_solucao_id as chave'),
            'grupo_chamado' => $query->addSelect('incidente_eventos.grupo_solucao_id as chave'),
            'para_grupo', 'para_responsavel' => $query->addSelect('incidente_eventos.alvo_id as chave'),
            'categoria' => $query
                ->leftJoin('itens', 'itens.id', '=', 'incidentes.item_id')
                ->leftJoin('subcategorias', 'subcategorias.id', '=', 'itens.subcategoria_id')
                ->addSelect('subcategorias.categoria_id as chave'),
            'subcategoria' => $query
                ->leftJoin('itens', 'itens.id', '=', 'incidentes.item_id')
                ->addSelect('itens.subcategoria_id as chave'),
            'item' => $query->addSelect('incidentes.item_id as chave'),
            'cliente' => $query
                ->leftJoin('customers', 'customers.id', '=', 'incidentes.customer_id')
                ->addSelect('customers.client_id as chave'),
            'tipo' => $query->addSelect('incidentes.tipo as chave'),
            // O status é calculado em PHP por `statusSlaResolucao()` (regra única da Fase 1).
            'status_sla' => $query->addSelect(['incidentes.prazo_resolucao', 'incidentes.concluido_em']),
        };
    }

    private function chaveDa(object $linha, string $agruparPor): int|string|null
    {
        if ($agruparPor === 'status_sla') {
            return (new Incidente)->forceFill([
                'prazo_resolucao' => $linha->prazo_resolucao,
                'concluido_em' => $linha->concluido_em,
            ])->statusSlaResolucao();
        }

        if ($linha->chave === null) {
            return null;
        }

        return is_numeric($linha->chave) ? (int) $linha->chave : (string) $linha->chave;
    }
}
