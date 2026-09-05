<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConviteUsuarioMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nome,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Você foi convidado para o '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.convite',
            with: ['nome' => $this->nome, 'url' => $this->url],
        );
    }
}
