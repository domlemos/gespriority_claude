<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Contracts\Auth\CanResetPassword;

/**
 * URL de "definir senha" do SPA Vue — usada tanto pelo reset de senha
 * (App\Providers\AppServiceProvider::boot(), via ResetPassword::createUrlUsing())
 * quanto pelo convite de novo usuário (App\Notifications\ConviteUsuario). Extraída
 * pra cá pra não duplicar a lógica de "qual path/broker bate com qual guard" nos
 * dois lugares — ver BACKEND_SPECS.md seção 3.4.3.1.
 */
final class PasswordUrl
{
    public static function build(CanResetPassword $notifiable, string $token): string
    {
        $path = $notifiable instanceof Customer ? '/portal/reset-password' : '/reset-password';

        return sprintf(
            '%s%s?token=%s&email=%s',
            config('app.frontend_url'),
            $path,
            $token,
            urlencode($notifiable->getEmailForPasswordReset())
        );
    }
}
