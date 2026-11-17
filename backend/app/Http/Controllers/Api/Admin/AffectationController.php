<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Affectation\GardeCampagnePubliee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AffecterEvaluateurRequest;
use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\MembreEquipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AFFECTATION d'un évaluateur à des candidatures (Lot 6a) — administrateur strict.
 * Résout la dépendance D-4a-1 (aucun endpoint d'affectation jusqu'ici).
 *
 *   POST /api/admin/affectations  { evaluateur_id, candidature_ids: [1..n] }
 *
 * Règles :
 *  - cible = `membre_equipe` de rôle `evaluateur` dont le compte est ACTIF
 *    (`utilisateur.actif`) — l'admin supervise, il n'est pas dans le pool
 *    d'affectation ; un compte désactivé n'en fait plus partie non plus ;
 *  - candidatures affectables : `statut_interne ∈ {soumis, en_instruction}`,
 *    `evaluateur_id` encore NUL (jamais affectée) ET `statut_eligibilite_interne
 *    ≠ non_eligible` (une élimination laisse `statut_interne` inchangé — sans
 *    ce second critère, un dossier éliminé resterait affectable) ;
 *  - un dossier DÉJÀ affecté (`evaluateur_id` non nul) est refusé ici, avec un
 *    message explicite : changer d'évaluateur passe par la réattribution
 *    (`ReattributionController`, Lot 20b, ADR-47), jamais par cet endpoint —
 *    cela garantit que toute bascule entre évaluateurs reçoit la sémantique
 *    dédiée (brouillon réinitialisé, garde « campagne publiée », etc.) ;
 *  - ATOMIQUE : si une seule candidature n'est pas affectable → 422 avec la
 *    liste, rien n'est écrit ;
 *  - `soumis` → `en_instruction` + `evaluateur_id` (convention Lot 4a) ;
 *  - 1 ligne `journal_audit` PAR candidature (audit exploitable, D-6a-1 —
 *    la maquette fait 1 ligne globale « N dossiers »).
 *
 * N'expose rien au candidat : il ne sait pas qui l'évalue.
 */
class AffectationController extends Controller
{
    public function store(AffecterEvaluateurRequest $request): JsonResponse
    {
        $membre = MembreEquipe::query()->with('utilisateur')->findOrFail($request->validated('evaluateur_id'));

        abort_unless(
            $membre->utilisateur?->role === 'evaluateur',
            422,
            "La cible d'une affectation doit être un membre d'équipe de rôle « évaluateur ».",
        );
        abort_unless(
            $membre->utilisateur->actif,
            422,
            "Le compte de cet évaluateur est désactivé : il ne peut pas recevoir d'affectation.",
        );

        $candidatures = Candidature::query()
            ->with('evaluateur')
            ->whereIn('id', $request->validated('candidature_ids'))
            ->get();

        // Correctif de revue : une campagne dont les résultats sont déjà
        // publiés (ADR-08) ne doit plus voir ses dossiers changer d'évaluateur
        // — ce garde-fou existait déjà pour l'affectation AUTOMATIQUE
        // (ADR-45) mais manquait ici. 409 (conflit d'état), pas 422 (les
        // refus 422 ci-dessous visent un dossier précis ; celui-ci vise la
        // campagne entière, même sémantique que `AffectationAutomatiqueRefusee::campagnePubliee`).
        abort_if(
            GardeCampagnePubliee::estPubliee($candidatures->pluck('campagne_id')->unique()->values()),
            409,
            "Les résultats d'une campagne concernée sont déjà publiés : aucune affectation n'est possible sur ces dossiers.",
        );

        $refuseesStatut = $candidatures->reject(
            fn (Candidature $c) => in_array($c->statut_interne, ['soumis', 'en_instruction'], true),
        );
        $restantes = $candidatures->diff($refuseesStatut);
        // Correctif de revue : un dossier déjà affecté (`evaluateur_id` non
        // nul) ne doit plus pouvoir changer de main via CET endpoint — seule
        // la réattribution (`ReattributionController`) porte la sémantique
        // dédiée (brouillon réinitialisé, garde « campagne publiée », audit
        // ancien/nouveau). Avant ce correctif, un dossier `en_instruction`
        // était « ré-affectable » ici, en silence, sans aucune des garanties
        // de la réattribution.
        $refuseesDejaAffectees = $restantes->filter(fn (Candidature $c) => $c->evaluateur_id !== null);
        $refuseesEligibilite = $restantes->diff($refuseesDejaAffectees)->filter(
            fn (Candidature $c) => $c->statut_eligibilite_interne === 'non_eligible',
        );

        if ($refuseesStatut->isNotEmpty() || $refuseesDejaAffectees->isNotEmpty() || $refuseesEligibilite->isNotEmpty()) {
            $motifs = [];
            if ($refuseesStatut->isNotEmpty()) {
                $motifs[] = 'Non affectable(s) (doit être « soumise » ou « en instruction ») : '
                    .$refuseesStatut->pluck('numero_dossier')->join(', ').'.';
            }
            if ($refuseesDejaAffectees->isNotEmpty()) {
                $motifs[] = 'Déjà affecté(s) à un évaluateur — utilisez « Réattribuer » pour changer d\'évaluateur : '
                    .$refuseesDejaAffectees->pluck('numero_dossier')->join(', ').'.';
            }
            if ($refuseesEligibilite->isNotEmpty()) {
                $motifs[] = 'Non affectable(s) (dossier non éligible) : '
                    .$refuseesEligibilite->pluck('numero_dossier')->join(', ').'.';
            }

            throw ValidationException::withMessages([
                'candidature_ids' => [implode(' ', $motifs).' Aucune affectation effectuée.'],
            ]);
        }

        DB::transaction(function () use ($request, $candidatures, $membre) {
            $nouvelEvaluateur = $membre->prenom.' '.$membre->nom;

            foreach ($candidatures as $candidature) {
                // Toute candidature qui arrive jusqu'ici est forcément NON
                // affectée (filtrée ci-dessus) : jamais d'« ancien » évaluateur.
                $candidature->forceFill([
                    'evaluateur_id' => $membre->id,
                    'statut_interne' => 'en_instruction',
                ])->saveQuietly();

                JournalAudit::create([
                    'auteur_id' => $request->user()->id,
                    'role' => $request->user()->role,
                    'action' => 'Affectation évaluateur',
                    'module' => 'Candidatures',
                    'objet' => $candidature->numero_dossier,
                    'ancienne_valeur' => null,
                    'nouvelle_valeur' => $nouvelEvaluateur,
                    'resultat' => 'Succès',
                ]);
            }
        });

        return response()->json([
            'data' => [
                'affectees' => $candidatures->count(),
                'evaluateur' => [
                    'id' => $membre->id,
                    'prenom' => $membre->prenom,
                    'nom' => $membre->nom,
                ],
            ],
        ]);
    }
}
