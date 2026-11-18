<?php

namespace Tests\Unit\Affectation;

use App\Domain\Affectation\ResolutionCibles;
use App\Exceptions\AffectationAutomatiqueRefusee;
use PHPUnit\Framework\TestCase;

/**
 * Résolution des cibles par évaluateur (Lot 3, ADR-48) — fonction PURE, sans
 * base. Identifiants fictifs.
 */
class ResolutionCiblesTest extends TestCase
{
    public function test_sans_aucune_cible_le_reste_est_partage_a_parts_egales_moins_charges_dabord(): void
    {
        $resultat = ResolutionCibles::resoudre(['a' => 5, 'b' => 2, 'c' => 2], [], 9);

        // 9 à partager entre 3 évaluateurs sans cible : base 3 chacun, reste 0 → pas d'extra.
        $this->assertSame(['a' => 3, 'b' => 3, 'c' => 3], $resultat);
    }

    public function test_reste_non_divisible_va_aux_moins_charges_dabord(): void
    {
        $resultat = ResolutionCibles::resoudre(['a' => 5, 'b' => 2, 'c' => 2], [], 10);

        // base 3 chacun (9), reste 1 : va au moins chargé (b et c à égalité, b
        // en premier dans l'ordre reçu → b reçoit l'extra).
        $this->assertSame(['a' => 3, 'b' => 4, 'c' => 3], $resultat);
    }

    public function test_cibles_explicites_respectees_le_reste_va_aux_evaluateurs_sans_cible(): void
    {
        $resultat = ResolutionCibles::resoudre(
            ['a' => 5, 'b' => 2, 'c' => 2],
            ['a' => 3],
            10,
        );

        // a reçoit exactement sa cible (3). Reste 7 partagé entre b et c
        // (sans cible), égaux en charge → b (premier dans l'ordre) absorbe l'extra.
        $this->assertSame(['a' => 3, 'b' => 4, 'c' => 3], $resultat);
    }

    public function test_toutes_les_cibles_a_zero_laisse_tout_non_affecte_sans_erreur(): void
    {
        $resultat = ResolutionCibles::resoudre(
            ['a' => 5, 'b' => 2, 'c' => 2],
            ['a' => 0, 'b' => 0, 'c' => 0],
            10,
        );

        $this->assertSame(['a' => 0, 'b' => 0, 'c' => 0], $resultat);
        $this->assertSame(0, array_sum($resultat));
    }

    public function test_cibles_explicites_couvrant_tout_le_monde_sans_atteindre_le_total_laisse_un_reliquat(): void
    {
        $resultat = ResolutionCibles::resoudre(
            ['a' => 0, 'b' => 0],
            ['a' => 2, 'b' => 1],
            10,
        );

        // Tous les évaluateurs ont une cible explicite : le reliquat (7) reste
        // non affecté, ce n'est pas réparti ailleurs.
        $this->assertSame(['a' => 2, 'b' => 1], $resultat);
        $this->assertSame(3, array_sum($resultat));
    }

    public function test_somme_des_cibles_superieure_aux_affectables_est_refusee(): void
    {
        try {
            ResolutionCibles::resoudre(['a' => 0, 'b' => 0], ['a' => 6, 'b' => 6], 10);
            $this->fail('Une exception AffectationAutomatiqueRefusee était attendue.');
        } catch (AffectationAutomatiqueRefusee $e) {
            $this->assertSame('cibles_excessives', $e->codeApplicatif);
            $this->assertSame(422, $e->statut);
        }
    }

    public function test_cible_visant_un_evaluateur_hors_de_lensemble_resolu_est_refusee(): void
    {
        try {
            ResolutionCibles::resoudre(['a' => 0, 'b' => 0], ['inconnu' => 1], 10);
            $this->fail('Une exception AffectationAutomatiqueRefusee était attendue.');
        } catch (AffectationAutomatiqueRefusee $e) {
            $this->assertSame('cible_evaluateur_invalide', $e->codeApplicatif);
            $this->assertSame(422, $e->statut);
        }
    }

    public function test_resultat_couvre_tous_les_evaluateurs_dans_lordre_recu(): void
    {
        $resultat = ResolutionCibles::resoudre(['a' => 0, 'b' => 0, 'c' => 0], ['b' => 1], 4);

        $this->assertSame(['a', 'b', 'c'], array_keys($resultat));
    }

    public function test_deterministe_memes_entrees_meme_resultat(): void
    {
        $charges = ['x' => 3, 'y' => 1, 'z' => 1];
        $cibles = ['x' => 2];

        $this->assertSame(
            ResolutionCibles::resoudre($charges, $cibles, 9),
            ResolutionCibles::resoudre($charges, $cibles, 9),
        );
    }
}
