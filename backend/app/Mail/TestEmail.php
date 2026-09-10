<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail de TEST (Lot 12a, ADR-31) — envoyé par `php artisan casa:test-email`
 * pour que l'administrateur valide la configuration d'envoi sur le serveur
 * (nous n'avons pas ses identifiants SMTP).
 *
 * Contenu volontairement minimal : nom de l'app, horodatage, et le NOM du
 * transport (`config('mail.default')` = `smtp` / `log` — jamais une valeur
 * secrète comme l'hôte, l'identifiant ou le mot de passe). AUCUN identifiant
 * SMTP ne transite par cette classe.
 */
class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $transport,
        public readonly string $envoyeLe,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[CASA] E-mail de test');
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.test',
            with: [
                'appName' => config('app.name'),
                'transport' => $this->transport,
                'envoyeLe' => $this->envoyeLe,
            ],
        );
    }
}
