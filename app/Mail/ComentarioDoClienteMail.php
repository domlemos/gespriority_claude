<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ComentarioDoClienteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $agente,
        public readonly int $numero,
        public readonly string $titulo,
        public readonly string $cliente,
        public readonly ?string $empresa,
        public readonly string $comentario,
        public readonly bool $reabertura,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        $assunto = $this->reabertura ? 'Chamado reaberto pelo cliente' : 'Novo comentário do cliente';

        return new Envelope(subject: "[#{$this->numero}] {$assunto}: {$this->titulo}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.comentario-cliente');
    }
}
