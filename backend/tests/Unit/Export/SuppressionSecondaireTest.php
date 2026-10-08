<?php

namespace Tests\Unit\Export;

use App\Domain\Export\SuppressionSecondaire;
use Tests\TestCase;

class SuppressionSecondaireTest extends TestCase
{
    public function test_aucun_masquage_si_toutes_les_categories_sont_au_dessus_du_seuil(): void
    {
        $resultat = SuppressionSecondaire::appliquer(['A' => 10, 'B' => 7], 5);

        $this->assertSame(['A' => 10, 'B' => 7], $resultat['effectifs']);
        $this->assertSame(17, $resultat['total']);
    }

    public function test_categorie_sous_le_seuil_est_masquee(): void
    {
        $resultat = SuppressionSecondaire::appliquer(['A' => 10, 'B' => 2], 5);

        $this->assertSame('<5', $resultat['effectifs']['B']);
    }

    /**
     * Une seule catégorie masquée => une seconde (la plus petite des
     * restantes) doit l'être aussi, sinon Total − catégories visibles
     * retrouve exactement la valeur masquée.
     */
    public function test_une_seule_categorie_masquee_entraine_la_suppression_secondaire(): void
    {
        $resultat = SuppressionSecondaire::appliquer(['A' => 20, 'B' => 6, 'C' => 2], 5);

        $this->assertSame('<5', $resultat['effectifs']['C'], 'C est sous le seuil.');
        $this->assertSame('<5', $resultat['effectifs']['B'], 'B (la plus petite des restantes) doit être masquée en second.');
        $this->assertSame(20, $resultat['effectifs']['A']);
        $this->assertNull($resultat['total'], 'Le total ne doit jamais être affiché à côté d\'une cellule masquée.');
    }

    public function test_deux_categories_deja_masquees_ne_declenchent_pas_de_suppression_supplementaire(): void
    {
        $resultat = SuppressionSecondaire::appliquer(['A' => 20, 'B' => 1, 'C' => 2], 5);

        $this->assertSame(20, $resultat['effectifs']['A'], 'A reste visible : >= 2 inconnues, aucune reconstruction possible.');
        $this->assertSame('<5', $resultat['effectifs']['B']);
        $this->assertSame('<5', $resultat['effectifs']['C']);
        $this->assertNull($resultat['total']);
    }

    public function test_total_absent_des_qu_une_cellule_est_masquee(): void
    {
        $resultat = SuppressionSecondaire::appliquer(['A' => 10, 'B' => 1], 5);

        $this->assertNull($resultat['total']);
    }

    public function test_aucune_reconstruction_possible_du_total_affiche(): void
    {
        $resultat = SuppressionSecondaire::appliquer(['A' => 20, 'B' => 6, 'C' => 2], 5);

        // Le total n'étant jamais affiché dès qu'une cellule est masquée, il
        // n'existe tout simplement rien à partir de quoi soustraire.
        $this->assertNull($resultat['total']);
    }
}
