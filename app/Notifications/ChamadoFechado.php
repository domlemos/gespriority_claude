<?php

namespace App\Notifications;

use App\Mail\ChamadoFechadoMail;
use App\Models\Customer;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Chamado fechado pelo staff → e-mail para o cliente (solicitante) com a
 * mensagem de encerramento e o link do portal, onde ele pode reabrir dentro
 * do prazo. Na fila (worker `queue`) e só depois do commit: se o update
 * falhar, nada é enviado. Ver BACKEND_SPECS.md §3.4.7.
 */
class ChamadoFechado extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IncidenteDescricao $encerramento)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Customer $notifiable): ChamadoFechadoMail
    {
        $incidente = $this->encerramento->loadMissing('incidente')->incidente;

        return (new ChamadoFechadoMail(
            cliente: $notifiable->name,
            numero: $incidente->id,
            titulo: $incidente->titulo,
            mensagem: $this->encerramento->descricao,
            prazoReaberturaDias: Incidente::PRAZO_REABERTURA_PORTAL_DIAS,
            url: rtrim(config('app.frontend_url'), '/')."/portal/incidentes/{$incidente->id}",
        ))->to($notifiable->email);
    }
}
