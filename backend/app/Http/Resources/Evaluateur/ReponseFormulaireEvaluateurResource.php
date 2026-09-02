<?php

namespace App\Http\Resources\Evaluateur;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Réponses du formulaire vues par l'évaluateur : tous les champs déclaratifs du
 * candidat + `mo04_note_etoiles` (donnée saisie par l'évaluateur — null tant que
 * la notation n'est pas faite, Lot 4b).
 *
 * Resource DISTINCTE de ReponseFormulaireResource (candidat) — jamais réutilisée
 * en croisé.
 *
 * @mixin \App\Models\ReponseFormulaire
 */
class ReponseFormulaireEvaluateurResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sc01_scolarise_actuellement' => $this->sc01_scolarise_actuellement,
            'sc02_derniere_classe' => $this->sc02_derniere_classe,
            'sc03_document_justifiant_niveau' => $this->sc03_document_justifiant_niveau,
            'sc05_beneficiaire_formation_actuelle' => $this->sc05_beneficiaire_formation_actuelle,
            'sc06_deja_beneficie_formation' => $this->sc06_deja_beneficie_formation,
            'sc07_filiere_suivie' => $this->sc07_filiere_suivie,
            'sc08_mene_a_terme' => $this->sc08_mene_a_terme,
            'sc09_motif_non_achevement' => $this->sc09_motif_non_achevement,

            'se01_vit_avec' => $this->se01_vit_avec,
            'se02_orphelin' => $this->se02_orphelin,
            'se03_situation_emploi' => $this->se03_situation_emploi,
            'se04_source_revenu' => $this->se04_source_revenu,
            'se05_personnes_a_charge' => $this->se05_personnes_a_charge,
            'se06_soutien_menage' => $this->se06_soutien_menage,

            'langue_ecrit' => $this->langue_ecrit,
            'langue_parle' => $this->langue_parle,
            'langue_comprehension' => $this->langue_comprehension,
            'info_word' => $this->info_word,
            'info_excel' => $this->info_excel,
            'info_internet' => $this->info_internet,

            'acces_plateau' => $this->acces_plateau,
            'acces_deux_plateaux_vallons' => $this->acces_deux_plateaux_vallons,

            'mo04_lettre_motivation' => $this->mo04_lettre_motivation,
            'mo04_note_etoiles' => $this->mo04_note_etoiles,

            'di01_disponible_lun_ven' => $this->di01_disponible_lun_ven,
            'di02_contraintes' => $this->di02_contraintes,
            'di03_engagement_complet' => $this->di03_engagement_complet,

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
