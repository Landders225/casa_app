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
 * JAMAIS cette classe-ci — elle déclenche `EntretienReplanifie` (Lot 15b), une
 * classe dédiée, si au moins une des 3 valeurs a réellement changé.
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
 *
 * Lot 12c (ADR-33, extension) — canal `database` en plus de `mail` : même
 * date/heure/lieu, jamais de score ni de nom d'évaluateur dans `toDatabase()`.
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
        return ['mail', 'database'];
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

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'categorie' => 'entretien',
            'titre' => 'Convocation à un entretien',
            'message' => "Le {$this->date} à {$this->heure}, {$this->lieu}.",
            'lien' => '/candidat',
        ];
    }
}
