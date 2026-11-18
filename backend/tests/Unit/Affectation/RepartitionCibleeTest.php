<?php

namespace Tests\Unit\Affectation;

use App\Domain\Affectation\RepartitionCiblee;
use PHPUnit\Framework\TestCase;

/**
 * Répartition selon des cibles EXACTES par évaluateur (Lot 3, ADR-48) —
 * fonction PURE, sans base. Identifiants fictifs.
 */
class RepartitionCibleeTest extends TestCase
{
    public function test_chaque_evaluateur_recoit_exactement_sa_cible(): void
    {
        $attribution = RepartitionCiblee::repartir(
            ['a' => 2, 'b' => 1],
            ['d1', 'd2', 'd3'],
        );

        $this->assertSame(['d1' => 'a', 'd2' => 'a', 'd3' => 'b'], $attribution);
    }

    public function test_cible_a_zero_ne_recoit_rien(): void
    {
        $attribution = RepartitionCiblee::repartir(
            ['a' => 0, 'b' => 2],
            ['d1', 'd2'],
        );

        $this->assertSame(['d1' => 'b', 'd2' => 'b'], $attribution);
        $this->assertArrayNotHasKey('a', array_flip($attribution));
    }

    public function test_somme_des_cibles_inferieure_au_nombre_de_dossiers_laisse_le_reste_non_affecte(): void
    {
        $attribution = RepartitionCiblee::repartir(
            ['a' => 1, 'b' => 1],
            ['d1', 'd2', 'd3', 'd4'],
        );

        $this->assertSame(['d1' => 'a', 'd2' => 'b'], $attribution);
        $this->assertCount(2, $attribution);
    }

    public function test_aucune_cible_ou_aucun_dossier(): void
    {
        $this->assertSame([], RepartitionCiblee::repartir([], ['d1']));
        $this->assertSame([], RepartitionCiblee::repartir(['a' => 3], []));
    }

    public function test_deterministe_memes_entrees_meme_resultat(): void
    {
        $cibles = ['a' => 2, 'b' => 3, 'c' => 0];
        $dossiers = array_map(fn ($i) => "d{$i}", range(1, 5));

        $this->assertSame(
            RepartitionCiblee::repartir($cibles, $dossiers),
            RepartitionCiblee::repartir($cibles, $dossiers),
        );
    }
}
