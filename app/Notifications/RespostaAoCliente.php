<?php

namespace App\Notifications;

use App\Mail\RespostaAoClienteMail;
use App\Models\Customer;
use App\Models\IncidenteDescricao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Resposta do staff ao cliente → e-mail para o cliente (solicitante) com o
 * texto da resposta e o link do portal. Na fila (worker `queue`) e só depois
 * do commit. Sai no idioma do cliente (HasLocalePreference). Ver
 * BACKEND_SPECS.md §3.4.7.1.
 */
class RespostaAoCliente extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IncidenteDescricao $resposta)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Customer $notifiable): RespostaAoClienteMail
    {
        $this->resposta->loadMissing(['incidente', 'user']);
        $incidente = $this->resposta->incidente;

        return (new RespostaAoClienteMail(
            cliente: $notifiable->name,
            numero: $incidente->id,
            titulo: $incidente->titulo,
            agente: $this->resposta->user->name,
            mensagem: $this->resposta->descricao,
            url: rtrim(config('app.frontend_url'), '/')."/portal/incidentes/{$incidente->id}",
        ))->to($notifiable->email);
    }
}
