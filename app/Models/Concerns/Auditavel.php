<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\Auditoria;

/**
 * Grava criado/atualizado/excluido do model em `audit_logs`. Só registra
 * quando há um usuário autenticado no request — seeders, comandos e fluxos
 * sem login (ex.: redefinição de senha por token, que tem log explícito)
 * não geram linhas.
 *
 * Ganchos opcionais no model:
 * - `deveAuditar(): bool` — pula o registro (ex.: entradas de sistema do feed);
 * - `camposAuditaveis(): ?array` — whitelist de colunas (default: todas, menos as ignoradas);
 * - `auditoriaResumida(): bool` — grava só os nomes dos campos alterados, sem valores
 *   (usado onde o detalhe já fica em outro histórico, ex.: feed do chamado);
 * - `rotuloAuditoria(): string` — snapshot legível da entidade.
 */
trait Auditavel
{
    /** Colunas nunca registradas: técnicas ou sensíveis. */
    private static array $camposIgnoradosAuditoria = ['id', 'created_at', 'updated_at', 'deleted_at', 'password', 'remember_token'];

    public static function bootAuditavel(): void
    {
        static::created(fn (self $model) => $model->registrarAuditoria(AuditLog::ACAO_CRIADO));
        static::updated(fn (self $model) => $model->registrarAuditoria(AuditLog::ACAO_ATUALIZADO));
        static::deleted(fn (self $model) => $model->registrarAuditoria(AuditLog::ACAO_EXCLUIDO));
    }

    protected function registrarAuditoria(string $acao): void
    {
        if (Auditoria::atorAutenticado() === null) {
            return;
        }

        if (method_exists($this, 'deveAuditar') && ! $this->deveAuditar()) {
            return;
        }

        $resumida = method_exists($this, 'auditoriaResumida') && $this->auditoriaResumida();

        $alteracoes = match ($acao) {
            AuditLog::ACAO_CRIADO => $resumida ? null : collect($this->filtrarCamposAuditoria($this->getAttributes()))
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => ['de' => null, 'para' => $v])
                ->all(),
            AuditLog::ACAO_ATUALIZADO => $this->diffAuditoria($resumida),
            default => null,
        };

        // Update sem nenhuma coluna auditável alterada (ex.: só prazos de SLA recalculados).
        if ($acao === AuditLog::ACAO_ATUALIZADO && $alteracoes === null) {
            return;
        }

        Auditoria::registrar($acao, $this, $alteracoes);
    }

    private function diffAuditoria(bool $resumida): ?array
    {
        $mudancas = $this->getChanges();
        $senhaAlterada = array_key_exists('password', $mudancas);
        $mudancas = $this->filtrarCamposAuditoria($mudancas);

        if ($resumida) {
            return $mudancas === [] ? null : ['campos' => array_keys($mudancas)];
        }

        $diff = [];
        foreach ($mudancas as $campo => $novo) {
            $diff[$campo] = ['de' => $this->getRawOriginal($campo), 'para' => $novo];
        }

        if ($senhaAlterada) {
            $diff['senha'] = ['de' => null, 'para' => '(alterada)'];
        }

        return $diff === [] ? null : $diff;
    }

    private function filtrarCamposAuditoria(array $atributos): array
    {
        $atributos = array_diff_key($atributos, array_flip(self::$camposIgnoradosAuditoria));

        if (method_exists($this, 'camposAuditaveis') && ($whitelist = $this->camposAuditaveis()) !== null) {
            $atributos = array_intersect_key($atributos, array_flip($whitelist));
        }

        return $atributos;
    }
}
