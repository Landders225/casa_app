<?php

namespace Tests\Unit\Affectation;

use App\Domain\Affectation\RepartitionEquilibree;
use PHPUnit\Framework\TestCase;

/**
 * Répartition « toujours le moins chargé » (ADR-45) — fonction PURE, sans base.
 * Identifiants fictifs.
 */
class RepartitionEquilibreeTest extends TestCase
{
    /**
     * @param  array<string, string>  $attribution
     * @return array<string, int>
     */
    private static function recus(array $attribution): array
    {
        return array_count_values($attribution);
    }

    public function test_repartition_egale_a_une_unite_pres(): void
    {
        $attribution = RepartitionEquilibree::repartir(['a' => 0, 'b' => 0, 'c' => 0], array_map(fn ($i) => "d{$i}", range(1, 10)));

        $this->assertSame(['a' => 4, 'b' => 3, 'c' => 3], self::recus($attribution));
    }

    public function test_tourniquet_dans_l_ordre_recu_egalite_au_premier(): void
    {
        $this->assertSame(
            ['d1' => 'a', 'd2' => 'b', 'd3' => 'a', 'd4' => 'b'],
            RepartitionEquilibree::repartir(['a' => 0, 'b' => 0], ['d1', 'd2', 'd3', 'd4']),
        );
    }

    public function test_les_ecarts_de_charge_existants_sont_combles_d_abord(): void
    {
        $attribution = RepartitionEquilibree::repartir(['a' => 5, 'b' => 2, 'c' => 0], array_map(fn ($i) => "d{$i}", range(1, 6)));

        // c rattrape b (0 → 2), puis b et c alternent : charges finales a 5, b 4, c 4.
        $this->assertSame(['c' => 4, 'b' => 2], self::recus($attribution));
        $this->assertArrayNotHasKey('a', self::recus($attribution));
    }

    public function test_deterministe_memes_entrees_meme_resultat(): void
    {
        $charges = ['x' => 1, 'y' => 0, 'z' => 1];
        $dossiers = array_map(fn ($i) => "d{$i}", range(1, 17));

        $this->assertSame(
            RepartitionEquilibree::repartir($charges, $dossiers),
            RepartitionEquilibree::repartir($charges, $dossiers),
        );
    }

    public function test_aucun_evaluateur_ou_aucun_dossier(): void
    {
        $this->assertSame([], RepartitionEquilibree::repartir([], ['d1']));
        $this->assertSame([], RepartitionEquilibree::repartir(['a' => 0], []));
    }
}
