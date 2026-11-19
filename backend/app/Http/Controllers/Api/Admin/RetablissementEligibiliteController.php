<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Affectation\GardeCampagnePubliee;
use App\Domain\Eligibilite\StatutsRestaurables;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RetablirEligibiliteRequest;
use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RÉTABLISSEMENT D'ÉLIGIBILITÉ (Lot 4, ADR-49) — administrateur strict.
 *
 *   POST /api/admin/candidatures/eligibilite/retablir  { candidature_ids: [1..n], motif }
 *
 * L'administration choisit elle-même, dossier par dossier, des candidatures
 * `non_eligible` (rejet automatique OU élimination manuelle) à rendre à
 * nouveau `eligible`. `motif` OBLIGATOIRE. Interdit après publication
 * (`GardeCampagnePubliee`, 409, même garde que l'affectation/réattribution).
 *
 * Effet par dossier :
 *  - `statut_eligibilite_interne -> 'eligible'`, `eligibilite_retablie = true`
 *    (+ `_le`/`_par`) — PRIORITAIRE sur tout recalcul automatique ultérieur
 *    (`VerificationController`, `CorrectionController` : les deux lisent ce
 *    flag avant d'écraser le verdict). Seule `EliminationController` peut le
 *    renverser (remet `eligibilite_retablie = false`) ;
 *  - si `statut_interne === 'non_eligible'` (rejet à la soumission, jamais
 *    entré dans le pipeline) -> `statut_interne -> 'soumis'` : SEUL cas où le
 *    statut workflow bouge (symétrique de `SoumissionController`). Pour tout
 *    autre statut (`soumis`/`en_instruction`/`evalue`, élimination manuelle
 *    en cours de pipeline), `statut_interne` reste INCHANGÉ ;
 *  - si `statut_interne === 'evalue'` (dossier déjà entièrement noté avant
 *    son élimination), `campagne.classement_perime = true` : un classement
 *    calculé avant ce rétablissement ignorait ce dossier, donc n'est plus à
 *    jour (même mécanisme que l'édition de quota, ADR-34).
 *
 * ATOMIQUE, même patron que `ReattributionController` : un seul dossier
 * refusé bloque tout le lot, verrou (`lockForUpdate`) pendant l'opération,
 * relecture intégrale sous verrou (jamais une collection pré-transaction).
 *
 * 1 ligne `journal_audit` PAR dossier, incluant la transition `statut_interne`
 * quand elle a lieu (lisible, sans donnée personnelle de candidat).
 */
class RetablissementEligibiliteController extends Controller
{
    /** Plafond de sécurité par opération (même esprit qu'ADR-45/ADR-47). */
    public const PLAFOND_DOSSIERS = 500;

    public function store(RetablirEligibiliteRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $ids = $request->validated('candidature_ids');

            $candidatures = Candidature::query()
                ->whereIn('id', $ids)
                ->orderBy('numero_dossier')
                ->lockForUpdate()
                ->get();

            if ($candidatures->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'candidature_ids' => ["Une des candidatures n'existe plus : rechargez la liste."],
                ]);
            }

            $this->verifierRestaurable($candidatures);

            $motif = $request->validated('motif');
            foreach ($candidatures as $candidature) {
                $this->retablirUne($request->user(), $candidature, $motif);
            }

            return response()->json(['data' => [
                'retablies' => $candidatures->count(),
            ]]);
        });
    }

    /**
     * Tout refus d'UN SEUL dossier bloque l'ensemble du lot (atomicité) —
     * motifs agrégés dans un seul message, même patron qu'`AffectationController`
     * et `ReattributionController`.
     *
     * @param  Collection<int, Candidature>  $candidatures
     */
    private function verifierRestaurable(Collection $candidatures): void
    {
        abort_if(
            GardeCampagnePubliee::estPubliee($candidatures->pluck('campagne_id')->unique()->values()),
            409,
            "Les résultats d'une campagne concernée sont déjà publiés : aucun rétablissement d'éligibilité n'est possible sur ces dossiers.",
        );

        $dejaEligibles = $candidatures->filter(fn (Candidature $c) => $c->statut_eligibilite_interne !== 'non_eligible');
        $statutNonRestaurable = $candidatures->filter(fn (Candidature $c) => ! StatutsRestaurables::autorise($c->statut_interne));

        if ($dejaEligibles->isEmpty() && $statutNonRestaurable->isEmpty()) {
            return;
        }

        $motifs = [];
        if ($dejaEligibles->isNotEmpty()) {
            $motifs[] = 'Déjà éligible(s) — rien à rétablir : '
                .$dejaEligibles->pluck('numero_dossier')->join(', ').'.';
        }
        if ($statutNonRestaurable->isNotEmpty()) {
            $motifs[] = 'Statut de dossier non éligible à un rétablissement : '
                .$statutNonRestaurable->pluck('numero_dossier')->join(', ').'.';
        }

        throw ValidationException::withMessages([
            'candidature_ids' => [implode(' ', $motifs).' Aucun rétablissement effectué.'],
        ]);
    }

    private function retablirUne(User $auteur, Candidature $candidature, string $motif): void
    {
        $statutInterneAvant = $candidature->statut_interne;
        $statutInterneApres = $statutInterneAvant === 'non_eligible' ? 'soumis' : $statutInterneAvant;

        $candidature->forceFill([
            'statut_eligibilite_interne' => 'eligible',
            'statut_interne' => $statutInterneApres,
            'eligibilite_retablie' => true,
            'eligibilite_retablie_le' => now(),
            'eligibilite_retablie_par' => $auteur->membreEquipe?->id,
        ])->saveQuietly();

        if ($statutInterneAvant === 'evalue') {
            Campagne::query()->where('id', $candidature->campagne_id)->update(['classement_perime' => true]);
        }

        JournalAudit::create([
            'auteur_id' => $auteur->id,
            'role' => $auteur->role,
            'action' => 'Rétablissement d\'éligibilité',
            'module' => 'Candidatures',
            'objet' => $candidature->numero_dossier,
            'ancienne_valeur' => 'éligibilité: non_eligible · statut: '.$statutInterneAvant,
            'nouvelle_valeur' => 'éligibilité: eligible · statut: '.$statutInterneApres,
            'motif' => $motif,
            'resultat' => 'Succès',
        ]);
    }
}
