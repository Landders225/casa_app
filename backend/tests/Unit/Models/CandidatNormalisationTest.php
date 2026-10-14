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
}
