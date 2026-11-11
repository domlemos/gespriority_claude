<?php

namespace App\Relatorios;

use App\Models\Incidente;
use App\Models\User;
use App\Support\PeriodoRelatorio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fonte sobre a tabela `incidentes` — `abertos` (data = `created_at`,
 * qualquer status, spec 6A.1) e `cancelados` (`status = cancelado`, data =
 * `concluido_em`). Um chamado = uma linha-base; os filtros e a visibilidade
 * são os do chamado no estado atual (spec 6A.3), via os mesmos scopes do
 * caminho legado.
 */
final class FonteIncidentes implements FonteRelatorio
{
    public function linhas(ConsultaRelatorio $c, User $u): Collection
    {
        $colunaData = $c->contar === 'cancelados' ? 'concluido_em' : 'created_at';

        $query = DB::table('incidentes')
            ->whereIn('incidentes.id', $this->idsFiltrados($c, $u))
            ->whereNotNull("incidentes.{$colunaData}")
            ->select(['incidentes.id', "incidentes.{$colunaData} as data"]);

        if ($c->temPeriodo()) {
            $query->where("incidentes.{$colunaData}", '>=', PeriodoRelatorio::inicioUtc($c->inicio))
                ->where("incidentes.{$colunaData}", '<', PeriodoRelatorio::fimExclusivoUtc($c->fim));
        }

        $this->selecionarChave($query, $c->agruparPor);

        return $query->get()->map(fn (object $linha) => new LinhaBase(
            chave: $this->chaveDa($linha, $c->agruparPor),
            incidenteId: (int) $linha->id,
            eventoId: null,
            autorId: null,
            data: CarbonImmutable::parse($linha->data, 'UTC'),
        ));
    }

    public function rotulos(ConsultaRelatorio $c, array $chaves): array
    {
        return RotulosRelatorio::resolver($c->agruparPor, $chaves);
    }

    public function contaEventos(): bool
    {
        return false;
    }

    /**
     * Ids dos chamados que passam nos filtros e na visibilidade, como
     * subconsulta. Os scopes usam colunas sem prefixo (`grupo_solucao_id`,
     * `status`...), que ficariam ambíguas ao lado dos joins da chave — por
     * isso filtram numa consulta própria, só sobre `incidentes`.
     */
    private function idsFiltrados(ConsultaRelatorio $c, User $u): Builder
    {
        $filtros = $c->filtros;

        // Em "aberto por", o grupo do analista é o de quem ABRIU, não o do
        // responsável (regra da Fase 1, spec 6A.3).
        $grupoCriador = null;
        if ($c->agruparPor === 'aberto_por') {
            $grupoCriador = $filtros['grupo_analista_id'] ?? null;
            unset($filtros['grupo_analista_id']);
        }

        return Incidente::query()
            ->visiveisPara($u)
            ->filtrosRelatorio($filtros)
            ->when($c->contar === 'cancelados', fn (Builder $q) => $q->where('status', 'cancelado'))
            ->when($grupoCriador, fn (Builder $q, int $v) => $q->whereHas('criadoPor', fn (Builder $qc) => $qc->where('grupo_solucao_id', $v)))
            ->select('id');
    }

    /**
     * Seleciona a coluna da chave como `chave`. Joins são `left` para o
     * chamado sem item/cliente cair na linha vazia em vez de sumir.
     */
    private function selecionarChave(QueryBuilder $query, string $agruparPor): void
    {
        match ($agruparPor) {
            'categoria' => $query
                ->leftJoin('itens', 'itens.id', '=', 'incidentes.item_id')
                ->leftJoin('subcategorias', 'subcategorias.id', '=', 'itens.subcategoria_id')
                ->addSelect('subcategorias.categoria_id as chave'),
            'subcategoria' => $query
                ->leftJoin('itens', 'itens.id', '=', 'incidentes.item_id')
                ->addSelect('itens.subcategoria_id as chave'),
            'item' => $query->addSelect('incidentes.item_id as chave'),
            'grupo_chamado' => $query->addSelect('incidentes.grupo_solucao_id as chave'),
            'responsavel' => $query->addSelect('incidentes.responsavel_id as chave'),
            'cliente' => $query
                ->leftJoin('customers', 'customers.id', '=', 'incidentes.customer_id')
                ->addSelect('customers.client_id as chave'),
            'tipo' => $query->addSelect('incidentes.tipo as chave'),
            // O id do customer só serve para separar a linha "Portal (cliente)".
            'aberto_por' => $query->addSelect(['incidentes.criado_por_id as chave', 'incidentes.criado_por_customer_id as customer_criador']),
        };
    }

    /**
     * Chamado aberto pelo portal tem `criado_por_id` nulo como os legados de
     * antes da coluna — sem a linha própria, cairiam juntos em "(desconhecido)".
     */
    private function chaveDa(object $linha, string $agruparPor): int|string|null
    {
        if ($agruparPor === 'aberto_por' && $linha->chave === null && $linha->customer_criador !== null) {
            return RotulosRelatorio::CHAVE_PORTAL;
        }

        if ($linha->chave === null) {
            return null;
        }

        return is_numeric($linha->chave) ? (int) $linha->chave : (string) $linha->chave;
    }
}
