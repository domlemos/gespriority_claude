<?php

namespace App\Notifications;

use App\Mail\ConviteUsuarioMail;
use App\Support\PasswordUrl;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Notification;

/**
 * Convite de novo usuário/customer — reaproveita o mesmo mecanismo de token
 * do reset de senha (App\Http\Controllers\Api\{User,Customer}Controller::enviarConvite(),
 * Password::broker()->createToken()), só com um e-mail próprio (não o
 * ResetPassword padrão do Laravel) — ver BACKEND_SPECS.md seção 3.4.3.1.
 */
class ConviteUsuario extends Notification
{
    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(CanResetPassword $notifiable): ConviteUsuarioMail
    {
        return (new ConviteUsuarioMail(
            nome: $notifiable->name,
            url: PasswordUrl::build($notifiable, $this->token),
        ))->to($notifiable->getEmailForPasswordReset());
    }
}
