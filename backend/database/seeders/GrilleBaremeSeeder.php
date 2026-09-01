<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BARÈME COMPLET EN BASE — grille v1.
 *
 * Transcription 1:1 de App_maquette/assets/js/scoring.js (constantes
 * CASA_GRILLE et CASA_CRITERES_PRIORITE). Aucune valeur inventée.
 *
 * Rappels de fidélité (cf. Étape 1) :
 *  - D1 : la grille est seedée en `version = 1` (1re grille officielle de
 *    production). Le "version: 2" de scoring.js est un versionnage interne
 *    maquette.
 *  - D4 : rubrique `langues` -> max_points = 10 (poids officiel). Les items
 *    LANG.FR / LANG.INFO valent 6 et 3 (Σ = 9). Le passage de 9 à 10 est un
 *    rééchelonnage ALGORITHMIQUE de ServiceScoring (ADR-06), pas une donnée :
 *    on ne "corrige" donc PAS les items à 10.
 *  - D5 : MO.04 -> item.max_points = 15 (contribution réelle à la rubrique).
 *    L'échelle 0-5 étoiles vit dans reponse_formulaire.mo04_note_etoiles ; la
 *    conversion étoiles -> points (x3) reste dans ServiceScoring (ADR-06).
 *  - D6 : les constantes purement algorithmiques (moisApprox, seuils de durée,
 *    rameneSur, pointsParEtoile, bornes d'âge, seuil de français) ne sont PAS
 *    seedées — ce sont des règles de calcul, pas du référentiel.
 */
class GrilleBaremeSeeder extends Seeder
{
    public function run(): void
    {
        $grilleId = (string) Str::uuid();

        DB::table('grille')->insert([
            'id' => $grilleId,
            'version' => 1,
            'label' => 'Grille officielle CASA — v1 (Dossier /65 + Entretien /35)',
            'date_effet' => '2026-05-01',
            'actif' => true,
            'created_at' => now(),
        ]);

        // --- Volets ---
        $voletDossierId = (string) Str::uuid();
        $voletEntretienId = (string) Str::uuid();
        DB::table('volet')->insert([
            ['id' => $voletDossierId,   'grille_id' => $grilleId, 'code' => 'dossier',   'label' => 'Dossier de candidature', 'max_points' => 65],
            ['id' => $voletEntretienId, 'grille_id' => $grilleId, 'code' => 'entretien', 'label' => 'Entretien',              'max_points' => 35],
        ]);

        $this->seedVoletDossier($voletDossierId);
        $this->seedVoletEntretien($voletEntretienId);
        $this->seedCriteresPriorite($grilleId);
    }

    private function rubrique(string $voletId, string $code, string $label, float $maxPoints, int $ordre): string
    {
        $id = (string) Str::uuid();
        DB::table('rubrique')->insert([
            'id' => $id,
            'volet_id' => $voletId,
            'code' => $code,
            'label' => $label,
            'max_points' => $maxPoints,
            'ordre' => $ordre,
        ]);

        return $id;
    }

    /**
     * @param  array<int,array{0:string,1:string,2:float}>  $options  [valeur, label, points] (eliminatoire via 4e élément optionnel)
     */
    private function item(string $rubriqueId, array $def, array $options = []): void
    {
        $itemId = (string) Str::uuid();
        DB::table('item')->insert([
            'id' => $itemId,
            'rubrique_id' => $rubriqueId,
            'code' => $def['code'],
            'label' => $def['label'],
            'type' => $def['type'],
            'max_points' => $def['max_points'] ?? null,
            'notation_evaluateur' => $def['notation_evaluateur'] ?? false,
            'notee' => $def['notee'] ?? true,
            'eliminatoire' => $def['eliminatoire'] ?? false,
            'eliminatoire_groupe' => $def['eliminatoire_groupe'] ?? null,
        ]);

        foreach ($options as $opt) {
            DB::table('option_item')->insert([
                'id' => (string) Str::uuid(),
                'item_id' => $itemId,
                'valeur' => $opt[0],
                'label' => $opt[1],
                'points' => $opt[2],
                'eliminatoire' => $opt[3] ?? false,
            ]);
        }
    }

    private function seedVoletDossier(string $voletId): void
    {
        /* ---- Profil scolaire /12 ---- */
        $r = $this->rubrique($voletId, 'scolaire', 'Profil scolaire', 12, 1);
        $this->item($r, ['code' => 'SC.01', 'label' => 'Êtes-vous actuellement scolarisé(e) ?', 'type' => 'choix', 'max_points' => 1.5], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 1.5],
        ]);
        $this->item($r, ['code' => 'SC.02', 'label' => 'Dernière classe fréquentée', 'type' => 'choix', 'max_points' => 4.5], [
            ['avant_3e', 'Antérieure à la 3ème', 0],
            ['cap', 'Cycle CAP', 0],
            ['3e', '3ème', 1.5],
            ['seconde', 'Seconde', 1.5],
            ['1ere', '1ère', 3],
            ['terminale', 'Terminale', 4.5],
            ['bt_bep', 'Cycle BT/BEP', 4.5],
        ]);
        $this->item($r, ['code' => 'SC.03', 'label' => "Dispose d'un document justifiant le niveau", 'type' => 'choix', 'max_points' => 1.5], [
            ['oui', 'Oui', 1.5],
            ['non', 'Non', 0],
        ]);
        $this->item($r, ['code' => 'SC.04', 'label' => 'Plus haut diplôme obtenu', 'type' => 'choix', 'max_points' => 3, 'notation_evaluateur' => true], [
            ['cepe', 'CEPE', 0, true],
            ['cap', 'CAP', 0],
            ['bepc', 'BEPC', 1.5],
            ['bac', 'BAC', 3],
            ['bt_bep', 'BT/BEP', 3],
        ]);
        $this->item($r, ['code' => 'SC.05', 'label' => "Bénéficiaire actuel d'un programme de formation professionnelle ?", 'type' => 'choix', 'max_points' => 1.5], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 1.5],
        ]);
        $this->item($r, ['code' => 'SC.06', 'label' => "A déjà bénéficié d'un programme de formation professionnelle ?", 'type' => 'choix', 'notee' => false], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 0],
        ]);
        $this->item($r, ['code' => 'SC.07', 'label' => 'Filière et certification suivies', 'type' => 'texte', 'notee' => false]);
        $this->item($r, ['code' => 'SC.08', 'label' => 'Programme mené à terme ?', 'type' => 'choix', 'notee' => false], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 0],
        ]);
        $this->item($r, ['code' => 'SC.09', 'label' => 'Motif de non-achèvement', 'type' => 'texte', 'notee' => false]);
        $this->item($r, ['code' => 'SC.10', 'label' => "Pièce justificative d'achèvement", 'type' => 'document', 'notee' => false]);

        /* ---- Situation socio-économique /13 ---- */
        $r = $this->rubrique($voletId, 'socioEco', 'Situation socio-économique', 13, 2);
        $this->item($r, ['code' => 'SE.01', 'label' => 'Avec lequel des parents biologiques vivez-vous ?', 'type' => 'choix', 'notee' => false], [
            ['pere', 'Père', 0],
            ['mere', 'Mère', 0],
            ['les_deux', 'Les deux', 0],
            ['aucun', 'Aucun', 0],
        ]);
        $this->item($r, ['code' => 'SE.02', 'label' => 'Êtes-vous orphelin(e) ?', 'type' => 'choix', 'max_points' => 3.25], [
            ['oui', 'Oui', 3.25],
            ['non', 'Non', 0],
        ]);
        $this->item($r, ['code' => 'SE.03', 'label' => "Situation d'emploi actuelle", 'type' => 'choix', 'max_points' => 3.25], [
            ['sans_emploi', 'Sans emploi / sans opportunité', 3.25],
            ['stage', 'Stage', 0],
            ['interim', 'Intérim', 0],
            ['temps_partiel', 'Emploi à temps partiel', 0],
            ['temps_plein', 'Emploi à temps plein', 0],
        ]);
        $this->item($r, ['code' => 'SE.04', 'label' => 'Principale source de revenu (si sans emploi)', 'type' => 'choix', 'max_points' => 3.25], [
            ['parent', 'Parent biologique ou non', 3.25],
            ['conjoint', 'Conjoint(e)', 3.25],
            ['agr', 'Activité génératrice de revenus', 0],
            ['aucune', 'Aucune', 0],
        ]);
        $this->item($r, ['code' => 'SE.05', 'label' => 'Nombre de personnes à charge', 'type' => 'choix', 'notee' => false], [
            ['0', '0', 0],
            ['1-2', '1 à 2', 0],
            ['3+', '3 et plus', 0],
        ]);
        $this->item($r, ['code' => 'SE.06', 'label' => 'Êtes-vous le soutien principal du ménage ?', 'type' => 'choix', 'max_points' => 3.25], [
            ['oui', 'Oui', 3.25],
            ['non', 'Non', 0],
        ]);

        /* ---- Expérience professionnelle /5 (items dérivés, pas d'options) ---- */
        $r = $this->rubrique($voletId, 'experience', 'Expérience professionnelle', 5, 3);
        $this->item($r, ['code' => 'EXP.DOMAINES', 'label' => "Domaine(s) d'expérience", 'type' => 'derive', 'max_points' => 2.5]);
        $this->item($r, ['code' => 'EXP.DUREE', 'label' => "Durée cumulée d'expérience", 'type' => 'derive', 'max_points' => 2.5]);

        /* ---- Langues & compétences informatiques /10 ---- */
        // D4 : Σ items = 6 + 3 = 9 ; le rééchelonnage /9 -> /10 est dans ServiceScoring.
        $r = $this->rubrique($voletId, 'langues', 'Langues & compétences informatiques', 10, 4);
        $this->item($r, ['code' => 'LANG.FR', 'label' => 'Français (écrit / parlé / compréhension)', 'type' => 'niveaux', 'max_points' => 6]);
        $this->item($r, ['code' => 'LANG.INFO', 'label' => 'Informatique (Word / Excel / Internet)', 'type' => 'niveaux', 'max_points' => 3]);

        /* ---- Motivation (dossier) /15 ---- */
        $r = $this->rubrique($voletId, 'motivation', 'Motivation (dossier)', 15, 5);
        $this->item($r, ['code' => 'MO.03', 'label' => "Classement des filières par ordre d'intérêt", 'type' => 'classement', 'notee' => false]);
        $this->item($r, ['code' => 'MO.04', 'label' => 'Motivations à suivre la formation', 'type' => 'texte_note', 'max_points' => 15, 'notation_evaluateur' => true]);

        /* ---- Disponibilité /10 ---- */
        $r = $this->rubrique($voletId, 'disponibilite', 'Disponibilité', 10, 6);
        $this->item($r, ['code' => 'DI.01', 'label' => 'Disponible du lundi au vendredi pendant toute la formation', 'type' => 'choix', 'notee' => false, 'eliminatoire' => true], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 0],
        ]);
        $this->item($r, ['code' => 'DI.02', 'label' => 'Contraintes familiales / professionnelles', 'type' => 'choix', 'max_points' => 10], [
            ['aucune', 'Aucune contrainte déclarée', 10],
            ['gerable', 'Contrainte déclarée mais jugée gérable', 5],
            ['bloquante', 'Contrainte bloquante', 0],
        ]);
        $this->item($r, ['code' => 'DI.03', 'label' => "Engagement à suivre l'intégralité de la formation", 'type' => 'choix', 'notee' => false, 'eliminatoire' => true], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 0],
        ]);
        $this->item($r, ['code' => 'DI.04', 'label' => 'Pouvez-vous suivre les formations au Plateau ?', 'type' => 'choix', 'notee' => false, 'eliminatoire_groupe' => 'acces_sites'], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 0],
        ]);
        $this->item($r, ['code' => 'DI.05', 'label' => 'Pouvez-vous suivre les formations aux 2 Plateaux Vallons ?', 'type' => 'choix', 'notee' => false, 'eliminatoire_groupe' => 'acces_sites'], [
            ['oui', 'Oui', 0],
            ['non', 'Non', 0],
        ]);
    }

    private function seedVoletEntretien(string $voletId): void
    {
        $rubriques = [
            ['presentation', 'Présentation', 8, 1, [
                ['PRES.01', 'Tenue et posture professionnelle', 3],
                ['PRES.02', "Ponctualité et respect du cadre de l'entretien", 2],
                ['PRES.03', 'Clarté de la présentation personnelle', 3],
            ]],
            ['relationnel', 'Aptitudes relationnelles / sens du service', 10, 2, [
                ['REL.01', "Écoute et qualité d'échange avec le jury", 3],
                ['REL.02', 'Courtoisie, politesse, savoir-être', 3],
                ['REL.03', 'Sens du service et orientation client (mise en situation)', 4],
            ]],
            ['expressionOrale', 'Expression orale', 8, 3, [
                ['EO.01', "Clarté et fluidité de l'expression", 3],
                ['EO.02', 'Qualité du vocabulaire et correction du langage', 3],
                ['EO.03', 'Capacité à structurer une réponse', 2],
            ]],
            ['motivationEntretien', 'Expression des motivations', 9, 4, [
                ['MOE.01', 'Cohérence du projet professionnel avec la filière choisie', 3],
                ['MOE.02', "Conviction et authenticité de la motivation exprimée à l'oral", 3],
                ['MOE.03', 'Connaissance du métier et du programme CASA', 3],
            ]],
        ];

        foreach ($rubriques as [$code, $label, $poids, $ordre, $sousCriteres]) {
            $rubriqueId = $this->rubrique($voletId, $code, $label, $poids, $ordre);
            foreach ($sousCriteres as [$scCode, $scLabel, $scMax]) {
                DB::table('sous_critere_entretien')->insert([
                    'id' => (string) Str::uuid(),
                    'rubrique_id' => $rubriqueId,
                    'code' => $scCode,
                    'label' => $scLabel,
                    'max_points' => $scMax,
                ]);
            }
        }
    }

    private function seedCriteresPriorite(string $grilleId): void
    {
        $criteres = [
            [1, 'mixite', 'Mixité — participation des jeunes filles'],
            [2, 'vulnerabilite', 'Vulnérabilité socio-économique (NEET)'],
            [3, 'experience_secteur', 'Expérience dans le secteur hôtellerie-restauration'],
            [4, 'motivation', 'Motivation démontrée'],
        ];

        foreach ($criteres as [$ordre, $code, $label]) {
            DB::table('critere_priorite')->insert([
                'id' => (string) Str::uuid(),
                'grille_id' => $grilleId,
                'ordre' => $ordre,
                'code' => $code,
                'label' => $label,
            ]);
        }
    }
}
