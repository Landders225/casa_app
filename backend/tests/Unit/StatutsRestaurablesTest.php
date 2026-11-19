<?php

namespace Tests\Unit;

use App\Domain\Eligibilite\StatutsRestaurables;
use Tests\TestCase;

/**
 * Lot 4 (ADR-49) — liste blanche des `statut_interne` restaurables. Pure
 * fonction, aucune base : le CHECK Postgres de `candidature` interdit
 * physiquement toute valeur hors `brouillon|soumis|en_instruction|
 * non_eligible|evalue`, donc impossible de prouver le refus d'une 6ᵉ valeur
 * par une vraie ligne en base — ce test prouve le refus PAR DÉFAUT au niveau
 * de la fonction elle-même, défensif contre un enum futur ajouté sans mise à
 * jour de `StatutsRestaurables::AUTORISES`.
 */
class StatutsRestaurablesTest extends TestCase
{
    public function test_les_4_statuts_atteignables_non_eligibles_sont_autorises(): void
    {
        foreach (['soumis', 'en_instruction', 'non_eligible', 'evalue'] as $statut) {
            $this->assertTrue(StatutsRestaurables::autorise($statut), "« {$statut} » devrait être restaurable.");
        }
    }

    public function test_brouillon_est_refuse(): void
    {
        $this->assertFalse(StatutsRestaurables::autorise('brouillon'));
    }

    public function test_un_statut_futur_inconnu_est_refuse_par_defaut(): void
    {
        foreach (['archive', 'en_appel', ''] as $statutHypothetique) {
            $this->assertFalse(
                StatutsRestaurables::autorise($statutHypothetique),
                'Un statut non listé explicitement doit être refusé par défaut (liste blanche).',
            );
        }
    }
}
