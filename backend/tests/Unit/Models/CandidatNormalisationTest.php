<?php

namespace Tests\Unit\Models;

use App\Models\Candidat;
use PHPUnit\Framework\TestCase;

/**
 * `Candidat::normaliserRecherche()` (lot recherche-admin, ADR-39) — SOURCE
 * UNIQUE utilisée à la fois pour précalculer `recherche_normalisee` (hook
 * `Candidat::booted()`) et pour normaliser le terme tapé côté contrôleur
 * (`CandidatureSupervisionController::appliquerRecherche()`). Test PUR (pas
 * de DB) : la fonction est statique et déterministe. Jetons fictifs
 * uniquement.
 */
class CandidatNormalisationTest extends TestCase
{
    public function test_accents_courants_sont_retires(): void
    {
        $this->assertSame('etemoin', Candidat::normaliserRecherche('Étemoin', ''));
        $this->assertSame('etemoin', Candidat::normaliserRecherche('Ètemoin', ''));
        $this->assertSame('cecile', Candidat::normaliserRecherche('Çécile', ''));
        $this->assertSame('coeur', Candidat::normaliserRecherche('Cœur', ''));
        $this->assertSame('ihou', Candidat::normaliserRecherche('Ïhou', ''));
    }

    public function test_apostrophe_droite_et_typographique_sont_retirees(): void
    {
        $sansApostropheDroite = Candidat::normaliserRecherche("N'Testeur", '');
        $sansApostropheTypo = Candidat::normaliserRecherche('N’Testeur', '');

        $this->assertSame('ntesteur', $sansApostropheDroite);
        $this->assertSame('ntesteur', $sansApostropheTypo);
        $this->assertSame($sansApostropheDroite, $sansApostropheTypo);
    }

    public function test_tiret_est_retire(): void
    {
        $this->assertSame('traorediomande', Candidat::normaliserRecherche('', 'Traore-Diomande'));
    }

    public function test_insensible_a_la_casse(): void
    {
        $this->assertSame(
            Candidat::normaliserRecherche('KOUAME', 'TESTEUR'),
            Candidat::normaliserRecherche('kouame', 'testeur'),
        );
    }

    public function test_espaces_multiples_et_bords_sont_neutralises(): void
    {
        // `trim()` sur prenom+' '+nom : les espaces internes à un mot composé
        // ne sont pas écrasés (hors périmètre — la recherche « contient »
        // sur le résultat gère déjà ce cas), mais les bords le sont.
        $this->assertSame('jean dupont', Candidat::normaliserRecherche('  Jean  ', '  Dupont  '));
    }

    public function test_prenom_et_nom_null_ne_plantent_pas(): void
    {
        $this->assertSame('', Candidat::normaliserRecherche(null, null));
        $this->assertSame('jean', Candidat::normaliserRecherche('Jean', null));
    }

    /**
     * Stockage (hook, `prenom`+`nom`) et terme tapé (recherche, un seul mot)
     * passent par EXACTEMENT la même fonction : le terme normalisé doit se
     * retrouver tel quel, en sous-chaîne, dans la colonne normalisée — c'est
     * précisément ce sur quoi s'appuie le `ILIKE '%...%'` du contrôleur.
     */
    public function test_terme_de_recherche_normalise_est_une_sous_chaine_de_la_colonne_normalisee(): void
    {
        $colonne = Candidat::normaliserRecherche('Åsa', 'N’Guessan-Yéo');
        $termeTape = Candidat::normaliserRecherche('NGUESSAN', '');

        $this->assertSame('asa nguessanyeo', $colonne);
        $this->assertSame('nguessan', $termeTape);
        $this->assertStringContainsString($termeTape, $colonne);
    }

    // --- Correctif prod : variantes de présentation Unicode (NFKC) ---------
    // Constat réel (jamais de donnée réelle ici, uniquement des jetons
    // fictifs construits caractère par caractère) : un candidat en
    // production avait prénom+nom ENTIÈREMENT en lettres « mathématiques »
    // (blocs U+1D400–U+1D7FF + U+210E), 0 lettre ASCII — `Str::ascii()` seul
    // les supprime silencieusement (`Str::ascii` ne connaît que le bloc
    // Latin), d'où `recherche_normalisee = ''`. NFKC les ramène à leur forme
    // canonique AVANT `Str::ascii()`.

    public function test_lettres_mathematiques_italiques_sont_normalisees(): void
    {
        // "𝑇𝑒𝑠𝑡" — italique mathématique (U+1D447,U+1D452,U+1D460,U+1D461).
        $this->assertSame('test', Candidat::normaliserRecherche('𝑇𝑒𝑠𝑡', ''));
    }

    public function test_lettres_mathematiques_grasses_sont_normalisees(): void
    {
        // "𝐀𝐰𝐚" — gras mathématique (U+1D400,U+1D430,U+1D41A).
        $this->assertSame('awa', Candidat::normaliserRecherche('𝐀𝐰𝐚', ''));
    }

    public function test_lettres_mathematiques_script_sont_normalisees(): void
    {
        // "𝒶𝒷𝒸" — script mathématique (U+1D4B6,U+1D4B7,U+1D4B8).
        $this->assertSame('abc', Candidat::normaliserRecherche('𝒶𝒷𝒸', ''));
    }

    public function test_lettres_pleine_largeur_sont_normalisees(): void
    {
        // "ＮＯＭ" — pleine largeur (U+FF2E,U+FF2F,U+FF2D).
        $this->assertSame('nom', Candidat::normaliserRecherche('ＮＯＭ', ''));
    }

    public function test_ligature_et_u210e_sont_normalisees(): void
    {
        // "ﬁℎ" — ligature LATIN SMALL LIGATURE FI (U+FB01) + PLANCK CONSTANT
        // (U+210E, qui EST une lettre « h » italique dans Unicode).
        $this->assertSame('fih', Candidat::normaliserRecherche('ﬁℎ', ''));
    }

    public function test_terme_ascii_simple_equivaut_au_meme_terme_stylise(): void
    {
        $this->assertSame(
            Candidat::normaliserRecherche('Test', ''),
            Candidat::normaliserRecherche('𝑇𝑒𝑠𝑡', ''),
        );
        $this->assertSame(
            Candidat::normaliserRecherche('Awa Nom', ''),
            Candidat::normaliserRecherche('𝐀𝐰𝐚 ＮＯＭ', ''),
        );
    }

    /**
     * Tous les cas déjà couverts avant le correctif Unicode restent inchangés
     * — NFKC s'ajoute, ne remplace rien (accents, apostrophes, tirets, œ, ç
     * ne sont pas des variantes de présentation NFKC, le résultat est donc
     * identique à avant sur ces cas).
     */
    public function test_nfkc_ne_change_rien_aux_cas_deja_couverts(): void
    {
        $this->assertSame('etemoin', Candidat::normaliserRecherche('Étemoin', ''));
        $this->assertSame('cecile', Candidat::normaliserRecherche('Çécile', ''));
        $this->assertSame('coeur', Candidat::normaliserRecherche('Cœur', ''));
        $this->assertSame('ntesteur', Candidat::normaliserRecherche("N'Testeur", ''));
        $this->assertSame('ntesteur', Candidat::normaliserRecherche('N’Testeur', ''));
        $this->assertSame('traorediomande', Candidat::normaliserRecherche('', 'Traore-Diomande'));
    }

    /**
     * Garde-fou : un nom qui ne contient AUCUNE lettre translittérable même
     * après NFKC (ex. idéogrammes — NFKC ne traduit pas le chinois en latin)
     * reste légitimement vide, sans planter. Jetons de test, pas un vrai nom.
     */
    public function test_caracteres_non_translitterables_restent_vides_sans_erreur(): void
    {
        $this->assertSame('', Candidat::normaliserRecherche('測試', '案例'));
    }
}
