<?php

namespace App\Domain\Candidature;

use Illuminate\Validation\Rule;

/**
 * Source unique des valeurs autorisées pour `reponse_formulaire`, transcrite de
 * App_maquette/assets/js/scoring.js (constante CASA_GRILLE) et alignée sur les
 * CHECK du MLD.
 *
 * Un test de non-dérive (tests/Unit/ChampsFormulaireTest) vérifie que chaque
 * énumération ci-dessous == les `option_item.valeur` du barème seedé au Lot 1.
 * Si scoring.js OU le seeder change sans l'autre, le test casse.
 */
final class ChampsFormulaire
{
    /**
     * Champ `reponse_formulaire` -> code d'item du barème (pour le test de
     * non-dérive). Les champs sans item de barème (textes libres, niveaux 0-3)
     * n'y figurent pas.
     *
     * @var array<string, string>
     */
    public const ITEM_PAR_CHAMP = [
        'sc01_scolarise_actuellement' => 'SC.01',
        'sc02_derniere_classe' => 'SC.02',
        'sc03_document_justifiant_niveau' => 'SC.03',
        'sc05_beneficiaire_formation_actuelle' => 'SC.05',
        'sc06_deja_beneficie_formation' => 'SC.06',
        'sc08_mene_a_terme' => 'SC.08',
        'se01_vit_avec' => 'SE.01',
        'se02_orphelin' => 'SE.02',
        'se03_situation_emploi' => 'SE.03',
        'se04_source_revenu' => 'SE.04',
        'se05_personnes_a_charge' => 'SE.05',
        'se06_soutien_menage' => 'SE.06',
        'di01_disponible_lun_ven' => 'DI.01',
        'di02_contraintes' => 'DI.02',
        'di03_engagement_complet' => 'DI.03',
        'acces_plateau' => 'DI.04',
        'acces_deux_plateaux_vallons' => 'DI.05',
    ];

    /**
     * Valeurs autorisées par champ énuméré (scoring.js -> options[].value).
     *
     * @var array<string, list<string>>
     */
    public const ENUMS = [
        'sc01_scolarise_actuellement' => ['oui', 'non'],
        'sc02_derniere_classe' => ['avant_3e', 'cap', '3e', 'seconde', '1ere', 'terminale', 'bt_bep'],
        'sc03_document_justifiant_niveau' => ['oui', 'non'],
        'sc05_beneficiaire_formation_actuelle' => ['oui', 'non'],
        'sc06_deja_beneficie_formation' => ['oui', 'non'],
        'sc08_mene_a_terme' => ['oui', 'non'],
        'se01_vit_avec' => ['pere', 'mere', 'les_deux', 'aucun'],
        'se02_orphelin' => ['oui', 'non'],
        'se03_situation_emploi' => ['sans_emploi', 'stage', 'interim', 'temps_partiel', 'temps_plein'],
        'se04_source_revenu' => ['parent', 'conjoint', 'agr', 'aucune'],
        'se05_personnes_a_charge' => ['0', '1-2', '3+'],
        'se06_soutien_menage' => ['oui', 'non'],
        'di01_disponible_lun_ven' => ['oui', 'non'],
        'di02_contraintes' => ['aucune', 'gerable', 'bloquante'],
        'di03_engagement_complet' => ['oui', 'non'],
        'acces_plateau' => ['oui', 'non'],
        'acces_deux_plateaux_vallons' => ['oui', 'non'],
    ];

    /**
     * Champs texte libre -> longueur max. `mo04_lettre_motivation` = 500,
     * aligné sur MO04_MAX de candidature.html. `sc07` / `sc09` sont `text` en
     * base : borne de sécurité applicative.
     *
     * @var array<string, int>
     */
    public const TEXTE = [
        'sc07_filiere_suivie' => 2000,
        'sc09_motif_non_achevement' => 2000,
        'mo04_lettre_motivation' => 500,
    ];

    /**
     * Champs "niveau" (échelle 0-3 : Débutant/Élémentaire/Intermédiaire/Avancé).
     *
     * @var list<string>
     */
    public const NIVEAUX = [
        'langue_ecrit',
        'langue_parle',
        'langue_comprehension',
        'info_word',
        'info_excel',
        'info_internet',
    ];

    /**
     * Clés qu'un candidat ne peut JAMAIS écrire via /reponses :
     *  - `mo04_note_etoiles` : saisie évaluateur (contrainte applicative) ;
     *  - nationalité / diplôme / SC.04 : n'existent pas côté candidat (ADR-07) ;
     *  - champs internes/dérivés de `candidature` ;
     *  - `cqp_confirme` : se confirme via l'endpoint dédié.
     *
     * @var list<string>
     */
    public const INTERDITS = [
        'mo04_note_etoiles',
        'nationalite',
        'nationalite_confirmee',
        'diplome',
        'diplome_verifie',
        'sc04',
        'sc04_plus_haut_diplome',
        'statut_interne',
        'statut_eligibilite_interne',
        'score',
        'score_total',
        'evaluateur_id',
        'commentaire_evaluateur',
        'dossier_verrouille',
        'cqp_confirme',
    ];

    /**
     * Règles de validation pour un PATCH partiel de `/reponses`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function reglesReponses(): array
    {
        $regles = [];

        foreach (self::ENUMS as $champ => $valeurs) {
            $regles[$champ] = ['sometimes', 'nullable', 'string', Rule::in($valeurs)];
        }
        foreach (self::TEXTE as $champ => $max) {
            $regles[$champ] = ['sometimes', 'nullable', 'string', 'max:'.$max];
        }
        foreach (self::NIVEAUX as $champ) {
            $regles[$champ] = ['sometimes', 'nullable', 'integer', 'between:0,3'];
        }
        foreach (self::INTERDITS as $champ) {
            $regles[$champ] = ['prohibited'];
        }

        return $regles;
    }

    /**
     * Toutes les clés qu'un candidat PEUT écrire.
     *
     * @return list<string>
     */
    public static function champsAutorises(): array
    {
        return array_merge(
            array_keys(self::ENUMS),
            array_keys(self::TEXTE),
            self::NIVEAUX,
        );
    }

    /**
     * Messages 422 en français.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [];
        foreach (array_keys(self::ENUMS) as $champ) {
            $messages[$champ.'.in'] = "Le champ « {$champ} » a une valeur non autorisée.";
        }
        foreach (self::INTERDITS as $champ) {
            $messages[$champ.'.prohibited'] = "Le champ « {$champ} » ne peut pas être renseigné par le candidat.";
        }

        return $messages;
    }
}
