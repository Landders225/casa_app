<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Accusé de réception de l'inscription (Lot 12b, ADR-33) — envoyé juste après
 * la création du compte (`RegisterController`).
 *
 * Contenu volontairement muet sur l'éligibilité : à ce stade aucune candidature
 * n'existe encore (l'inscription crée le COMPTE seul, Lot 7) — il n'y a donc
 * rien à taire, mais la règle générale du lot (aucun mot sur un statut) reste
 * appliquée par cohérence avec les 3 autres mails.
 *
 * Lot 12c (ADR-33, extension) — canal `database` en plus de `mail` : même
 * contenu neutre, juste persisté pour l'historique in-app candidat.
 */
class InscriptionConfirmee extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[CASA] Votre inscription est confirmée')
            ->view('emails.inscription-confirmee', [
                'appName' => config('app.name'),
                'lien' => rtrim((string) config('app.url'), '/').'/candidat',
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'categorie' => 'inscription',
            'titre' => 'Inscription confirmée',
            'message' => 'Votre compte CASA a bien été créé.',
            'lien' => '/candidat',
        ];
    }
}
