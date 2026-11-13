<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Ponto único de gravação do log de ações (App\Models\AuditLog). Síncrono de
 * propósito (não fila): dentro de um DB::transaction() o log cai junto com a
 * ação num rollback, e nunca registra algo que não aconteceu.
 */
final class Auditoria
{
    /**
     * @param  string  $acao  slug de AuditLog::ACOES
     * @param  Model|null  $entidade  alvo da ação (null em login/logout/exportação)
     * @param  array|null  $alteracoes  `{campo: {de, para}}` ou contexto livre (ex.: filtros da exportação)
     * @param  User|Customer|null  $ator  default: usuário autenticado no request
     */
    public static function registrar(
        string $acao,
        ?Model $entidade = null,
        ?array $alteracoes = null,
        User|Customer|null $ator = null,
        ?string $entidadeType = null,
        ?string $entidadeLabel = null,
        ?string $atorNome = null,
        ?string $atorType = null,
        ?int $atorId = null,
    ): void {
        $ator ??= self::atorAutenticado();
        $request = request();

        AuditLog::query()->create([
            'ator_type' => $ator ? AuditLog::aliasDe($ator) : $atorType,
            'ator_id' => $ator?->getKey() ?? $atorId,
            'ator_nome' => $ator?->name ?? $atorNome,
            'acao' => $acao,
            'entidade_type' => $entidade ? AuditLog::aliasDe($entidade) : $entidadeType,
            'entidade_id' => $entidade?->getKey(),
            'entidade_label' => $entidadeLabel ?? ($entidade ? self::rotulo($entidade) : null),
            'alteracoes' => $alteracoes ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? mb_substr($request->userAgent(), 0, 1000) : null,
        ]);
    }

    /**
     * Diff de uma relação N:N (pivot `sync()` não dispara evento de model):
     * grava `atualizado` na entidade só se o conjunto mudou.
     *
     * @param  array<int, string>  $antes  nomes legíveis antes da troca
     * @param  array<int, string>  $depois  nomes legíveis depois da troca
     */
    public static function registrarRelacao(Model $entidade, string $campo, array $antes, array $depois): void
    {
        sort($antes);
        sort($depois);

        if ($antes === $depois) {
            return;
        }

        self::registrar(AuditLog::ACAO_ATUALIZADO, $entidade, [$campo => ['de' => $antes, 'para' => $depois]]);
    }

    /** Usuário do guard que autenticou o request (web ou customer), ou null fora de request autenticado. */
    public static function atorAutenticado(): User|Customer|null
    {
        // hasUser(), não user(): só quem o middleware `auth:` já autenticou
        // neste request. Em rota sem login (login, redefinição de senha), o
        // navegador pode carregar a sessão de outro perfil (staff e cliente
        // no mesmo navegador) e `user()` a resolveria como autor.
        if (! Auth::hasUser()) {
            return null;
        }

        // Defesa extra: dentro de uma rota, só conta se ela exige `auth:`.
        $rota = request()?->route();
        if ($rota !== null && ! collect($rota->gatherMiddleware())->contains(fn ($m) => is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:')))) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User || $user instanceof Customer ? $user : null;
    }

    public static function rotulo(Model $model): string
    {
        if (method_exists($model, 'rotuloAuditoria')) {
            return $model->rotuloAuditoria();
        }

        return (string) ($model->getAttribute('name') ?? $model->getAttribute('nome') ?? '#'.$model->getKey());
    }
}
