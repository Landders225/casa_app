<?php

namespace App\Domain\Export;

use Normalizer;

/**
 * Table de correspondance EXPLICITE et VERSIONNÉE des villes/communes
 * déclarées par les candidats (`candidat.ville_residence`, champ libre) —
 * commande `casa:export-analyse` (ADR à venir).
 *
 * Ne fait AUCUN rapprochement flou (pas de distance de Levenshtein/fuzzy
 * matching) : chaque commune, quartier, ville et correction orthographique
 * reconnue est énumérée ci-dessous. La normalisation du texte brut
 * (Unicode NFKC, casse, accents, séparateurs) est déterministe, pas une
 * heuristique — elle ne fait que ramener "Abidjan-Cocody", "abidjan -
 * cocody" et "ABIDJAN COCODY" à la même clé de recherche.
 *
 * District d'Abidjan (verbatim validé) : les 10 communes nommées + les 3
 * communes satellites (Anyama, Bingerville, Songon). Toute valeur hors de
 * cette liste et absente de la liste "Intérieur"/"Étranger" devient
 * `A_CLASSER` — JAMAIS absorbée silencieusement dans une catégorie voisine.
 */
final class NormalisationVille
{
    public const ZONE_ABIDJAN = 'Abidjan';

    public const ZONE_INTERIEUR = 'Intérieur';

    public const ZONE_ETRANGER = 'Étranger';

    public const ZONE_NON_PRECISEE = 'Non précisée';

    public const ZONE_A_CLASSER = 'À classer';

    /** Clé normalisée (sans accent) => libellé canonique affiché. */
    private const COMMUNES_ABIDJAN = [
        'cocody' => 'Cocody',
        'yopougon' => 'Yopougon',
        'abobo' => 'Abobo',
        'koumassi' => 'Koumassi',
        'port bouet' => 'Port-Bouët',
        'adjame' => 'Adjamé',
        'attecoube' => 'Attécoubé',
        'marcory' => 'Marcory',
        'treichville' => 'Treichville',
        'plateau' => 'Plateau',
    ];

    /** Communes satellites du district — hors "10 communes" mais dans le district. */
    private const COMMUNES_SATELLITES = [
        'anyama' => 'Anyama',
        'bingerville' => 'Bingerville',
        'songon' => 'Songon',
    ];

    /**
     * Quartier (clé normalisée, recherchée en sous-chaîne) => commune
     * canonique de rattachement. Vérifié, pas deviné : seuls les
     * rapprochements explicitement communiqués sont ici.
     */
    private const QUARTIERS = [
        'deux plateaux' => 'Cocody',
        'deux plateau' => 'Cocody',
        'angre' => 'Cocody',
        'paillet' => 'Adjamé',
    ];

    /** Alias directs d'Abidjan (commune non précisée). */
    private const ALIAS_ABIDJAN = ['abidjan', 'abj'];

    /** Villes de l'Intérieur observées (liste blanche communiquée). */
    private const VILLES_INTERIEUR = [
        'san pedro' => 'San-Pedro',
        'bouake' => 'Bouaké',
        'daloa' => 'Daloa',
        'yamoussoukro' => 'Yamoussoukro',
        'beoumi' => 'Béoumi',
        'korhogo' => 'Korhogo',
        'dabou' => 'Dabou',
        'adzope' => 'Adzopé',
        'grand bassam' => 'Grand-Bassam',
        'bongouanou' => 'Bongouanou',
        'divo' => 'Divo',
        'noe' => 'Noé',
        'danane' => 'Danané',
        'tiebissou' => 'Tiébissou',
        'man' => 'Man',
        'bouna' => 'Bouna',
        'gagnoa' => 'Gagnoa',
        'grand bereby' => 'Grand-Béréby',
    ];

    /** Valeurs désignant explicitement l'étranger. */
    private const PAYS_ETRANGER = ['agadez', 'maroc'];

    /** "Côte d'Ivoire" seul (sans Abidjan ni ville reconnue) = pays, pas de ville. */
    private const COTE_IVOIRE_SEULE = 'cote d ivoire';

    /**
     * Corrections orthographiques — UNIQUEMENT les variantes qui ne sont PAS
     * de simples variantes d'accent (celles-ci sont déjà absorbées par le
     * retrait des accents ci-dessous, ex. bouake/bouaké, adzope/adzopé,
     * attecoube/attécoubé). Appliquées jeton par jeton.
     */
    private const CORRECTIONS_TYPO = [
        'yopugon' => 'yopougon',
        'anyaman' => 'anyama',
        'boueet' => 'bouet',
    ];

    /**
     * @return array{zone: string, commune_abidjan: ?string, ville_interieur: ?string}
     */
    public static function classer(string $brut): array
    {
        $haystack = self::cleNormalisee($brut);

        if ($haystack === '') {
            return self::resultat(self::ZONE_A_CLASSER);
        }

        foreach (self::QUARTIERS as $cle => $commune) {
            if (str_contains($haystack, $cle)) {
                return self::resultat(self::ZONE_ABIDJAN, $commune);
            }
        }

        foreach ([...self::COMMUNES_ABIDJAN, ...self::COMMUNES_SATELLITES] as $cle => $libelle) {
            if (self::contientJeton($haystack, $cle)) {
                return self::resultat(self::ZONE_ABIDJAN, $libelle);
            }
        }

        foreach (self::ALIAS_ABIDJAN as $alias) {
            if (self::contientJeton($haystack, $alias)) {
                return self::resultat(self::ZONE_ABIDJAN);
            }
        }

        foreach (self::VILLES_INTERIEUR as $cle => $libelle) {
            if (self::contientJeton($haystack, $cle)) {
                return self::resultat(self::ZONE_INTERIEUR, null, $libelle);
            }
        }

        foreach (self::PAYS_ETRANGER as $cle) {
            if (self::contientJeton($haystack, $cle)) {
                return self::resultat(self::ZONE_ETRANGER);
            }
        }

        if (str_contains($haystack, self::COTE_IVOIRE_SEULE)) {
            return self::resultat(self::ZONE_NON_PRECISEE);
        }

        return self::resultat(self::ZONE_A_CLASSER);
    }

    /**
     * @return array{zone: string, commune_abidjan: ?string, ville_interieur: ?string}
     */
    private static function resultat(string $zone, ?string $commune = null, ?string $ville = null): array
    {
        return ['zone' => $zone, 'commune_abidjan' => $commune, 'ville_interieur' => $ville];
    }

    /**
     * Un "jeton" multi-mots (ex. "san pedro") est recherché en sous-chaîne
     * (déjà espacé de façon homogène par cleNormalisee()) — pas de recherche
     * de mot isolé par regex, un simple str_contains suffit car les
     * séparateurs sont déjà uniformisés en un espace unique.
     */
    private static function contientJeton(string $haystack, string $jeton): bool
    {
        return str_contains(' '.$haystack.' ', ' '.$jeton.' ');
    }

    /**
     * NFKC (absorbe les variantes Unicode mathématiques, ex. "𝐴𝑏𝑖𝑑𝑗𝑎𝑛"),
     * minuscules, suppression des accents (NFD + retrait des marques
     * combinantes), puis uniformisation des séparateurs ( -, _, ',  ') en un
     * espace unique, et enfin correction des typos jeton par jeton.
     */
    private static function cleNormalisee(string $brut): string
    {
        $s = Normalizer::normalize(trim($brut), Normalizer::FORM_KC) ?: trim($brut);
        $s = mb_strtolower($s);
        $s = Normalizer::normalize($s, Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}/u', '', $s) ?? $s;
        $s = str_replace(["'", '’', '‐', '–', '—'], ' ', $s);
        $s = preg_replace('/[-_,]/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = trim($s);

        if ($s === '') {
            return '';
        }

        $jetons = array_map(
            fn (string $jeton) => self::CORRECTIONS_TYPO[$jeton] ?? $jeton,
            explode(' ', $s)
        );

        return implode(' ', $jetons);
    }
}
