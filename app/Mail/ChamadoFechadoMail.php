<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChamadoFechadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $cliente,
        public readonly int $numero,
        public readonly string $titulo,
        public readonly string $mensagem,
        public readonly int $prazoReaberturaDias,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[#{$this->numero}] Seu chamado foi fechado: {$this->titulo}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.chamado-fechado');
    }
}
