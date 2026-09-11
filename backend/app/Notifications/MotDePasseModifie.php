<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation de changement de mot de passe (Lot 13, ADR-32) — envoyée après
 * CHAQUE changement réussi, connecté ou via le lien « mot de passe oublié ».
 *
 * But : détection de prise de compte — si le destinataire n'est pas à
 * l'origine du changement, il le sait immédiatement et peut réagir. Contenu
 * minimal : ni l'ancien ni le nouveau mot de passe, aucune autre donnée de
 * compte.
 */
class MotDePasseModifie extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $modifieLe) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[CASA] Votre mot de passe a été modifié')
            ->view('emails.mot-de-passe-modifie', [
                'appName' => config('app.name'),
                'modifieLe' => $this->modifieLe,
            ]);
    }
}
