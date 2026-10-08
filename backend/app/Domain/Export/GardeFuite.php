<?php

namespace App\Domain\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Dernier filet AVANT livraison : relit RÉELLEMENT le .xlsx déjà écrit par
 * {@see AnalyseExcel::generer()} (pas la structure en mémoire — le fichier
 * tel qu'il existe sur disque) et vérifie qu'AUCUNE cellule, sur AUCUNE
 * feuille, n'égale EXACTEMENT une valeur identifiante connue de cet export
 * (cni, telephone, numero_cmu, email, numero_dossier, UUID candidat/
 * candidature/utilisateur — JAMAIS prenom/nom, faux positifs possibles avec
 * des noms de villes).
 *
 * Comparaison en ÉGALITÉ EXACTE (jamais en sous-chaîne) : un id_pseudonyme
 * (HMAC tronqué) peut légitimement contenir par hasard la même sous-chaîne
 * qu'un fragment d'identifiant sans que ce soit une fuite — seule une
 * cellule rigoureusement IDENTIQUE à une valeur interdite compte.
 *
 * Exécuté uniquement sur une génération RÉELLE (jamais `--dry-run`, qui
 * n'écrit aucun fichier) — cf. `ExportAnalyse::handle()`.
 */
final class GardeFuite
{
    /**
     * @param  array<string, string>  $valeursInterdites  valeur => catégorie
     * @return array{valeur: string, categorie: string}|null null si aucune fuite
     */
    public static function detecter(string $cheminFichier, array $valeursInterdites): ?array
    {
        if ($valeursInterdites === []) {
            return null;
        }

        $classeur = IOFactory::load($cheminFichier);
        foreach ($classeur->getAllSheets() as $feuille) {
            foreach ($feuille->getRowIterator() as $ligne) {
                foreach ($ligne->getCellIterator() as $cellule) {
                    $valeur = $cellule->getValue();
                    if ($valeur === null || $valeur === '') {
                        continue;
                    }
                    $valeur = (string) $valeur;
                    if (array_key_exists($valeur, $valeursInterdites)) {
                        return ['valeur' => $valeur, 'categorie' => $valeursInterdites[$valeur]];
                    }
                }
            }
        }

        return null;
    }
}
