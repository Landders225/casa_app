<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Affectation\GardeCampagnePubliee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReattribuerEvaluateurRequest;
use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RÉATTRIBUTION d'un ou plusieurs dossiers DÉJÀ AFFECTÉS, vers un autre
 * évaluateur ou vers le lot non affecté (Lot 20b) — administrateur strict.
 * Distincte de `AffectationController` (première affectation d'un dossier
 * `soumis`/non affecté) : ici, chaque dossier a DÉJÀ un `evaluateur_id`.
 *
 *   POST /api/admin/reattributions  { evaluateur_id: uuid|null, candidature_ids: [1..n] }
 *
 * Règles par état de l'évaluation (une par cas, ADR-47) :
 *  - ABSENTE (pas de brouillon, pas d'entretien) → libre ;
 *  - COMMENCÉE (note MO.04 et/ou commentaire évaluateur posés sur un dossier
 *    NON verrouillé, OU entretien `planifie`/`realise` non `valide`) →
 *    autorisée, mais le brouillon de l'ANCIEN évaluateur est RÉINITIALISÉ
 *    (jamais hérité sans le savoir par le nouveau) — tracé dans l'audit SANS
 *    copier son contenu (jamais la note/le commentaire/les sous-notes
 *    effacées dans `journal_audit`) ;
 *  - DOSSIER /65 VERROUILLÉ, ENTRETIEN PAS ENCORE VALIDÉ → autorisée : seul
 *    l'entretien change de main, le score /65 reste figé (ADR-04, jamais
 *    retouché) — mais le RETOUR AU LOT NON AFFECTÉ (`evaluateur_id = null`)
 *    est REFUSÉ dans ce cas : un dossier verrouillé ne doit jamais perdre
 *    tout évaluateur, seule une réattribution VERS UN AUTRE évaluateur est
 *    permise ;
 *  - TOTALEMENT VALIDÉE (dossier verrouillé ET entretien validé) → refusée
 *    (seule la correction exceptionnelle admin, hors scope, peut rouvrir) ;
 *  - une campagne concernée déjà PUBLIÉE → refusée, quel que soit l'état
 *    ci-dessus (`GardeCampagnePubliee`, partagée avec `AffectationController`).
 *
 * ATOMIQUE : si un seul dossier du lot est refusé (quel qu'en soit le motif),
 * RIEN n'est écrit. Verrouillage des lignes (`lockForUpdate`) pendant toute
 * l'opération — même patron que `ServiceAffectationAutomatique::executer`
 * (ADR-45) : une réattribution/affectation concurrente sur les mêmes
 * dossiers attend le verrou, puis les relit à jour.
 *
 * 1 ligne `journal_audit` PAR dossier (ancien évaluateur -> nouveau), même
 * forme que `AffectationController`. Une seconde ligne, SANS contenu, quand
 * un brouillon a été réinitialisé.
 */
class ReattributionController extends Controller
{
    /** Plafond de sécurité par opération (même esprit qu'ADR-45). */
    public const PLAFOND_DOSSIERS = 500;

    public function store(ReattribuerEvaluateurRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $nouvelEvaluateur = $this->resoudreCible($request->validated('evaluateur_id'));
            $ids = $request->validated('candidature_ids');

            // Verrou sur les lignes concernées : une affectation/réattribution
            // concurrente sur les mêmes dossiers attend, puis les relit à jour
            // (ADR-45). Rechargées intégralement sous verrou — jamais la
            // collection éventuellement obtenue avant la transaction.
            $candidatures = Candidature::query()
                ->with(['evaluateur', 'entretien.notes', 'reponseFormulaire'])
                ->whereIn('id', $ids)
                ->orderBy('numero_dossier')
                ->lockForUpdate()
                ->get();

            if ($candidatures->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'candidature_ids' => ["Une des candidatures n'existe plus : rechargez la liste."],
                ]);
            }

            $this->verifierAffectable($candidatures, $nouvelEvaluateur);

            $noms = [];
            foreach ($candidatures as $candidature) {
                $noms[$candidature->id] = $this->reattribuerUne($request->user(), $candidature, $nouvelEvaluateur);
            }

            return response()->json([
                'data' => [
                    'reattribuees' => $candidatures->count(),
                    'evaluateur' => $nouvelEvaluateur ? [
                        'id' => $nouvelEvaluateur->id,
                        'prenom' => $nouvelEvaluateur->prenom,
                        'nom' => $nouvelEvaluateur->nom,
                    ] : null,
                ],
            ]);
        });
    }

    private function resoudreCible(?string $evaluateurId): ?MembreEquipe
    {
        if ($evaluateurId === null) {
            return null;
        }

        $membre = MembreEquipe::query()->with('utilisateur')->findOrFail($evaluateurId);

        abort_unless(
            $membre->utilisateur?->role === 'evaluateur',
            422,
            "La cible d'une réattribution doit être un membre d'équipe de rôle « évaluateur ».",
        );
        abort_unless(
            $membre->utilisateur->actif,
            422,
            'Le compte de cet évaluateur est désactivé : il ne peut pas recevoir de réattribution.',
        );

        return $membre;
    }

    /**
     * Tout refus d'UN SEUL dossier bloque l'ensemble du lot (atomicité) —
     * motifs agrégés dans un seul message, même patron qu'`AffectationController`.
     *
     * @param  Collection<int, Candidature>  $candidatures
     */
    private function verifierAffectable(Collection $candidatures, ?MembreEquipe $nouvelEvaluateur): void
    {
        // Campagne publiée : prioritaire, vise la campagne entière (409,
        // même sémantique que l'affectation manuelle et l'automatique).
        abort_if(
            GardeCampagnePubliee::estPubliee($candidatures->pluck('campagne_id')->unique()->values()),
            409,
            "Les résultats d'une campagne concernée sont déjà publiés : aucune réattribution n'est possible sur ces dossiers.",
        );

        $nonAffectes = $candidatures->filter(fn (Candidature $c) => $c->evaluateur_id === null);
        $totalementValides = $candidatures->filter(fn (Candidature $c) => $this->estTotalementValide($c));
        $retourPoolInterdit = $nouvelEvaluateur === null
            ? $candidatures->diff($nonAffectes)->diff($totalementValides)->filter(fn (Candidature $c) => (bool) $c->dossier_verrouille)
            : collect();

        if ($nonAffectes->isEmpty() && $totalementValides->isEmpty() && $retourPoolInterdit->isEmpty()) {
            return;
        }

        $motifs = [];
        if ($nonAffectes->isNotEmpty()) {
            $motifs[] = 'Non affecté(s) — utilisez l\'affectation initiale, pas la réattribution : '
                .$nonAffectes->pluck('numero_dossier')->join(', ').'.';
        }
        if ($totalementValides->isNotEmpty()) {
            $motifs[] = 'Entièrement évalué(s) (dossier et entretien déjà validés) — seule une correction exceptionnelle administrateur peut rouvrir ces dossiers : '
                .$totalementValides->pluck('numero_dossier')->join(', ').'.';
        }
        if ($retourPoolInterdit->isNotEmpty()) {
            $motifs[] = "Dossier déjà verrouillé — impossible de remettre dans le lot non affecté, seule une réattribution vers un autre évaluateur est permise : "
                .$retourPoolInterdit->pluck('numero_dossier')->join(', ').'.';
        }

        throw ValidationException::withMessages([
            'candidature_ids' => [implode(' ', $motifs).' Aucune réattribution effectuée.'],
        ]);
    }

    private function estTotalementValide(Candidature $candidature): bool
    {
        return (bool) $candidature->dossier_verrouille
            && $candidature->entretien !== null
            && $candidature->entretien->statut === 'valide';
    }

    /**
     * Réattribue UN dossier (déjà validé affectable par `verifierAffectable`)
     * et consigne l'audit. Retourne le nom de l'ancien évaluateur (inutilisé
     * par l'appelant actuellement, gardé pour un futur résumé détaillé).
     */
    private function reattribuerUne(User $auteur, Candidature $candidature, ?MembreEquipe $nouvelEvaluateur): ?string
    {
        $ancien = $candidature->evaluateur;
        $ancienNom = $ancien ? $ancien->prenom.' '.$ancien->nom : null;
        $nouveauNom = $nouvelEvaluateur ? $nouvelEvaluateur->prenom.' '.$nouvelEvaluateur->nom : 'Lot non affecté';

        $etaitVerrouille = (bool) $candidature->dossier_verrouille;
        $entretien = $candidature->entretien;
        $entretienOuvert = $etaitVerrouille && ($entretien === null || $entretien->statut !== 'valide');

        $reponse = $candidature->reponseFormulaire;
        $brouillonDossier = ! $etaitVerrouille
            && (($reponse !== null && $reponse->mo04_note_etoiles !== null) || $candidature->commentaire_evaluateur !== null);
        $brouillonEntretien = $entretien !== null && in_array($entretien->statut, ['planifie', 'realise'], true);

        $brouillonReinitialise = false;

        if ($brouillonDossier) {
            // Écriture DIRECTE (pas via le modèle, `commentaire_evaluateur`
            // n'est pas fillable — même patron que `EvaluationController::update`) :
            // JAMAIS copié dans l'audit, juste effacé.
            if ($reponse !== null) {
                DB::table('reponse_formulaire')->where('candidature_id', $candidature->id)->update(['mo04_note_etoiles' => null]);
            }
            DB::table('candidature')->where('id', $candidature->id)->update(['commentaire_evaluateur' => null]);
            $brouillonReinitialise = true;
        }

        if ($brouillonEntretien) {
            // FK `note_sous_critere_entretien.entretien_id` sans cascade :
            // les sous-notes doivent être effacées AVANT la ligne `entretien`.
            $entretien->notes()->delete();
            $entretien->delete();
            $brouillonReinitialise = true;
        }

        $nouveauStatut = $nouvelEvaluateur === null ? 'soumis' : $candidature->statut_interne;

        $candidature->forceFill([
            'evaluateur_id' => $nouvelEvaluateur?->id,
            'statut_interne' => $nouveauStatut,
        ])->saveQuietly();

        $fragmentsMotif = [];
        if ($entretienOuvert) {
            $fragmentsMotif[] = 'Dossier déjà verrouillé : seul l\'entretien change de main, le score /65 reste inchangé.';
        }
        if ($brouillonReinitialise) {
            $fragmentsMotif[] = "Évaluation commencée : brouillon non validé de l'ancien évaluateur réinitialisé.";
        }

        JournalAudit::create([
            'auteur_id' => $auteur->id,
            'role' => $auteur->role,
            'action' => 'Réattribution évaluateur',
            'module' => 'Candidatures',
            'objet' => $candidature->numero_dossier,
            'ancienne_valeur' => $ancienNom,
            'nouvelle_valeur' => $nouveauNom,
            'motif' => $fragmentsMotif === [] ? null : implode(' ', $fragmentsMotif),
            'resultat' => 'Succès',
        ]);

        return $ancienNom;
    }
}
