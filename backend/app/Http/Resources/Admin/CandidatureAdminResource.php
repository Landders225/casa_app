<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Candidature vue par l'ADMINISTRATEUR — `GET /api/admin/candidatures` (Lot 6a),
 * vue de supervision pour choisir qui affecter.
 *
 * ⚠️ 🔴 ADMINISTRATEUR STRICT. Expose `statut_interne`,
 * `statut_eligibilite_interne`, l'évaluateur affecté, les scores figés et la
 * décision — aucun de ces champs ne doit atteindre un candidat ni un évaluateur.
 * Resource DISTINCTE de `CandidatureCandidatResource` et des Resources
 * évaluateur, jamais réutilisée en croisé. Utilisée uniquement par
 * `CandidatureSupervisionController` (route `role:administrateur`).
 *
 * @mixin \App\Models\Candidature
 */
class CandidatureAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_dossier' => $this->numero_dossier,
            'statut_interne' => $this->statut_interne,
            'statut_eligibilite_interne' => $this->statut_eligibilite_interne,
            'date_soumission' => $this->date_soumission?->toIso8601String(),
            'dossier_verrouille' => (bool) $this->dossier_verrouille,

            'candidat' => $this->whenLoaded('candidat', fn () => $this->candidat ? [
                'prenom' => $this->candidat->prenom,
                'nom' => $this->candidat->nom,
                'ville_residence' => $this->candidat->ville_residence,
            ] : null),
            'filiere' => $this->whenLoaded('filiere', fn () => [
                'code' => $this->filiere->code,
                'nom' => $this->filiere->nom,
            ]),
            'evaluateur' => $this->whenLoaded('evaluateur', fn () => $this->evaluateur ? [
                'id' => $this->evaluateur->id,
                'prenom' => $this->evaluateur->prenom,
                'nom' => $this->evaluateur->nom,
            ] : null),

            'score_dossier' => $this->whenLoaded('evaluationDossier', fn () => $this->evaluationDossier?->score_total),
            'score_entretien' => $this->whenLoaded('entretien', fn () => $this->entretien?->score_total),
            'decision' => $this->whenLoaded('decisionCandidature', fn () => $this->decisionCandidature?->decision),
        ];
    }
}
