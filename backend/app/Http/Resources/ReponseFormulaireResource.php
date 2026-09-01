<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Réponses brutes déclaratives du candidat (liste blanche stricte, ADR-02).
 *
 * ⚠️ `mo04_note_etoiles` (saisie évaluateur) n'apparaît JAMAIS. `candidature_id`
 * non plus (redondant, la ressource est déjà imbriquée dans la candidature).
 *
 * @mixin \App\Models\ReponseFormulaire
 */
class ReponseFormulaireResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Profil scolaire
            'sc01_scolarise_actuellement' => $this->sc01_scolarise_actuellement,
            'sc02_derniere_classe' => $this->sc02_derniere_classe,
            'sc03_document_justifiant_niveau' => $this->sc03_document_justifiant_niveau,
            'sc05_beneficiaire_formation_actuelle' => $this->sc05_beneficiaire_formation_actuelle,
            'sc06_deja_beneficie_formation' => $this->sc06_deja_beneficie_formation,
            'sc07_filiere_suivie' => $this->sc07_filiere_suivie,
            'sc08_mene_a_terme' => $this->sc08_mene_a_terme,
            'sc09_motif_non_achevement' => $this->sc09_motif_non_achevement,

            // Situation socio-économique
            'se01_vit_avec' => $this->se01_vit_avec,
            'se02_orphelin' => $this->se02_orphelin,
            'se03_situation_emploi' => $this->se03_situation_emploi,
            'se04_source_revenu' => $this->se04_source_revenu,
            'se05_personnes_a_charge' => $this->se05_personnes_a_charge,
            'se06_soutien_menage' => $this->se06_soutien_menage,

            // Langues & informatique (0-3)
            'langue_ecrit' => $this->langue_ecrit,
            'langue_parle' => $this->langue_parle,
            'langue_comprehension' => $this->langue_comprehension,
            'info_word' => $this->info_word,
            'info_excel' => $this->info_excel,
            'info_internet' => $this->info_internet,

            // Accès aux sites (DI.04 / DI.05)
            'acces_plateau' => $this->acces_plateau,
            'acces_deux_plateaux_vallons' => $this->acces_deux_plateaux_vallons,

            // Motivation — lettre uniquement (la note en étoiles est à l'évaluateur)
            'mo04_lettre_motivation' => $this->mo04_lettre_motivation,

            // Disponibilité
            'di01_disponible_lun_ven' => $this->di01_disponible_lun_ven,
            'di02_contraintes' => $this->di02_contraintes,
            'di03_engagement_complet' => $this->di03_engagement_complet,

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
