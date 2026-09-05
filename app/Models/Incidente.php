<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\IncidenteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// prazo_resposta/prazo_resolucao/respondido_em/concluido_em ficam de fora de
// propósito — são só o controller quem escreve (cálculo automático), nunca
// mass-assignment a partir de input do cliente (ver BACKEND_SPECS.md §3.1).
#[Fillable(['customer_id', 'item_id', 'grupo_solucao_id', 'responsavel_id', 'titulo', 'prioridade', 'origem', 'status'])]
class Incidente extends Model
{
    /** @use HasFactory<IncidenteFactory> */
    use HasFactory;

    public const STATUSES = ['aberto', 'em_andamento', 'pendente', 'resolvido', 'fechado', 'cancelado'];

    public const ORIGENS = ['portal', 'email', 'telefone', 'chat', 'presencial', 'monitoramento'];

    /** Status que encerram o incidente pra fins de SLA — congelam `concluido_em`. */
    public const STATUS_CONCLUIDOS = ['resolvido', 'fechado', 'cancelado'];

    public const SLA_DENTRO_PRAZO = 'dentro_prazo';

    public const SLA_ESTOURADO = 'estourado';

    public const SLA_SEM_SLA = 'sem_sla';

    protected function casts(): array
    {
        return [
            'prazo_resposta' => 'datetime',
            'prazo_resolucao' => 'datetime',
            'respondido_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function grupoSolucao(): BelongsTo
    {
        return $this->belongsTo(GrupoSolucao::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id')->withTrashed();
    }

    // Quem abriu — diferente de responsavel/grupo_solucao, nunca muda
    // depois de setado (não é um evento repetível como resolvido/fechado,
    // não precisa de IncidenteEvento, ver BACKEND_SPECS.md §3.1). Nulo pra
    // incidentes criados antes desta coluna existir.
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id')->withTrashed();
    }

    // withTrashed() — comentários excluídos continuam visíveis no feed
    // (marcados via 'excluido_em', ver IncidenteDescricaoResource), nunca
    // somem da listagem.
    public function descricoes(): HasMany
    {
        return $this->hasMany(IncidenteDescricao::class)->withTrashed()->latest();
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(Anexo::class)->latest();
    }

    /** Status exibidos por padrão quando nenhum filtro `status` é informado. */
    public const STATUSES_PADRAO_LISTAGEM = ['aberto', 'em_andamento', 'pendente'];

    /**
     * Filtro composto por igualdade exata em colunas do Incidente — cada
     * chave é opcional (só filtra se presente em `$filtros`), combinadas
     * com AND. Validação dos valores (enum/`exists`) é responsabilidade de
     * quem chama (`IncidenteController`/`DashboardController`), não deste
     * scope — ele só monta a query.
     *
     * Sem `status` explícito, a listagem já nasce restrita a
     * `STATUSES_PADRAO_LISTAGEM` (aberto/em_andamento) — os demais status só
     * aparecem quando o chamador pede um `status` explicitamente, ou quando
     * `todos_status` (bool) é passado como truthy — usado pelo botão
     * "Mostrar todos os registros" da listagem (só `DashboardController`
     * valida/aceita esse parâmetro; `IncidenteController::index()`, do
     * portal do cliente, não expõe essa chave, então mantém a restrição
     * padrão de sempre). Um `status` explícito tem prioridade sobre
     * `todos_status` (mesma ordem de precedência do `->when()` abaixo).
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['numero'] ?? null, fn (Builder $q, int $v) => $q->where('id', $v))
            ->when(
                $filtros['status'] ?? null,
                fn (Builder $q, string $v) => $q->where('status', $v),
                fn (Builder $q) => empty($filtros['todos_status'])
                    ? $q->whereIn('status', self::STATUSES_PADRAO_LISTAGEM)
                    : $q,
            )
            ->when($filtros['prioridade'] ?? null, fn (Builder $q, string $v) => $q->where('prioridade', $v))
            ->when($filtros['origem'] ?? null, fn (Builder $q, string $v) => $q->where('origem', $v))
            ->when($filtros['customer_id'] ?? null, fn (Builder $q, int $v) => $q->where('customer_id', $v))
            ->when($filtros['item_id'] ?? null, fn (Builder $q, int $v) => $q->where('item_id', $v))
            ->when($filtros['grupo_solucao_id'] ?? null, fn (Builder $q, int $v) => $q->where('grupo_solucao_id', $v))
            ->when($filtros['responsavel_id'] ?? null, fn (Builder $q, int $v) => $q->where('responsavel_id', $v));
    }

    /**
     * Filtro pra relatórios — deliberadamente separado de scopeFiltros():
     * sem a restrição padrão de status (`STATUSES_PADRAO_LISTAGEM` é uma
     * decisão de UX de listagem "de trabalho", não faz sentido pra
     * relatório histórico), e com dimensões que a listagem não tem
     * (`data_inicio`/`data_fim` sobre `concluido_em`, `categoria_id`/
     * `subcategoria_id` via join até `Item`, `client_id` via join até
     * `Customer`). Ver RelatorioController.
     */
    public function scopeFiltrosRelatorio(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filtros['data_inicio'] ?? null, fn (Builder $q, string $v) => $q->whereDate('concluido_em', '>=', $v))
            ->when($filtros['data_fim'] ?? null, fn (Builder $q, string $v) => $q->whereDate('concluido_em', '<=', $v))
            ->when($filtros['item_id'] ?? null, fn (Builder $q, int $v) => $q->where('item_id', $v))
            ->when(
                $filtros['subcategoria_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('item', fn (Builder $qi) => $qi->where('subcategoria_id', $v))
            )
            ->when(
                $filtros['categoria_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('item.subcategoria', fn (Builder $qs) => $qs->where('categoria_id', $v))
            )
            ->when($filtros['grupo_solucao_id'] ?? null, fn (Builder $q, int $v) => $q->where('grupo_solucao_id', $v))
            ->when($filtros['responsavel_id'] ?? null, fn (Builder $q, int $v) => $q->where('responsavel_id', $v))
            ->when($filtros['customer_id'] ?? null, fn (Builder $q, int $v) => $q->where('customer_id', $v))
            ->when(
                $filtros['client_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('customer', fn (Builder $qc) => $qc->where('client_id', $v))
            );
    }

    /** Colunas aceitas em `sort_by` — ver scopeOrdenarPor(). */
    public const SORTABLE_COLUMNS = [
        'numero', 'titulo', 'prioridade', 'status', 'data_abertura', 'cliente', 'grupo_solucao', 'responsavel',
    ];

    /**
     * Ordenação por coluna clicável na listagem (DashboardController). Sem
     * `$sortBy`, mantém o padrão histórico (`latest()`, por `created_at`).
     * `cliente`/`grupo_solucao`/`responsavel` não são colunas de
     * `incidentes` — precisam de `leftJoin` pra ordenar pelo nome
     * relacionado; nesse caso o `select('incidentes.*')` evita colisão de
     * colunas (ex.: `id`/`name`/`created_at` existem nas duas tabelas).
     * `classificacao` e `status_sla_*` ficam de fora de propósito: a
     * primeira é concatenada no frontend a partir de 3 tabelas, as
     * segundas são calculadas em PHP contra `now()` — ordená-las exigiria
     * replicar essa lógica em SQL bruto, fora de escopo desta entrega.
     */
    public function scopeOrdenarPor(Builder $query, ?string $sortBy, string $sortDir = 'asc'): Builder
    {
        if (! $sortBy) {
            return $query->latest();
        }

        $query->select('incidentes.*');

        return match ($sortBy) {
            'numero' => $query->orderBy('incidentes.id', $sortDir),
            'titulo' => $query->orderBy('incidentes.titulo', $sortDir),
            'prioridade' => $query->orderBy('incidentes.prioridade', $sortDir),
            'status' => $query->orderBy('incidentes.status', $sortDir),
            'data_abertura' => $query->orderBy('incidentes.created_at', $sortDir),
            'cliente' => $query
                ->leftJoin('customers', 'customers.id', '=', 'incidentes.customer_id')
                ->leftJoin('clients', 'clients.id', '=', 'customers.client_id')
                ->orderBy('clients.name', $sortDir),
            'grupo_solucao' => $query
                ->leftJoin('grupos_solucao', 'grupos_solucao.id', '=', 'incidentes.grupo_solucao_id')
                ->orderBy('grupos_solucao.nome', $sortDir),
            'responsavel' => $query
                ->leftJoin('users', 'users.id', '=', 'incidentes.responsavel_id')
                ->orderBy('users.name', $sortDir),
        };
    }

    public function statusSlaResposta(): string
    {
        return $this->calcularStatusSla($this->prazo_resposta, $this->respondido_em);
    }

    public function statusSlaResolucao(): string
    {
        return $this->calcularStatusSla($this->prazo_resolucao, $this->concluido_em);
    }

    /**
     * Minutos até o prazo (negativo se já estourado); `null` sem política
     * aplicável. Usa `respondido_em`/`concluido_em` como referência (em vez
     * de "agora") quando já setados — é isso que congela o resultado depois
     * que o incidente conclui, ao invés de continuar mudando com o tempo.
     */
    public function tempoRestanteRespostaMinutos(): ?int
    {
        return $this->calcularTempoRestanteMinutos($this->prazo_resposta, $this->respondido_em);
    }

    public function tempoRestanteResolucaoMinutos(): ?int
    {
        return $this->calcularTempoRestanteMinutos($this->prazo_resolucao, $this->concluido_em);
    }

    private function calcularStatusSla(?Carbon $prazo, ?Carbon $referenciaCongelada): string
    {
        if ($prazo === null) {
            return self::SLA_SEM_SLA;
        }

        $referencia = $referenciaCongelada ?? now();

        return $referencia->lessThanOrEqualTo($prazo) ? self::SLA_DENTRO_PRAZO : self::SLA_ESTOURADO;
    }

    private function calcularTempoRestanteMinutos(?Carbon $prazo, ?Carbon $referenciaCongelada): ?int
    {
        if ($prazo === null) {
            return null;
        }

        $referencia = $referenciaCongelada ?? now();

        return intdiv($prazo->getTimestamp() - $referencia->getTimestamp(), 60);
    }
}
