<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Modification d'un entretien déjà planifié (Lot 15b) — envoyée quand
 * l'évaluateur change au moins une des 3 valeurs date/heure/lieu d'un
 * entretien qui existait déjà (`EntretienController::update`). Classe
 * DÉDIÉE, distincte d'`EntretienPlanifie` — même patron que les 4 mails de
 * l'ADR-33 (1 classe par événement métier), plus simple à tester isolément.
 *
 * Ne se déclenche PAS à la première planification (déjà couverte par
 * `EntretienPlanifie`), ni sur une mise à jour de présence/observation/notes
 * qui ne touche pas date/heure/lieu.
 *
 * Contenu : uniquement date/heure/lieu — jamais de score, de barème ou de nom
 * d'évaluateur (même garde-fou que les 4 mails de l'ADR-33 et le résidu de
 * risque documenté pour l'entretien : structurellement, seul un candidat
 * éligible et affecté peut recevoir ce mail).
 */
class EntretienReplanifie extends Notification implements ShouldQueue
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
            ->subject('[CASA] Modification de votre entretien')
            ->view('emails.entretien-replanifie', [
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
            'titre' => 'Modification de votre entretien',
            'message' => "Nouvelle date : le {$this->date} à {$this->heure}, {$this->lieu}.",
            'lien' => '/candidat',
        ];
    }
}
