<?php

namespace App\Domain\Export;

use App\Domain\Rapports\RapportExcel;
use App\Domain\Rapports\ServiceRapports;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Sérialise en .xlsx le résultat DÉJÀ MASQUÉ de
 * {@see ServiceExportAnalyse::construire()} — 4 feuilles (Données,
 * Dictionnaire, Villes, Synthèse). Même principe que
 * {@see RapportExcel} : ne recalcule rien, ne connaît
 * aucun garde-fou — consomme une structure déjà passée au crible du masquage.
 */
class AnalyseExcel
{
    private const FOND_TITRE = 'D6E9E3';

    private const FOND_ENTETE = 'F0F2F5';

    /** Colonnes dont la valeur doit être écrite comme un NOMBRE (le reste : texte explicite, jamais d'auto-conversion Excel). */
    private const COLONNES_NUMERIQUES = [
        'delai_inscription_soumission_jours',
        'exp_hotellerie_nb', 'exp_restauration_nb', 'exp_commerce_nb',
        'nb_pieces_dossier', 'nb_justificatifs_experience',
        'langue_ecrit', 'langue_parle', 'langue_comprehension',
        'info_word', 'info_excel', 'info_internet',
    ];

    private const DESCRIPTIONS = [
        'id_pseudonyme' => 'Identifiant PSEUDONYME de la candidature (HMAC-SHA256 tronqué, clé applicative) — stable, mais PAS anonyme : réversible par qui détient la base source. La protection réelle est la diffusion restreinte de ce fichier.',
        'campagne' => 'Nom de la campagne de la candidature.',
        'filiere' => 'Filière effectivement soumise par la candidature (peut différer du 1er choix si la liste des préférences a changé depuis).',
        'preference_rang_1' => 'Filière classée en 1er choix (classement_filiere_preference).',
        'preference_rang_2' => 'Filière classée en 2e choix.',
        'preference_rang_3' => 'Filière classée en 3e choix.',
        'preference_rang_4' => 'Filière classée en 4e choix.',
        'preference_rang_5' => 'Filière classée en 5e choix.',
        'sexe' => 'F ou H.',
        'tranche_age' => "Âge calculé à la date d'ouverture de LA CAMPAGNE de la candidature (même référence que la règle d'éligibilité 18-30 appliquée à la soumission, App\\Domain\\Eligibilite\\ServiceEligibilite::evaluerSoumission) — PAS la date du jour de l'export, PAS la date d'inscription. Tranches de 3 ans, jamais la date de naissance brute.",
        'residence_ci' => "Déclaré par le candidat à l'inscription (oui = réside en Côte d'Ivoire).",
        'zone' => "Abidjan / Intérieur / Étranger / Non précisée / « Hors Abidjan » / « Non précisée / Autre » — classification de `ville_residence` (champ libre) via la table de correspondance versionnée (App\\Domain\\Export\\NormalisationVille). Calculée UNIQUEMENT à partir de la ville saisie librement par le candidat, SANS recoupement avec sa déclaration de résidence en Côte d'Ivoire (`residence_ci`, posée séparément et irrévocablement à l'inscription) : une valeur « Étranger »/« Hors Abidjan » peut donc coexister avec `residence_ci = oui` — c'est une saisie du candidat, non corrigée par cet export. Toute zone hors Abidjan/Intérieur dont l'effectif global est < ".ServiceRapports::SEUIL_MASQUAGE.' est fusionnée dans Intérieur sous « Hors Abidjan » (jamais affichée seule) — voir la note ADR-38 ci-dessous.',
        'commune_abidjan' => "Commune du district d'Abidjan si précisée ET si son effectif global est ≥ 5 — sinon « Autre commune » (k-anonymat, seuil réutilisé de ServiceRapports). Vide si la zone n'est pas Abidjan ou si aucune commune n'a pu être précisée.",
        'ville_normalisee' => 'Pour la zone Intérieur (non fusionnée) : nom de ville si effectif global ≥ 5, sinon « Autre ville ». Pour Abidjan : toujours « Abidjan » (le détail est dans commune_abidjan). Pour une zone fusionnée sous « Hors Abidjan » (ADR-38) : toujours « Autre ville ». « Abidjan (commune non précisée) » est le cas le PLUS fréquent : ville_residence est un champ libre côté candidat, beaucoup ne précisent pas de commune — limite de la donnée source, pas un défaut de la normalisation.',
        'date_inscription' => 'Date de création du compte (utilisateur.created_at).',
        'date_soumission' => 'Date de soumission de la candidature (candidature.date_soumission), vide si jamais soumise.',
        'delai_inscription_soumission_jours' => 'Nombre de jours entre inscription et soumission, vide si jamais soumise.',
        'etat_dossier' => 'statut_interne brut : brouillon / soumis / en_instruction / non_eligible / evalue — TOUS les états sont inclus dans cette feuille (y compris brouillon) ; filtrez sur cette colonne dans le tableur pour restreindre.',
        'etat_eligibilite' => 'statut_eligibilite_interne brut : non_verifie / eligible / non_eligible.',
        'dossier_verrouille' => 'oui/non.',
        'cqp_confirme' => 'oui/non.',
        'criteres_eliminatoires' => 'Liste « code:origine » séparée par « ; » (ex. age_min:soumission_candidat) — JAMAIS le champ `detail` (texte libre, risque de ré-identification).',
        'sc01_scolarise_actuellement' => 'oui/non.',
        'sc02_derniere_classe' => 'avant_3e / 3e / seconde / 1ere / terminale / cap / bt_bep.',
        'sc03_document_justifiant_niveau' => 'oui/non.',
        'sc05_beneficiaire_formation_actuelle' => 'oui/non.',
        'sc06_deja_beneficie_formation' => 'oui/non.',
        'sc08_mene_a_terme' => 'oui/non.',
        'se01_vit_avec' => 'pere / mere / les_deux / aucun.',
        'se02_orphelin' => 'oui/non.',
        'se03_situation_emploi' => 'sans_emploi / stage / interim / temps_partiel / temps_plein.',
        'se04_source_revenu' => 'parent / conjoint / agr / aucune.',
        'se05_personnes_a_charge' => '0 / 1-2 / 3+.',
        'se06_soutien_menage' => 'oui/non.',
        'langue_ecrit' => 'Échelle 0 à 3.',
        'langue_parle' => 'Échelle 0 à 3.',
        'langue_comprehension' => 'Échelle 0 à 3.',
        'info_word' => 'Échelle 0 à 3.',
        'info_excel' => 'Échelle 0 à 3.',
        'info_internet' => 'Échelle 0 à 3.',
        'acces_plateau' => 'oui/non.',
        'acces_deux_plateaux_vallons' => 'oui/non.',
        'di01_disponible_lun_ven' => 'oui/non.',
        'di02_contraintes' => 'aucune / gerable / bloquante.',
        'di03_engagement_complet' => 'oui/non.',
        'exp_hotellerie_nb' => "Nombre d'expériences professionnelles déclarées, domaine hôtellerie.",
        'exp_hotellerie_duree_max' => 'moins_6 / 6_12 / plus_12 — la plus longue des expériences hôtellerie.',
        'exp_restauration_nb' => "Nombre d'expériences professionnelles déclarées, domaine restauration.",
        'exp_restauration_duree_max' => 'moins_6 / 6_12 / plus_12 — la plus longue des expériences restauration.',
        'exp_commerce_nb' => "Nombre d'expériences professionnelles déclarées, domaine commerce.",
        'exp_commerce_duree_max' => 'moins_6 / 6_12 / plus_12 — la plus longue des expériences commerce.',
        'nb_pieces_dossier' => "Nombre de pièces justificatives du DOSSIER (rattachement='dossier') — distinct de nb_justificatifs_experience.",
        'nb_justificatifs_experience' => 'Nombre de pièces justificatives liées à une expérience professionnelle (experience_professionnelle.piece_justificative_id) — distinct de nb_pieces_dossier.',
    ];

    private const NOTE_PIECE = "Généré à l'exécution depuis la table `type_document` EN VIGUEUR (jamais une liste codée en dur) — oui/non.";

    /** Liste d'exclusion communiquée pour ce lot — testée automatiquement (cf. tests/Feature/Console/ExportAnalyseCommandTest.php). */
    private const CHAMPS_EXCLUS = [
        'prenom', 'nom', 'cni', 'telephone', 'numero_cmu', 'email', 'date_naissance',
        'ville brute (candidat.ville_residence non normalisée)', 'numero_dossier', 'UUID (candidature.id)',
        'commentaire_evaluateur', 'evaluateur_id', 'date_evaluation',
        'toute note ou score (evaluation_dossier, score_rubrique_dossier, entretien, note_sous_critere_entretien, mo04_note_etoiles)',
        'decision_candidature', 'remplacement', 'verification_dossier', 'mo04_lettre_motivation',
        'sc07_filiere_suivie', 'sc09_motif_non_achevement', 'journal_audit',
    ];

    /**
     * @param  array<string, mixed>  $resultat  sortie non bloquée de ServiceExportAnalyse::construire()
     */
    public function generer(array $resultat): string
    {
        $spreadsheet = new Spreadsheet;

        $this->feuilleDonnees($spreadsheet->getActiveSheet(), $resultat);
        $this->feuilleDictionnaire($spreadsheet->createSheet(), $resultat['colonnes']);
        $this->feuilleVilles($spreadsheet->createSheet(), $resultat['villes']);
        $this->feuilleSynthese($spreadsheet->createSheet(), $resultat['synthese']);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * @param  array<string, mixed>  $resultat
     */
    private function feuilleDonnees(Worksheet $sheet, array $resultat): void
    {
        $sheet->setTitle('Données');
        /** @var list<string> $colonnes */
        $colonnes = $resultat['colonnes'];
        /** @var CarbonImmutable $genereLe */
        $genereLe = $resultat['genere_le'];

        $sheet->setCellValueExplicit('A1', 'Export CASA — instantané du '.$genereLe->format('Y-m-d H:i:s'), DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $ligneEntetes = 3;
        foreach ($colonnes as $i => $nom) {
            $colonne = $this->lettre($i);
            $sheet->setCellValueExplicit("{$colonne}{$ligneEntetes}", $nom, DataType::TYPE_STRING);
        }
        $derniereColonne = $this->lettre(count($colonnes) - 1);
        $sheet->getStyle("A{$ligneEntetes}:{$derniereColonne}{$ligneEntetes}")->getFont()->setBold(true);
        $sheet->getStyle("A{$ligneEntetes}:{$derniereColonne}{$ligneEntetes}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FOND_ENTETE);

        $ligne = $ligneEntetes + 1;
        foreach ($resultat['lignes'] as $donnee) {
            foreach ($colonnes as $i => $nom) {
                $colonne = $this->lettre($i);
                $valeur = $donnee[$nom] ?? null;
                if (in_array($nom, self::COLONNES_NUMERIQUES, true) && $valeur !== null) {
                    $sheet->setCellValue("{$colonne}{$ligne}", (int) $valeur);
                } else {
                    $sheet->setCellValueExplicit("{$colonne}{$ligne}", (string) ($valeur ?? ''), DataType::TYPE_STRING);
                }
            }
            $ligne++;
        }

        foreach (range(0, count($colonnes) - 1) as $i) {
            $sheet->getColumnDimension($this->lettre($i))->setWidth(16);
        }
    }

    /**
     * @param  list<string>  $colonnes
     */
    private function feuilleDictionnaire(Worksheet $sheet, array $colonnes): void
    {
        $sheet->setTitle('Dictionnaire');

        $ligne = 1;
        $sheet->setCellValue("A{$ligne}", 'Colonne');
        $sheet->setCellValue("B{$ligne}", 'Description');
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getFont()->setBold(true);
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FOND_ENTETE);
        $ligne++;

        foreach ($colonnes as $nom) {
            $description = self::DESCRIPTIONS[$nom]
                ?? (str_starts_with($nom, 'piece_') ? self::NOTE_PIECE : '');
            $sheet->setCellValueExplicit("A{$ligne}", $nom, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$ligne}", $description, DataType::TYPE_STRING);
            $sheet->getStyle("A{$ligne}")->getAlignment()->setVertical('top');
            $sheet->getStyle("B{$ligne}")->getAlignment()->setWrapText(true);
            $ligne++;
        }

        $ligne++;
        $ligne = $this->titreSection($sheet, $ligne, 'Notes');
        $notes = [
            'Fichier pseudonymisé : donnée personnelle à diffusion restreinte, ne pas publier les lignes.',
            "Masquage : toute ville/commune/catégorie d'effectif global < ".ServiceRapports::SEUIL_MASQUAGE.' candidatures est masquée — jamais affichée en clair.',
            "Feuille Synthèse : commodité de présentation — ses comptages sont recalculables depuis la feuille Données (1 ligne = 1 candidature). La protection réelle de ce fichier n'est donc PAS l'agrégation de Synthèse, mais la diffusion RESTREINTE du fichier : id_pseudonyme est un pseudonyme stable (réversible par qui détient la base), pas une donnée anonyme.",
            'Ville normalisée « À classer » : si elle apparaît, la commande casa:export-analyse refuse de produire ce fichier (contrôle automatique) — elle ne peut donc jamais être présente ici.',
            "Fusion « Hors Abidjan » (ADR-38) : toute zone hors Abidjan/Intérieur (Étranger, Non précisée, future zone) dont l'effectif global est < ".ServiceRapports::SEUIL_MASQUAGE.' est fusionnée DANS le groupe Intérieur (qui grossit un groupe déjà au-dessus du seuil — jamais un simple renommage isolé) : zone devient « Hors Abidjan » pour ces lignes ET pour toutes les lignes Intérieur, ville_normalisee devient « Autre ville » pour les lignes fusionnées. Cas limite (non rencontré en pratique à ce volume) : si Intérieur + zones rares combinés restaient eux-mêmes sous le seuil, la fusion s\'étendrait aussi à Abidjan sous « Non précisée / Autre », pour toutes les lignes de l\'export.',
        ];
        foreach ($notes as $note) {
            $sheet->mergeCells("A{$ligne}:B{$ligne}");
            $sheet->setCellValueExplicit("A{$ligne}", '• '.$note, DataType::TYPE_STRING);
            $sheet->getStyle("A{$ligne}")->getAlignment()->setWrapText(true);
            $ligne++;
        }

        $ligne++;
        $ligne = $this->titreSection($sheet, $ligne, 'Exclusions garanties (jamais dans ce fichier)');
        foreach (self::CHAMPS_EXCLUS as $champExclu) {
            $sheet->setCellValueExplicit("A{$ligne}", $champExclu, DataType::TYPE_STRING);
            $ligne++;
        }

        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(90);
    }

    /**
     * @param  array{effectifs: array<string, int|string>, total: ?int}  $villes
     */
    private function feuilleVilles(Worksheet $sheet, array $villes): void
    {
        $sheet->setTitle('Villes');
        $ligne = $this->titre($sheet, 1, 'Répartition par ville / commune (agrégée)');
        $ligne = $this->entetes($sheet, $ligne, ['Ville / commune', 'Candidatures']);

        foreach ($villes['effectifs'] as $libelle => $valeur) {
            $ligne = $this->paire($sheet, $ligne, $libelle, $valeur);
        }

        if ($villes['total'] !== null) {
            $ligne++;
            $ligne = $this->paire($sheet, $ligne, 'Total', $villes['total']);
        }

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(16);
    }

    /**
     * @param  array<string, array{effectifs: array<string, int|string>, total: ?int}>  $synthese
     */
    private function feuilleSynthese(Worksheet $sheet, array $synthese): void
    {
        $sheet->setTitle('Synthèse');
        $ligne = 1;

        foreach (['sexe' => 'Sexe', 'zone' => 'Zone', 'filiere' => 'Filière'] as $cle => $titre) {
            $ligne = $this->titreSection($sheet, $ligne, $titre);
            $ligne = $this->entetes($sheet, $ligne, [$titre, 'Candidatures']);
            foreach ($synthese[$cle]['effectifs'] as $libelle => $valeur) {
                $ligne = $this->paire($sheet, $ligne, $libelle, $valeur);
            }
            if ($synthese[$cle]['total'] !== null) {
                $ligne = $this->paire($sheet, $ligne, 'Total', $synthese[$cle]['total']);
            }
            $ligne++;
        }

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(16);
    }

    private function titre(Worksheet $sheet, int $ligne, string $texte): int
    {
        $sheet->setCellValueExplicit("A{$ligne}", $texte, DataType::TYPE_STRING);
        $sheet->mergeCells("A{$ligne}:B{$ligne}");
        $sheet->getStyle("A{$ligne}")->getFont()->setBold(true)->setSize(14);

        return $ligne + 2;
    }

    private function titreSection(Worksheet $sheet, int $ligne, string $texte): int
    {
        $sheet->setCellValueExplicit("A{$ligne}", $texte, DataType::TYPE_STRING);
        $sheet->mergeCells("A{$ligne}:B{$ligne}");
        $sheet->getStyle("A{$ligne}")->getFont()->setBold(true);
        $sheet->getStyle("A{$ligne}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FOND_TITRE);

        return $ligne + 1;
    }

    /**
     * @param  list<string>  $libelles
     */
    private function entetes(Worksheet $sheet, int $ligne, array $libelles): int
    {
        $sheet->setCellValueExplicit("A{$ligne}", $libelles[0], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("B{$ligne}", $libelles[1], DataType::TYPE_STRING);
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getFont()->setBold(true);
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FOND_ENTETE);
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        return $ligne + 1;
    }

    private function paire(Worksheet $sheet, int $ligne, string $libelle, int|string $valeur): int
    {
        $sheet->setCellValueExplicit("A{$ligne}", $libelle, DataType::TYPE_STRING);
        if (is_int($valeur)) {
            $sheet->setCellValue("B{$ligne}", $valeur);
        } else {
            $sheet->setCellValueExplicit("B{$ligne}", $valeur, DataType::TYPE_STRING);
        }

        return $ligne + 1;
    }

    /** Convertit un index de colonne 0-based en lettre(s) Excel (A, B, ..., Z, AA, ...). */
    private function lettre(int $index): string
    {
        $lettre = '';
        $index++;
        while ($index > 0) {
            $reste = ($index - 1) % 26;
            $lettre = chr(65 + $reste).$lettre;
            $index = intdiv($index - 1, 26);
        }

        return $lettre;
    }
}
