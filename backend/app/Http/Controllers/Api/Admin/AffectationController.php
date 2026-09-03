<?php

namespace App\Http\Controllers\Api\Admin;

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
 *  - cible = `membre_equipe` de rôle `evaluateur` (l'admin supervise, il n'est
 *    pas dans le pool d'affectation) ;
 *  - candidatures affectables : `statut_interne ∈ {soumis, en_instruction}` ;
 *  - ATOMIQUE : si une seule candidature n'est pas affectable → 422 avec la
 *    liste, rien n'est écrit ;
 *  - `soumis` → `en_instruction` + `evaluateur_id` (convention Lot 4a) ;
 *    `en_instruction` → ré-affectation (evaluateur changé, statut inchangé) ;
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

        $candidatures = Candidature::query()
            ->with('evaluateur')
            ->whereIn('id', $request->validated('candidature_ids'))
            ->get();

        $refusees = $candidatures->reject(
            fn (Candidature $c) => in_array($c->statut_interne, ['soumis', 'en_instruction'], true),
        );

        if ($refusees->isNotEmpty()) {
            throw ValidationException::withMessages([
                'candidature_ids' => [
                    'Non affectable(s) (doit être « soumise » ou « en instruction ») : '
                    .$refusees->pluck('numero_dossier')->join(', ').'. Aucune affectation effectuée.',
                ],
            ]);
        }

        DB::transaction(function () use ($request, $candidatures, $membre) {
            $nouvelEvaluateur = $membre->prenom.' '.$membre->nom;

            foreach ($candidatures as $candidature) {
                $ancien = $candidature->evaluateur
                    ? $candidature->evaluateur->prenom.' '.$candidature->evaluateur->nom
                    : null;

                $candidature->forceFill([
                    'evaluateur_id' => $membre->id,
                    'statut_interne' => $candidature->statut_interne === 'soumis'
                        ? 'en_instruction'
                        : $candidature->statut_interne,
                ])->saveQuietly();

                JournalAudit::create([
                    'auteur_id' => $request->user()->id,
                    'role' => $request->user()->role,
                    'action' => 'Affectation évaluateur',
                    'module' => 'Candidatures',
                    'objet' => $candidature->numero_dossier,
                    'ancienne_valeur' => $ancien,
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
