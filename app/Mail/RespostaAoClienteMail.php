<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RespostaAoClienteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $cliente,
        public readonly int $numero,
        public readonly string $titulo,
        public readonly string $agente,
        public readonly string $mensagem,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[#{$this->numero}] ".__('Nova resposta ao seu chamado').": {$this->titulo}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.resposta-cliente');
    }
}
