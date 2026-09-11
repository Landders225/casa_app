<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation de soumission de candidature (Lot 12b, ADR-33).
 *
 * RÈGLE REINE (héritée d'ADR-03/du Lot 3c, `SoumissionController`) : ce mail est
 * envoyé par le MÊME appel, placé APRÈS le calcul d'éligibilité mais HORS de
 * toute branche conditionnelle sur son résultat — il est donc structurellement
 * impossible qu'il diffère selon que le candidat soit éligible ou non. Seul le
 * `$numeroDossier` varie entre deux destinataires ; sujet et reste du corps sont
 * fixes. Aucune mention de statut, d'éligibilité ou de délai d'examen différencié.
 *
 * Lot 12c (ADR-33, extension) — canal `database` en plus de `mail` : le seul
 * champ qui varie dans `toDatabase()` est `numeroDossier`, exactement comme
 * dans le mail.
 */
class CandidatureSoumise extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $numeroDossier) {}

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
            ->subject('[CASA] Votre candidature a bien été soumise')
            ->view('emails.candidature-soumise', [
                'appName' => config('app.name'),
                'numeroDossier' => $this->numeroDossier,
                'lien' => rtrim((string) config('app.url'), '/').'/candidat',
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'categorie' => 'soumission',
            'titre' => 'Candidature soumise',
            'message' => "Dossier n° {$this->numeroDossier} — en cours d'examen.",
            'lien' => '/candidat',
        ];
    }
}
