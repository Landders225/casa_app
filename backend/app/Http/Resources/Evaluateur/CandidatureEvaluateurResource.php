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
 * (visibilité workflow), la vérification, les critères éliminatoires déclenchés
 * (avec `detail` / `origine`), le `commentaire_evaluateur`, l'état de
 * verrouillage et un résumé de l'`evaluation` du dossier (Lot 4b — score /65
 * figé). Le détail par rubrique se lit sur GET .../evaluation.
 *
 * N'expose PAS (lots ultérieurs) : entretien /35, notes de sous-critères.
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
            'date_evaluation' => $this->date_evaluation?->toDateString(),
            'cqp_confirme' => (bool) $this->cqp_confirme,
            'dossier_verrouille' => (bool) $this->dossier_verrouille,
            'commentaire_evaluateur' => $this->commentaire_evaluateur,

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

            'evaluation' => $this->whenLoaded('evaluationDossier', fn () => $this->evaluationDossier ? [
                'verrouille' => (bool) $this->evaluationDossier->valide,
                'score_total' => $this->evaluationDossier->score_total,
                'valide_le' => $this->evaluationDossier->valide_le?->toIso8601String(),
                'grille_version' => $this->evaluationDossier->grille?->version,
            ] : null),
        ];
    }
}
