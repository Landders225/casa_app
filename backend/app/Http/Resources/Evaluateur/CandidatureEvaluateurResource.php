<?php

namespace App\Http\Resources\Evaluateur;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche candidat vue par l'ÉVALUATEUR — liste blanche explicite, plus large que
 * la Resource candidat (l'évaluateur a le DROIT de voir la zone 🔴 du dossier
 * qu'il instruit).
 *
 * Expose volontairement : `statut_interne`, `statut_eligibilite_interne`
 * (visibilité workflow), la vérification, et les critères éliminatoires
 * déclenchés (avec `detail` / `origine`).
 *
 * N'expose PAS (lots ultérieurs) : score /65, `evaluation_dossier`,
 * `commentaire_evaluateur`, entretien, notes.
 *
 * ⚠️ Resource DISTINCTE de CandidatureCandidatResource — jamais réutilisée en
 * croisé. Aucun de ces champs ne doit remonter au candidat.
 *
 * @mixin \App\Models\Candidature
 */
class CandidatureEvaluateurResource extends JsonResource
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
            'cqp_confirme' => (bool) $this->cqp_confirme,

            'filiere' => $this->whenLoaded('filiere', fn () => [
                'id' => $this->filiere->id,
                'code' => $this->filiere->code,
                'nom' => $this->filiere->nom,
            ]),
            'campagne' => $this->whenLoaded('campagne', fn () => [
                'id' => $this->campagne->id,
                'nom' => $this->campagne->nom,
            ]),
            'evaluateur' => $this->whenLoaded('evaluateur', fn () => $this->evaluateur ? [
                'id' => $this->evaluateur->id,
                'prenom' => $this->evaluateur->prenom,
                'nom' => $this->evaluateur->nom,
                'poste' => $this->evaluateur->poste,
            ] : null),

            'candidat' => $this->whenLoaded('candidat', fn () => $this->candidat ? [
                'id' => $this->candidat->id,
                'prenom' => $this->candidat->prenom,
                'nom' => $this->candidat->nom,
                'sexe' => $this->candidat->sexe,
                'date_naissance' => $this->candidat->date_naissance?->toDateString(),
                'cni' => $this->candidat->cni,
                'telephone' => $this->candidat->telephone,
                'ville_residence' => $this->candidat->ville_residence,
                'residence_ci' => (bool) $this->candidat->residence_ci,
            ] : null),

            'reponses' => $this->whenLoaded(
                'reponseFormulaire',
                fn () => new ReponseFormulaireEvaluateurResource($this->reponseFormulaire),
            ),
            'experiences' => ExperienceEvaluateurResource::collection($this->whenLoaded('experiences')),
            'pieces_dossier' => PieceEvaluateurResource::collection($this->whenLoaded('piecesDossier')),

            'verification' => $this->whenLoaded(
                'verification',
                fn () => $this->verification
                    ? new VerificationDossierResource($this->verification)
                    : null,
            ),
            'criteres_eliminatoires' => CritereEliminatoireResource::collection(
                $this->whenLoaded('criteresEliminatoires'),
            ),
        ];
    }
}
