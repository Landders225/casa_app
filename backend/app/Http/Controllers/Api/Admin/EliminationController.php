<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EliminerCandidatureRequest;
use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * ÉLIMINATION MANUELLE d'une candidature (Lot 6b) — administrateur strict.
 *
 *   POST /api/admin/candidatures/{candidature}/elimination  { motif }
 *
 * L'admin force un dossier `non_eligible` (fraude, pièce non conforme…).
 * `motif` OBLIGATOIRE (422 sinon). Une par une (D-6b-4). Possible avant OU après
 * publication.
 *
 * Effet :
 *  - `statut_eligibilite_interne → 'non_eligible'` ; `statut_interne` INCHANGÉ
 *    (verdict ≠ position workflow — divergence assumée avec `candidatures.html`) ;
 *  - 1 ligne `critere_eliminatoire_declenche` (`origine = 'decision_administrative'`,
 *    D-6b-3) ;
 *  - si une `decision_candidature` existe → `{ decision: 'non_retenu',
 *    motif_interne: 'non éligible' }` (préserve `motif_communicable`). PAS de
 *    promotion automatique (≠ remplacement — fraude ≠ désistement, D-6b : Q7) ;
 *  - après publication, le candidat voit `non_retenu` générique — INDISCERNABLE
 *    d'un non-retenu ordinaire (règle reine, D-5b-1).
 *  - `journal_audit` : `ancienne_valeur` → `nouvelle_valeur` + `motif`.
 *
 * Irréversible (une erreur se rattrape par un nouvel acte tracé, jamais un undo).
 */
class EliminationController extends Controller
{
    public function store(EliminerCandidatureRequest $request, Candidature $candidature): JsonResponse
    {
        abort_if(
            $candidature->statut_interne === 'brouillon',
            422,
            'Une candidature non soumise ne peut pas être éliminée.',
        );

        $motif = $request->validated('motif');
        $eligibiliteAvant = $candidature->statut_eligibilite_interne;
        $decision = $candidature->decisionCandidature;
        $decisionAvant = $decision?->decision;

        DB::transaction(function () use ($request, $candidature, $motif, $decision, $eligibiliteAvant, $decisionAvant) {
            $candidature->forceFill(['statut_eligibilite_interne' => 'non_eligible'])->saveQuietly();

            CritereEliminatoireDeclenche::create([
                'candidature_id' => $candidature->id,
                'code_critere' => 'decision_administrative',
                'detail' => $motif,
                'origine' => 'decision_administrative',
                'declenche_le' => now(),
            ]);

            if ($decision !== null) {
                $decision->update([
                    'decision' => 'non_retenu',
                    'motif_interne' => $decision->motif_interne ?? 'non éligible',
                ]);
            }

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Élimination manuelle',
                'module' => 'Candidatures',
                'objet' => $candidature->numero_dossier,
                'ancienne_valeur' => 'éligibilité: '.$eligibiliteAvant
                    .($decisionAvant !== null ? ' · décision: '.$decisionAvant : ''),
                'nouvelle_valeur' => 'éligibilité: non_eligible'
                    .($decisionAvant !== null ? ' · décision: non_retenu' : ''),
                'motif' => $motif,
                'resultat' => 'Succès',
            ]);
        });

        return response()->json(['data' => [
            'numero_dossier' => $candidature->numero_dossier,
            'statut_eligibilite_interne' => 'non_eligible',
        ]]);
    }
}
