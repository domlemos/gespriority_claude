<?php

namespace App\Notifications;

use App\Mail\ComentarioDoClienteMail;
use App\Models\IncidenteDescricao;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Comentário do cliente no portal (inclusive o motivo de uma reabertura) →
 * e-mail para o agente de solução do chamado. Na fila (worker `queue`) e só
 * depois do commit: se a transação da reabertura falhar, nada é enviado.
 * Destinatários decididos em App\Support\NotificacaoComentarioCliente — ver
 * BACKEND_SPECS.md §3.4.10.
 */
class ComentarioDoCliente extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IncidenteDescricao $descricao)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): ComentarioDoClienteMail
    {
        $descricao = $this->descricao->loadMissing('incidente', 'customer.client');
        $incidente = $descricao->incidente;

        return (new ComentarioDoClienteMail(
            agente: $notifiable->name,
            numero: $incidente->id,
            titulo: $incidente->titulo,
            cliente: $descricao->customer?->name ?? 'Cliente',
            empresa: $descricao->customer?->client?->name,
            comentario: $descricao->descricao,
            reabertura: str_starts_with($descricao->descricao, 'Chamado reaberto pelo cliente.'),
            url: rtrim(config('app.frontend_url'), '/')."/incidents/{$incidente->id}",
        ))->to($notifiable->email);
    }
}
