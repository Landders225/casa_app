<?php

namespace App\Domain\Rapports;

/**
 * Sérialise en CSV le RÉSULTAT DÉJÀ AGRÉGÉ de {@see ServiceRapports::agreger()}
 * (Lot 11c, ADR-30).
 *
 * Ne fait AUCUN calcul : il consomme la structure agrégée. Le garde-fou
 * k-anonymat est donc appliqué EN AMONT, à l'identique de l'écran — une
 * distribution masquée (`null`) sort ici « n/d ». Aucune donnée individuelle ne
 * peut apparaître : la source n'en contient pas.
 *
 * Séparateur `;` + BOM UTF-8 (convention Excel FR).
 */
class RapportCsv
{
    private const MASQUE = 'n/d';

    /**
     * @param  array<string, mixed>  $donnees  sortie de ServiceRapports::agreger()
     */
    public function generer(array $donnees): string
    {
        $lignes = [];

        $perimetre = $donnees['perimetre'];
        $lignes[] = ['Rapport CASA — '.($perimetre['campagne']['nom'] ?? 'Toutes campagnes')];
        $lignes[] = ['Candidatures soumises (périmètre)', $perimetre['candidatures']];
        $lignes[] = ['Seuil de masquage (k-anonymat)', $perimetre['seuil_masquage']];
        $lignes[] = [];

        $k = $donnees['kpis'];
        $lignes[] = ['Indicateur', 'Valeur'];
        $lignes[] = ['Candidatures soumises', $k['candidatures']];
        $lignes[] = ['Candidatures éligibles', $k['eligibles']];
        $lignes[] = ['Candidatures évaluées', $k['evaluees']];
        $lignes[] = ['Retenus', $k['retenus']];
        $lignes[] = ["Taux d'éligibilité (%)", $k['taux_eligibilite'] ?? self::MASQUE];
        $lignes[] = ['Taux de sélection (%)', $k['taux_selection'] ?? self::MASQUE];
        $lignes[] = [];

        $lignes[] = ['Candidatures par filière'];
        $lignes[] = ['Filière', 'Candidatures'];
        foreach ($donnees['par_filiere'] as $row) {
            $lignes[] = [$row['filiere']['nom'], $row['candidatures']];
        }
        $lignes[] = [];

        $lignes[] = ['Répartition femmes / hommes'];
        $lignes[] = ['Sexe', 'Candidatures'];
        $sexe = $donnees['repartition_sexe'];
        $lignes[] = ['Femmes', $sexe['F'] ?? self::MASQUE];
        $lignes[] = ['Hommes', $sexe['H'] ?? self::MASQUE];
        $lignes[] = [];

        $lignes[] = ['Distribution des scores'];
        $lignes[] = ['Tranche', 'Effectif'];
        $dist = $donnees['distribution_scores'];
        $bornes = $dist['bornes'] ?? [0, 20, 40, 60, 80, 100];
        for ($i = 0; $i < count($bornes) - 1; $i++) {
            $lignes[] = [
                $bornes[$i].'–'.$bornes[$i + 1],
                $dist['effectifs'][$i] ?? self::MASQUE,
            ];
        }
        $lignes[] = [];

        $lignes[] = ['Répartition des décisions'];
        $lignes[] = ['Décision', 'Effectif'];
        $dec = $donnees['repartition_decisions'];
        foreach (['retenu' => 'Retenu', 'liste_attente' => "Liste d'attente", 'non_retenu' => 'Non retenu', 'indisponible' => 'Indisponible'] as $cle => $libelle) {
            $lignes[] = [$libelle, $dec[$cle] ?? self::MASQUE];
        }
        $lignes[] = [];

        $lignes[] = ["Présence à l'entretien"];
        $lignes[] = ['Statut', 'Effectif'];
        $pres = $donnees['presence_entretien'];
        $lignes[] = ['Présents', $pres['present'] ?? self::MASQUE];
        $lignes[] = ['Absents', $pres['absent'] ?? self::MASQUE];
        $lignes[] = [];

        $lignes[] = ['Répartition par ville'];
        $lignes[] = ['Ville', 'Candidatures'];
        foreach ($donnees['top_villes'] ?? [] as $row) {
            $lignes[] = [$row['ville'], $row['candidatures']];
        }
        if ($donnees['top_villes'] === null) {
            $lignes[] = [self::MASQUE, self::MASQUE];
        }

        $corps = implode("\r\n", array_map(
            fn (array $cellules) => implode(';', array_map($this->cellule(...), $cellules)),
            $lignes,
        ));

        return "\u{FEFF}".$corps."\r\n";
    }

    private function cellule(string|int|null $valeur): string
    {
        $texte = (string) ($valeur ?? '');

        if (str_contains($texte, ';') || str_contains($texte, '"') || str_contains($texte, "\n")) {
            return '"'.str_replace('"', '""', $texte).'"';
        }

        return $texte;
    }
}
