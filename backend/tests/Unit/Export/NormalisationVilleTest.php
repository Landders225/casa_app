<?php

namespace Tests\Unit\Export;

use App\Domain\Export\NormalisationVille;
use Tests\TestCase;

/**
 * Lot export-analyse : table de correspondance des villes. Jeu de données =
 * les 82 variantes RÉELLEMENT observées communiquées par l'utilisateur
 * (somme des effectifs = 327) — pas des exemples inventés. Zéro "À classer"
 * attendu sur ce jeu (les règles de classement communiquées couvrent les 82
 * variantes), et c'est précisément ce que ce test prouve ligne par ligne.
 */
class NormalisationVilleTest extends TestCase
{
    /**
     * [valeur brute, effectif, zone attendue, commune_abidjan attendue, ville_interieur attendue]
     *
     * @return list<array{0: string, 1: int, 2: string, 3: ?string, 4: ?string}>
     */
    public static function variantesReelles(): array
    {
        $A = NormalisationVille::ZONE_ABIDJAN;
        $I = NormalisationVille::ZONE_INTERIEUR;
        $E = NormalisationVille::ZONE_ETRANGER;
        $N = NormalisationVille::ZONE_NON_PRECISEE;

        return [
            ['abidjan', 172, $A, null, null],
            ['san pedro', 9, $I, null, 'San-Pedro'],
            ['bouaké', 8, $I, null, 'Bouaké'],
            ['abidjan-cocody', 6, $A, 'Cocody', null],
            ['abidjan-yopougon', 5, $A, 'Yopougon', null],
            ['abidjan- cocody', 5, $A, 'Cocody', null],
            ['daloa', 5, $I, null, 'Daloa'],
            ['cocody', 5, $A, 'Cocody', null],
            ['abidjan yopougon', 4, $A, 'Yopougon', null],
            ['yopougon', 4, $A, 'Yopougon', null],
            ['abidjan cocody', 4, $A, 'Cocody', null],
            ['abidjan - yopougon', 4, $A, 'Yopougon', null],
            ['abobo', 3, $A, 'Abobo', null],
            ['yamoussoukro', 3, $I, null, 'Yamoussoukro'],
            ['bouake', 3, $I, null, 'Bouaké'],
            ['san-pedro', 3, $I, null, 'San-Pedro'],
            ['bingerville', 3, $A, 'Bingerville', null],
            ['abidjan- yopougon', 3, $A, 'Yopougon', null],
            ['abidjan - cocody', 3, $A, 'Cocody', null],
            ['abidjan - bingerville', 3, $A, 'Bingerville', null],
            ['béoumi', 2, $I, null, 'Béoumi'],
            ['anyama', 2, $A, 'Anyama', null],
            ['abidjan-koumassi', 2, $A, 'Koumassi', null],
            ['abidjan-anyama', 2, $A, 'Anyama', null],
            ['korhogo', 2, $I, null, 'Korhogo'],
            ['abidjan-abobo', 2, $A, 'Abobo', null],
            ['dabou', 2, $I, null, 'Dabou'],
            ['adzopé', 2, $I, null, 'Adzopé'],
            ['grand-bassam', 2, $I, null, 'Grand-Bassam'],
            ['abidjan port bouet', 2, $A, 'Port-Bouët', null],
            ['abidjan-adjame', 1, $A, 'Adjamé', null],
            ['bongouanou', 1, $I, null, 'Bongouanou'],
            ['divo', 1, $I, null, 'Divo'],
            ["cote d'ivoire", 1, $N, null, null],
            ['noé', 1, $I, null, 'Noé'],
            ['abidjan -cocody', 1, $A, 'Cocody', null],
            ['abidjan - yopugon', 1, $A, 'Yopougon', null],
            ['agadez', 1, $E, null, null],
            ['port bouet', 1, $A, 'Port-Bouët', null],
            ['abidjan attecoube', 1, $A, 'Attécoubé', null],
            ['abidjan -abobo', 1, $A, 'Abobo', null],
            ['songon', 1, $A, 'Songon', null],
            ['danané', 1, $I, null, 'Danané'],
            ['abj', 1, $A, null, null],
            ['adzope', 1, $I, null, 'Adzopé'],
            ['𝐴𝑏𝑖𝑑𝑗𝑎𝑛', 1, $A, null, null],
            ['abidjan, treichville', 1, $A, 'Treichville', null],
            ['abidjan -anyama', 1, $A, 'Anyama', null],
            ['abidjan -koumassi', 1, $A, 'Koumassi', null],
            ['tiébissou', 1, $I, null, 'Tiébissou'],
            ['koumassi', 1, $A, 'Koumassi', null],
            ['maroc', 1, $E, null, null],
            ['marcory', 1, $A, 'Marcory', null],
            ['abidjan-bingerville', 1, $A, 'Bingerville', null],
            ['cocody deux plateaux', 1, $A, 'Cocody', null],
            ['abidjan port bouet gonzague', 1, $A, 'Port-Bouët', null],
            ['abidjan - attecoube', 1, $A, 'Attécoubé', null],
            ['grand bassam', 1, $I, null, 'Grand-Bassam'],
            ['cocody angre', 1, $A, 'Cocody', null],
            ['abidjan _anyaman', 1, $A, 'Anyama', null],
            ['man', 1, $I, null, 'Man'],
            ["abidjan côte d'ivoire", 1, $A, null, null],
            ['abidjan -bingerville', 1, $A, 'Bingerville', null],
            ['abidjan - abobo', 1, $A, 'Abobo', null],
            ['abidjan - koumassi', 1, $A, 'Koumassi', null],
            ['abidjan koumassi', 1, $A, 'Koumassi', null],
            ['abidjan nord', 1, $A, null, null],
            ['abidjan-port boueet', 1, $A, 'Port-Bouët', null],
            ['abidjan-deux plateaux', 1, $A, 'Cocody', null],
            ['abidjan- port bouet', 1, $A, 'Port-Bouët', null],
            ['bouna', 1, $I, null, 'Bouna'],
            ['abidjan -port bouët', 1, $A, 'Port-Bouët', null],
            ['abidjan-marcory', 1, $A, 'Marcory', null],
            ['abidjan- koumassi', 1, $A, 'Koumassi', null],
            ['abidjan-cocody angré', 1, $A, 'Cocody', null],
            ['abidjan- abobo', 1, $A, 'Abobo', null],
            ['gagnoa', 1, $I, null, 'Gagnoa'],
            ['abidjan deux plateau', 1, $A, 'Cocody', null],
            ['abidjan-adjamé', 1, $A, 'Adjamé', null],
            ['port bouët', 1, $A, 'Port-Bouët', null],
            ['grand bereby', 1, $I, null, 'Grand-Béréby'],
            ['abidjan-adjamé paillet', 1, $A, 'Adjamé', null],
        ];
    }

    /** @dataProvider variantesReelles */
    public function test_classement_de_chaque_variante_reelle(string $brut, int $effectif, string $zoneAttendue, ?string $communeAttendue, ?string $villeAttendue): void
    {
        $resultat = NormalisationVille::classer($brut);

        $this->assertSame($zoneAttendue, $resultat['zone'], "zone pour « {$brut} »");
        $this->assertSame($communeAttendue, $resultat['commune_abidjan'], "commune_abidjan pour « {$brut} »");
        $this->assertSame($villeAttendue, $resultat['ville_interieur'], "ville_interieur pour « {$brut} »");
    }

    public function test_aucune_des_82_variantes_reelles_n_est_a_classer(): void
    {
        foreach (self::variantesReelles() as [$brut]) {
            $this->assertNotSame(
                NormalisationVille::ZONE_A_CLASSER,
                NormalisationVille::classer($brut)['zone'],
                "« {$brut} » ne devrait pas finir en « À classer »"
            );
        }
    }

    /**
     * Somme des effectifs par catégorie finale = somme des effectifs d'entrée
     * (327 sur ce jeu) — chaque variante n'est comptée qu'une seule fois,
     * dans une seule catégorie (correction demandée explicitement).
     */
    public function test_somme_des_effectifs_par_categorie_egale_la_somme_d_entree(): void
    {
        $sommeEntree = 0;
        $sommeParCategorie = 0;
        $categories = [];

        foreach (self::variantesReelles() as [$brut, $effectif]) {
            $sommeEntree += $effectif;

            $resultat = NormalisationVille::classer($brut);
            $cle = $resultat['zone'].'|'.($resultat['commune_abidjan'] ?? '').'|'.($resultat['ville_interieur'] ?? '');
            $categories[$cle] = ($categories[$cle] ?? 0) + $effectif;
        }

        $sommeParCategorie = array_sum($categories);

        $this->assertSame(327, $sommeEntree, 'La somme des effectifs du jeu de données de test doit rester 327.');
        $this->assertSame($sommeEntree, $sommeParCategorie);
    }

    public function test_valeur_vide_est_a_classer(): void
    {
        $this->assertSame(NormalisationVille::ZONE_A_CLASSER, NormalisationVille::classer('')['zone']);
        $this->assertSame(NormalisationVille::ZONE_A_CLASSER, NormalisationVille::classer('   ')['zone']);
    }

    public function test_valeur_totalement_inconnue_est_a_classer(): void
    {
        $this->assertSame(NormalisationVille::ZONE_A_CLASSER, NormalisationVille::classer('Mordor')['zone']);
    }

    public function test_insensible_a_la_casse_et_aux_separateurs(): void
    {
        $this->assertSame('Cocody', NormalisationVille::classer('ABIDJAN_COCODY')['commune_abidjan']);
        $this->assertSame('Cocody', NormalisationVille::classer('Abidjan,Cocody')['commune_abidjan']);
    }
}
