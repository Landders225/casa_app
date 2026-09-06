<?php

namespace App\Http\Resources;

use App\Models\Candidature;
use App\Services\StatutPublicResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Candidature vue par son propriétaire (rôle candidat) — LISTE BLANCHE STRICTE.
 *
 * N'expose JAMAIS (🔴, checklist dictionnaire-donnees.md + ADR-03) :
 * `statut_interne`, `statut_eligibilite_interne`, `dossier_verrouille*`,
 * `evaluateur_id`, `commentaire_evaluateur`, `date_evaluation`, le SCORE, le RANG,
 * `motif_interne`, ni rien issu de `evaluation_dossier` / `verification_dossier` /
 * `entretien` / `critere_eliminatoire_declenche` / le classement des autres.
 *
 * `statut_public` + `decision` + `motif_communicable` sont dérivés par
 * StatutPublicResolver (seul autorisé à lire `statut_interne` /
 * `decision_candidature` pour un candidat) : avant publication `decision` et
 * `motif_communicable` valent `null` (Lot 5b, ADR-03, séquence (c)).
 *
 * @mixin Candidature
 */
class CandidatureCandidatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $statut = app(StatutPublicResolver::class)->resoudre($this->resource);

        return [
            'id' => $this->id,
            'numero_dossier' => $this->numero_dossier,
            'statut_public' => $statut->statutPublic,
            // 🟡 — null tant qu'aucune publication n'existe (ADR-03, Lot 5b).
            'decision' => $statut->decision,
            'motif_communicable' => $statut->motifCommunicable,
            'cqp_confirme' => (bool) $this->cqp_confirme,
            'date_soumission' => $this->date_soumission?->toIso8601String(),

            'filiere' => $this->whenLoaded('filiere', fn () => [
                'id' => $this->filiere->id,
                'code' => $this->filiere->code,
                'nom' => $this->filiere->nom,
            ]),
            'campagne' => $this->whenLoaded('campagne', fn () => [
                'id' => $this->campagne->id,
                'nom' => $this->campagne->nom,
            ]),

            'reponses' => $this->whenLoaded(
                'reponseFormulaire',
                fn () => new ReponseFormulaireResource($this->reponseFormulaire),
            ),
            'experiences' => ExperienceResource::collection($this->whenLoaded('experiences')),
            'classement' => ClassementPreferenceResource::collection($this->whenLoaded('classement')),
            'pieces_dossier' => PieceJustificativeResource::collection($this->whenLoaded('piecesDossier')),
        ];
    }
}
