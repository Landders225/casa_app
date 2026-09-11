<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation à consulter les résultats (Lot 12b, ADR-33) — envoyée depuis
 * `PublicationController::publier` à TOUS les candidats ayant une décision
 * pour la campagne, quelle que soit cette décision (`retenu` / `liste_attente`
 * / `non_retenu` / `indisponible`).
 *
 * RÈGLE REINE : sujet et corps sont STRICTEMENT IDENTIQUES pour tous les
 * destinataires d'une même campagne — aucune variable de décision, de score,
 * de rang ou de motif. `$nomCampagne` est la SEULE donnée interpolée, et elle
 * est constante pour tous les destinataires d'un même appel (pas un
 * discriminant entre eux). Le lien est générique (espace candidat), jamais
 * `/resultat/<decision>`.
 *
 * Lot 12c (ADR-33, extension) — canal `database` en plus de `mail` : même
 * RÈGLE REINE réappliquée à `toDatabase()` — `$nomCampagne` est la SEULE
 * donnée interpolée, constante pour tous les destinataires d'un même appel
 * de publication. Preuve rejouée sur le contenu stocké (Lot 12c), pas
 * seulement sur le mail (Lot 12b).
 */
class ResultatsPublies extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $nomCampagne) {}

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
            ->subject('[CASA] Les résultats sont disponibles')
            ->view('emails.resultats-publies', [
                'appName' => config('app.name'),
                'nomCampagne' => $this->nomCampagne,
                'lien' => rtrim((string) config('app.url'), '/').'/candidat',
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'categorie' => 'resultats',
            'titre' => 'Résultats disponibles',
            'message' => "Les résultats de la campagne {$this->nomCampagne} sont disponibles.",
            'lien' => '/candidat',
        ];
    }
}
