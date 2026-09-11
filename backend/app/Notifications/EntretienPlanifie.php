<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convocation à l'entretien (Lot 12b, ADR-33) — envoyée UNE SEULE FOIS, à la
 * première planification (première saisie de date/heure/lieu par l'évaluateur,
 * `EntretienController::update`). Une replanification ultérieure ne redéclenche
 * pas ce mail (point ouvert 🟠, hors périmètre de ce lot).
 *
 * Résidu documenté (ADR-33, Étape 1 point d) : seuls les candidats éligibles et
 * affectés peuvent structurellement recevoir ce mail (le dossier doit être
 * verrouillé, ce qui exige une affectation, jamais possible pour un dossier
 * `non_eligible`). Accepté comme trace d'un acte physique réel (l'entretien
 * existerait de toute façon, avec ou sans cet e-mail) — de nature différente
 * des mails soumission/publication, purs artefacts numériques.
 *
 * Contenu : uniquement date/heure/lieu — jamais de score, de barème ou de nom
 * d'évaluateur.
 */
class EntretienPlanifie extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $date,
        private readonly string $heure,
        private readonly string $lieu,
    ) {}

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
            ->subject('[CASA] Convocation à un entretien')
            ->view('emails.entretien-planifie', [
                'appName' => config('app.name'),
                'date' => $this->date,
                'heure' => $this->heure,
                'lieu' => $this->lieu,
                'lien' => rtrim((string) config('app.url'), '/').'/candidat',
            ]);
    }
}
