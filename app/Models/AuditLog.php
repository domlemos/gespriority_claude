<?php

namespace App\Models;

use App\Support\PeriodoRelatorio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Log de ações de usuários (staff e clientes do portal) — insert-only, nunca
 * atualizado. Gravado por App\Models\Concerns\Auditavel (CRUD dos models) e
 * por chamadas explícitas a App\Support\Auditoria (login, pivots, convites,
 * downloads, exportações). Nomes/rótulos são snapshots: o log continua
 * legível mesmo depois que o autor/entidade é renomeado ou excluído.
 * Retenção de 6 meses via `model:prune` agendado (routes/console.php).
 * Ver BACKEND_SPECS.md §3.4.11.
 */
#[Fillable([
    'ator_type', 'ator_id', 'ator_nome', 'acao', 'entidade_type', 'entidade_id',
    'entidade_label', 'alteracoes', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    use MassPrunable;

    protected $table = 'audit_logs';

    public const UPDATED_AT = null;

    public const RETENCAO_MESES = 6;

    public const ACAO_CRIADO = 'criado';

    public const ACAO_ATUALIZADO = 'atualizado';

    public const ACAO_EXCLUIDO = 'excluido';

    /** slug => rótulo exibido na tela de Administração. */
    public const ACOES = [
        self::ACAO_CRIADO => 'Criou',
        self::ACAO_ATUALIZADO => 'Alterou',
        self::ACAO_EXCLUIDO => 'Excluiu',
        'login' => 'Entrou no sistema',
        'login_falhou' => 'Falha de login',
        'token_renovado' => 'Renovou a sessão',
        'logout' => 'Saiu do sistema',
        'logout_todos' => 'Saiu de todos os dispositivos',
        'senha_redefinida' => 'Redefiniu a senha',
        'convite_enviado' => 'Enviou convite',
        'download' => 'Baixou arquivo',
        'exportado' => 'Exportou relatório',
    ];

    /**
     * Alias curto gravado em `ator_type`/`entidade_type` => [classe, rótulo].
     * Alias local (não Relation::morphMap global) de propósito: o morph map
     * global mudaria o que já é gravado em personal_access_tokens e
     * incidente_eventos.alvo_type (nome da classe).
     */
    public const ENTIDADES = [
        'user' => [User::class, 'Usuário'],
        'customer' => [Customer::class, 'Usuário de cliente'],
        'client' => [Client::class, 'Cliente'],
        'politica_sla' => [PoliticaSla::class, 'Política de SLA'],
        'categoria' => [Categoria::class, 'Categoria'],
        'subcategoria' => [Subcategoria::class, 'Subcategoria'],
        'item' => [Item::class, 'Item'],
        'grupo_solucao' => [GrupoSolucao::class, 'Grupo de Solução'],
        'relatorio_salvo' => [RelatorioSalvo::class, 'Relatório salvo'],
        'incidente' => [Incidente::class, 'Chamado'],
        'incidente_descricao' => [IncidenteDescricao::class, 'Comentário de chamado'],
        'anexo' => [Anexo::class, 'Anexo'],
        'relatorio' => [null, 'Relatório'],
    ];

    protected function casts(): array
    {
        return [
            'alteracoes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function aliasDe(Model $model): ?string
    {
        foreach (self::ENTIDADES as $alias => [$classe]) {
            if ($classe !== null && $model instanceof $classe) {
                return $alias;
            }
        }

        return null;
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(self::RETENCAO_MESES));
    }

    /** Filtros da consulta da tela de Administração — o período é sempre obrigatório (validado no controller). */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->where('created_at', '>=', PeriodoRelatorio::inicioUtc($filtros['data_inicio']))
            ->where('created_at', '<', PeriodoRelatorio::fimExclusivoUtc($filtros['data_fim']))
            ->when($filtros['user_id'] ?? null, fn (Builder $q, int $v) => $q->where('ator_type', 'user')->where('ator_id', $v))
            ->when($filtros['customer_id'] ?? null, fn (Builder $q, int $v) => $q->where('ator_type', 'customer')->where('ator_id', $v))
            ->when($filtros['ator_type'] ?? null, fn (Builder $q, string $v) => $q->where('ator_type', $v))
            ->when($filtros['acao'] ?? null, fn (Builder $q, array $v) => $q->whereIn('acao', $v))
            ->when($filtros['entidade_type'] ?? null, fn (Builder $q, string $v) => $q->where('entidade_type', $v))
            ->when($filtros['entidade_id'] ?? null, fn (Builder $q, int $v) => $q->where('entidade_id', $v))
            ->when($filtros['ip_address'] ?? null, fn (Builder $q, string $v) => $q->where('ip_address', $v))
            // LOWER + LIKE em vez de ILIKE: roda igual no Postgres (produção)
            // e no SQLite (suíte de testes).
            ->when($filtros['busca'] ?? null, function (Builder $q, string $v) {
                $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($v)).'%';

                $q->where(fn (Builder $qq) => $qq
                    ->whereRaw("LOWER(ator_nome) LIKE ? ESCAPE '\\'", [$termo])
                    ->orWhereRaw("LOWER(entidade_label) LIKE ? ESCAPE '\\'", [$termo]));
            });
    }
}
