<?php

namespace App\Domain\Candidature;

use App\Domain\Piece\ContraintesFichier;
use App\Models\Candidature;

/**
 * Vérifie qu'une candidature est COMPLÈTE avant soumission. Transcrit la
 * validation par étape de App_maquette/pages/candidate/candidature.html.
 *
 * ⚠️ Ne lit AUCUN critère d'éligibilité : la complétude (dicible au candidat)
 * et l'éligibilité (jamais dite) sont deux vérifications totalement séparées
 * (séquence a, ADR-03). `se01` / `se05` ne sont pas requis (la maquette ne les
 * valide pas — D-3c-3).
 *
 * @phpstan-type Erreurs array<string, list<string>>
 */
final class ValidateurCompletude
{
    /** Réponses toujours obligatoires (validées à chaque étape de la maquette). */
    private const REPONSES_REQUISES = [
        'sc01_scolarise_actuellement',
        'sc02_derniere_classe',
        'sc03_document_justifiant_niveau',
        'sc05_beneficiaire_formation_actuelle',
        'se02_orphelin',
        'se03_situation_emploi',
        'se06_soutien_menage',
        'langue_ecrit', 'langue_parle', 'langue_comprehension',
        'info_word', 'info_excel', 'info_internet',
        'acces_plateau', 'acces_deux_plateaux_vallons',
        'di01_disponible_lun_ven', 'di02_contraintes', 'di03_engagement_complet',
    ];

    private const LETTRE_MIN = 30;

    /**
     * @return array<string, list<string>> vide si complet
     */
    public function verifier(Candidature $candidature): array
    {
        $erreurs = [];
        $c = $candidature->candidat;
        $r = $candidature->reponseFormulaire;

        // --- Identité (candidat) ---
        foreach (['prenom', 'nom', 'date_naissance', 'cni'] as $champ) {
            if (blank($c?->{$champ})) {
                $erreurs["identite.{$champ}"][] = 'Information obligatoire manquante.';
            }
        }

        // --- Filière confirmée ---
        if (! $candidature->cqp_confirme) {
            $erreurs['cqp_confirme'][] = 'La filière doit être confirmée avant la soumission.';
        }

        // --- Réponses toujours requises ---
        foreach (self::REPONSES_REQUISES as $champ) {
            if ($r === null || $r->{$champ} === null) {
                $erreurs["reponses.{$champ}"][] = 'Champ obligatoire.';
            }
        }

        // --- Lettre de motivation ---
        $lettre = $r?->mo04_lettre_motivation;
        if (blank($lettre) || mb_strlen(trim((string) $lettre)) < self::LETTRE_MIN) {
            $erreurs['reponses.mo04_lettre_motivation'][] = 'La lettre de motivation doit faire au moins 30 caractères.';
        }

        // --- Conditionnels inter-champs (D-3a-4) ---
        if ($r?->sc05_beneficiaire_formation_actuelle === 'non') {
            if ($r->sc06_deja_beneficie_formation === null) {
                $erreurs['reponses.sc06_deja_beneficie_formation'][] = 'Champ obligatoire.';
            }
            if ($r->sc06_deja_beneficie_formation === 'oui') {
                if (blank($r->sc07_filiere_suivie)) {
                    $erreurs['reponses.sc07_filiere_suivie'][] = 'Champ obligatoire.';
                }
                if ($r->sc08_mene_a_terme === null) {
                    $erreurs['reponses.sc08_mene_a_terme'][] = 'Champ obligatoire.';
                }
                if ($r->sc08_mene_a_terme === 'non' && blank($r->sc09_motif_non_achevement)) {
                    $erreurs['reponses.sc09_motif_non_achevement'][] = 'Champ obligatoire.';
                }
            }
        }
        if ($r?->se03_situation_emploi === 'sans_emploi' && $r->se04_source_revenu === null) {
            $erreurs['reponses.se04_source_revenu'][] = 'Champ obligatoire.';
        }

        // --- Expériences : chacune domaine + durée + justificatif ---
        foreach ($candidature->experiences as $i => $exp) {
            $n = $i + 1;
            if (blank($exp->domaine)) {
                $erreurs["experiences.{$i}.domaine"][] = "Domaine manquant (expérience {$n}).";
            }
            if (blank($exp->duree_categorie)) {
                $erreurs["experiences.{$i}.duree_categorie"][] = "Durée manquante (expérience {$n}).";
            }
            if ($exp->piece_justificative_id === null) {
                $erreurs["experiences.{$i}.justificatif"][] = "Justificatif manquant (expérience {$n}).";
            }
        }

        // --- Pièces du dossier : les 6 types ---
        $presents = $candidature->piecesDossier->pluck('type_document_code')->all();
        $manquants = array_values(array_diff(ContraintesFichier::TYPES_DOSSIER, $presents));
        if ($manquants !== []) {
            $erreurs['pieces_dossier'][] = 'Pièces manquantes : '.implode(', ', $manquants).'.';
        }

        return $erreurs;
    }
}
