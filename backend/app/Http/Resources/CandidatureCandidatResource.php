<?php

namespace App\Http\Resources;

use App\Services\StatutPublicResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Candidature vue par son propriétaire (rôle candidat) — LISTE BLANCHE STRICTE.
 *
 * N'expose JAMAIS (🔴, checklist dictionnaire-donnees.md + ADR-03) :
 * `statut_interne`, `statut_eligibilite_interne`, `dossier_verrouille*`,
 * `evaluateur_id`, `commentaire_evaluateur`, `date_evaluation`, ni rien issu de
 * `evaluation_dossier` / `verification_dossier` / `critere_eliminatoire_declenche`.
 * Le statut affichable est dérivé par StatutPublicResolver (seul autorisé à
 * lire `statut_interne` pour un candidat).
 *
 * @mixin \App\Models\Candidature
 */
class CandidatureCandidatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_dossier' => $this->numero_dossier,
            'statut_public' => app(StatutPublicResolver::class)->resoudre($this->resource),
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
        ];
    }
}
