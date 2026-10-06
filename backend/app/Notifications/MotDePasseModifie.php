<?php

namespace App\Notifications;

use App\Notifications\Concerns\EnvoiMailResilient;
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
    use EnvoiMailResilient;
    use Queueable;

    public function __construct(private readonly string $modifieLe) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Critique (Lot 18bis) : détection de prise de compte, aucun canal
     * `database` de repli — jamais soumis à `MAIL_NOTIFICATIONS_INFORMATIVES`
     * (`via()` ci-dessus ne consulte pas ce réglage), et file `mail-critique`
     * (priorité sur les notifications informatives au sein du worker).
     */
    protected function fileMail(): string
    {
        return 'mail-critique';
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
