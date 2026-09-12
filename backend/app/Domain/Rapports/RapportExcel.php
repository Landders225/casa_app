<?php

namespace App\Domain\Rapports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Sérialise en .xlsx MIS EN FORME le RÉSULTAT DÉJÀ AGRÉGÉ de
 * {@see ServiceRapports::agreger()} (Lot 15c — extension de l'export CSV,
 * Lot 11c/ADR-30).
 *
 * Même principe que {@see RapportCsv} : ne fait AUCUN calcul, ne connaît
 * aucune donnée individuelle, consomme la structure déjà masquée par le
 * garde-fou k-anonymat — une distribution `null` sort ici « n/d », à
 * l'identique du CSV. Les DEUX exports (CSV, xlsx) et l'écran partagent le
 * même `ServiceRapports` : le masquage ne peut pas diverger entre eux.
 *
 * Valeur ajoutée par rapport au CSV : mise en forme exploitable par le CoPil
 * (titres, en-têtes en gras, colonnes dimensionnées) — pas un second format
 * de données, un second format de PRÉSENTATION des mêmes données.
 */
class RapportExcel
{
    private const MASQUE = 'n/d';

    private const FOND_TITRE = 'D6E9E3';

    private const FOND_ENTETE = 'F0F2F5';

    /**
     * @param  array<string, mixed>  $donnees  sortie de ServiceRapports::agreger()
     */
    public function generer(array $donnees): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rapport CASA');

        $ligne = 1;
        $perimetre = $donnees['perimetre'];

        $ligne = $this->titre($sheet, $ligne, 'Rapport CASA — '.($perimetre['campagne']['nom'] ?? 'Toutes campagnes'));
        $ligne = $this->paire($sheet, $ligne, 'Candidatures soumises (périmètre)', $perimetre['candidatures']);
        $ligne = $this->paire($sheet, $ligne, 'Seuil de masquage (k-anonymat)', $perimetre['seuil_masquage']);
        $ligne++;

        $ligne = $this->entetes($sheet, $ligne, ['Indicateur', 'Valeur']);
        $k = $donnees['kpis'];
        $ligne = $this->paire($sheet, $ligne, 'Candidatures soumises', $k['candidatures']);
        $ligne = $this->paire($sheet, $ligne, 'Candidatures éligibles', $k['eligibles']);
        $ligne = $this->paire($sheet, $ligne, 'Candidatures évaluées', $k['evaluees']);
        $ligne = $this->paire($sheet, $ligne, 'Retenus', $k['retenus']);
        $ligne = $this->paire($sheet, $ligne, "Taux d'éligibilité (%)", $k['taux_eligibilite'] ?? self::MASQUE);
        $ligne = $this->paire($sheet, $ligne, 'Taux de sélection (%)', $k['taux_selection'] ?? self::MASQUE);
        $ligne++;

        $ligne = $this->titreSection($sheet, $ligne, 'Candidatures par filière');
        $ligne = $this->entetes($sheet, $ligne, ['Filière', 'Candidatures']);
        foreach ($donnees['par_filiere'] as $row) {
            $ligne = $this->paire($sheet, $ligne, $row['filiere']['nom'], $row['candidatures']);
        }
        $ligne++;

        $ligne = $this->titreSection($sheet, $ligne, 'Répartition femmes / hommes');
        $ligne = $this->entetes($sheet, $ligne, ['Sexe', 'Candidatures']);
        $sexe = $donnees['repartition_sexe'];
        $ligne = $this->paire($sheet, $ligne, 'Femmes', $sexe['F'] ?? self::MASQUE);
        $ligne = $this->paire($sheet, $ligne, 'Hommes', $sexe['H'] ?? self::MASQUE);
        $ligne++;

        $ligne = $this->titreSection($sheet, $ligne, 'Distribution des scores');
        $ligne = $this->entetes($sheet, $ligne, ['Tranche', 'Effectif']);
        $dist = $donnees['distribution_scores'];
        $bornes = $dist['bornes'] ?? [0, 20, 40, 60, 80, 100];
        for ($i = 0; $i < count($bornes) - 1; $i++) {
            $ligne = $this->paire($sheet, $ligne, $bornes[$i].'–'.$bornes[$i + 1], $dist['effectifs'][$i] ?? self::MASQUE);
        }
        $ligne++;

        $ligne = $this->titreSection($sheet, $ligne, 'Répartition des décisions');
        $ligne = $this->entetes($sheet, $ligne, ['Décision', 'Effectif']);
        $dec = $donnees['repartition_decisions'];
        foreach (['retenu' => 'Retenu', 'liste_attente' => "Liste d'attente", 'non_retenu' => 'Non retenu', 'indisponible' => 'Indisponible'] as $cle => $libelle) {
            $ligne = $this->paire($sheet, $ligne, $libelle, $dec[$cle] ?? self::MASQUE);
        }
        $ligne++;

        $ligne = $this->titreSection($sheet, $ligne, "Présence à l'entretien");
        $ligne = $this->entetes($sheet, $ligne, ['Statut', 'Effectif']);
        $pres = $donnees['presence_entretien'];
        $ligne = $this->paire($sheet, $ligne, 'Présents', $pres['present'] ?? self::MASQUE);
        $ligne = $this->paire($sheet, $ligne, 'Absents', $pres['absent'] ?? self::MASQUE);
        $ligne++;

        $ligne = $this->titreSection($sheet, $ligne, 'Répartition par ville');
        $ligne = $this->entetes($sheet, $ligne, ['Ville', 'Candidatures']);
        foreach ($donnees['top_villes'] ?? [] as $row) {
            $ligne = $this->paire($sheet, $ligne, $row['ville'], $row['candidatures']);
        }
        if ($donnees['top_villes'] === null) {
            $this->paire($sheet, $ligne, self::MASQUE, self::MASQUE);
        }

        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(16);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function titre(Worksheet $sheet, int $ligne, string $texte): int
    {
        $sheet->setCellValue("A{$ligne}", $texte);
        $sheet->mergeCells("A{$ligne}:B{$ligne}");
        $sheet->getStyle("A{$ligne}")->getFont()->setBold(true)->setSize(14);

        return $ligne + 2;
    }

    private function titreSection(Worksheet $sheet, int $ligne, string $texte): int
    {
        $sheet->setCellValue("A{$ligne}", $texte);
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
        $sheet->setCellValue("A{$ligne}", $libelles[0]);
        $sheet->setCellValue("B{$ligne}", $libelles[1]);
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getFont()->setBold(true);
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FOND_ENTETE);
        $sheet->getStyle("A{$ligne}:B{$ligne}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        return $ligne + 1;
    }

    private function paire(Worksheet $sheet, int $ligne, string|int $libelle, string|int|null $valeur): int
    {
        $sheet->setCellValueExplicit("A{$ligne}", (string) $libelle, DataType::TYPE_STRING);
        if (is_int($valeur)) {
            $sheet->setCellValue("B{$ligne}", $valeur);
            $sheet->getStyle("B{$ligne}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        } else {
            $sheet->setCellValueExplicit("B{$ligne}", (string) ($valeur ?? ''), DataType::TYPE_STRING);
        }

        return $ligne + 1;
    }
}
