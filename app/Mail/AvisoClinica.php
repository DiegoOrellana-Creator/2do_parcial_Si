<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de aviso al paciente. Se envía en el momento (no usa la cola), para no depender de un
 * proceso «queue:work» aparte; si falla, el aviso queda registrado como «Fallido».
 */
class AvisoClinica extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $lineas  párrafos del cuerpo
     * @param  array<string, string>  $datos  tabla «etiqueta => valor» (fecha, médico, importe…)
     */
    public function __construct(
        public string $asunto,
        public string $saludo,
        public array $lineas,
        public array $datos = [],
        public ?string $enlace = null,
        public ?string $textoEnlace = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto . ' · Clínica Santa Cruz');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.aviso', text: 'emails.aviso-texto');
    }
}
