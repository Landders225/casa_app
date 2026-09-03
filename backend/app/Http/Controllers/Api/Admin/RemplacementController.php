<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnregistrerRemplacementRequest;
use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\JournalAudit;
use App\Models\Remplacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * REMPLACEMENT d'un candidat retenu indisponible (Lot 6b) — administrateur strict.
 *
 *   POST /api/admin/remplacements  { candidature_id, motif }
 *
 * Acte POST-publication (un retenu PUBLIÉ devient indisponible). Préconditions :
 *  - `publication` existe pour la campagne (422 sinon — avant, relancez le classement) ;
 *  - `decision_candidature.decision = 'retenu'` (422 sinon) ;
 *  - `motif` OBLIGATOIRE (422 sinon).
 *
 * Effet : sortant → `indisponible`, premier `liste_attente` de la même filière
 * (par `rang`) → `retenu` (rangs inchangés, 🔴). Ligne `remplacement` +
 * `journal_audit`. Irréversible.
 *
 * `StatutPublicResolver` gère la transition SANS logique spéciale : il lit
 * `decision` en direct — au prochain `GET /api/candidature`, le promu voit
 * `retenu`, le sortant `indisponible`. Ni score, ni rang, ni le fait du
 * remplacement ne transparaît (test de non-fuite, D-3b-7).
 */
class RemplacementController extends Controller
{
    public function store(EnregistrerRemplacementRequest $request): JsonResponse
    {
        $candidature = Candidature::query()
            ->with(['campagne', 'decisionCandidature'])
            ->findOrFail($request->validated('candidature_id'));

        abort_unless(
            $candidature->campagne->publication()->exists(),
            422,
            "Le remplacement n'a de sens qu'après publication des résultats. Avant publication, relancez le calcul du classement.",
        );

        $decision = $candidature->decisionCandidature;
        abort_if(
            $decision === null || $decision->decision !== 'retenu',
            422,
            'Seul un candidat au statut « retenu » peut être déclaré indisponible.',
        );

        $membreId = $request->user()->membreEquipe?->id;
        abort_if($membreId === null, 422, 'Profil équipe incomplet : impossible de tracer l’auteur du remplacement.');

        // Premier de la liste d'attente de la MÊME filière, par rang croissant.
        $promuDecision = DecisionCandidature::query()
            ->where('decision', 'liste_attente')
            ->whereIn('candidature_id', Candidature::query()
                ->where('campagne_id', $candidature->campagne_id)
                ->where('filiere_id', $candidature->filiere_id)
                ->select('id'))
            ->orderBy('rang')
            ->first();

        $promu = $promuDecision
            ? Candidature::query()->find($promuDecision->candidature_id)
            : null;

        DB::transaction(function () use ($request, $candidature, $decision, $promuDecision, $promu, $membreId) {
            $decision->update(['decision' => 'indisponible']);
            $promuDecision?->update(['decision' => 'retenu']);

            Remplacement::create([
                'candidature_indisponible_id' => $candidature->id,
                'candidature_promue_id' => $promu?->id,
                'motif' => $request->validated('motif'),
                'effectue_par' => $membreId,
                'effectue_le' => now(),
            ]);

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'Remplacement de candidat indisponible',
                'module' => 'Classement',
                'objet' => $candidature->numero_dossier.' → '.($promu?->numero_dossier ?? 'aucun candidat en liste d’attente'),
                'ancienne_valeur' => 'Retenu',
                'nouvelle_valeur' => $promu
                    ? 'Indisponible · '.$promu->numero_dossier.' promu Retenu'
                    : 'Indisponible · aucun candidat en liste d’attente à promouvoir',
                'motif' => $request->validated('motif'),
                'resultat' => 'Succès',
            ]);
        });

        return response()->json(['data' => [
            'indisponible' => $candidature->numero_dossier,
            'promu' => $promu?->numero_dossier,
        ]]);
    }
}
