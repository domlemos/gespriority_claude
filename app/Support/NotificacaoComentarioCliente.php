<?php

namespace App\Support;

use App\Models\IncidenteDescricao;
use App\Models\User;
use App\Notifications\ComentarioDoCliente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Quem recebe o e-mail de comentário do cliente (ver ComentarioDoCliente):
 * 1. o responsável pelo chamado, se houver e estiver ativo;
 * 2. senão, os usuários ativos do grupo de solução do chamado;
 * 3. senão (chamado ainda não triado), ninguém — o comentário fica no feed.
 */
final class NotificacaoComentarioCliente
{
    public static function disparar(IncidenteDescricao $descricao): void
    {
        $destinatarios = self::destinatarios($descricao);

        if ($destinatarios->isNotEmpty()) {
            Notification::send($destinatarios, new ComentarioDoCliente($descricao));
        }
    }

    /** @return Collection<int, User> */
    public static function destinatarios(IncidenteDescricao $descricao): Collection
    {
        $incidente = $descricao->incidente;

        if ($incidente->responsavel_id !== null) {
            // Sem withTrashed(): responsável desativado não recebe — cai pro grupo.
            $responsavel = User::query()->find($incidente->responsavel_id);
            if ($responsavel) {
                return collect([$responsavel]);
            }
        }

        if ($incidente->grupo_solucao_id === null) {
            return collect();
        }

        return User::query()->where('grupo_solucao_id', $incidente->grupo_solucao_id)->orderBy('id')->get();
    }
}
