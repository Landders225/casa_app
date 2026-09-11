<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PublicationResource;
use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\JournalAudit;
use App\Models\Publication;
use App\Notifications\ResultatsPublies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * PUBLICATION des résultats d'une campagne (Lot 5b) — administrateur strict.
 *
 *  POST /api/admin/campagnes/{campagne}/publier
 *
 * Acte NOTARIAL, pas un calcul : on fige la visibilité candidat sur l'état du
 * dernier `POST .../classement` (que l'admin a relu et approuvé). Aucun recalcul.
 *
 * Garde-fous :
 *  - au moins une `decision_candidature` doit exister (classement calculé) → 422 ;
 *  - IRRÉVERSIBLE : re-publier → 409, aucune dé-publication.
 *
 * Effet : `INSERT publication` + `journal_audit` « Publication des résultats ».
 * Aucune modification de `decision_candidature`. À partir de là,
 * `StatutPublicResolver` bascule sur sa branche « publication existe » pour
 * toutes les candidatures de la campagne.
 */
class PublicationController extends Controller
{
    public function publier(Request $request, Campagne $campagne): PublicationResource
    {
        abort_if(
            $campagne->publication()->exists(),
            409,
            'Les résultats de cette campagne sont déjà publiés. La publication est irréversible ; une correction passe par la correction exceptionnelle (administrateur).',
        );

        $nbDecisions = DecisionCandidature::query()
            ->whereIn(
                'candidature_id',
                Candidature::query()->where('campagne_id', $campagne->id)->select('id'),
            )
            ->count();

        abort_if(
            $nbDecisions === 0,
            422,
            'Aucune décision : calculez le classement (POST /classement) avant de publier.',
        );

        $membreEquipeId = $request->user()->membreEquipe?->id;
        abort_if(
            $membreEquipeId === null,
            422,
            'Profil équipe incomplet : impossible de tracer l’auteur de la publication.',
        );

        $publication = DB::transaction(function () use ($request, $campagne, $membreEquipeId, $nbDecisions) {
            $publication = Publication::create([
                'campagne_id' => $campagne->id,
                'publiee_le' => now(),
                'publiee_par' => $membreEquipeId,
            ]);

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Publication des résultats',
                'module' => 'Résultats',
                'objet' => $campagne->nom,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => sprintf('%d candidat(s) avec décision notifié(s)', $nbDecisions),
                'resultat' => 'Succès',
            ]);

            return $publication;
        });

        $publication->load('publiePar');

        // Lot 12b — invitation à consulter, MÊME contenu pour tous, quelle que
        // soit la décision (RÈGLE REINE, ADR-33). `Notification::send()` avec une
        // notification `ShouldQueue` dispatche UN job indépendant par
        // destinataire (vérifié : `NotificationSender::queueNotification`) — la
        // réponse admin reste synchrone et rapide, et l'échec d'un envoi
        // n'affecte jamais les autres (isolation par job, cf. ADR-31).
        $destinataires = Candidature::query()
            ->where('campagne_id', $campagne->id)
            ->whereIn('id', DecisionCandidature::query()->select('candidature_id'))
            ->with('candidat.utilisateur')
            ->get()
            ->pluck('candidat.utilisateur')
            ->filter()
            ->unique('id') // un même candidat ne reçoit qu'un seul mail, même avec plusieurs candidatures décidées.
            ->values();

        Notification::send($destinataires, new ResultatsPublies($campagne->nom));

        return new PublicationResource([
            'campagne' => ['id' => $campagne->id, 'nom' => $campagne->nom],
            'publiee_le' => $publication->publiee_le?->toIso8601String(),
            'publiee_par' => $publication->publiePar ? [
                'prenom' => $publication->publiePar->prenom,
                'nom' => $publication->publiePar->nom,
            ] : null,
            'candidats_avec_decision' => $nbDecisions,
        ]);
    }
}
