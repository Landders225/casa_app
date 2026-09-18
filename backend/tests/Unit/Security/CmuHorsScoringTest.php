<?php

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * GARDE « LA CMU N'ENTRE DANS AUCUN CALCUL » (Lot 18) — pas une convention,
 * un test exécutable. Miroir backend de
 * `frontend/src/pages/{admin,evaluateur}/__tests__/noScoringFormula.test.js`
 * (même discipline : grep du CODE SOURCE, pas juste une conviction).
 *
 * `numero_cmu` (déclaratif, `candidat`) et le type de pièce `cmu`
 * (complétude du dossier, `ContraintesFichier::TYPES_DOSSIER`) sont une
 * condition de RECEVABILITÉ du dossier — jamais un facteur de score ou
 * d'éligibilité. Ce test grep les 4 fichiers qui calculent effectivement un
 * score ou une décision d'éligibilité et prouve qu'aucun des deux ne s'y
 * trouve.
 */
class CmuHorsScoringTest extends TestCase
{
    /** @var list<string> */
    private const FICHIERS_CALCUL = [
        'app/Domain/Scoring/ServiceScoring.php',
        'app/Domain/Eligibilite/ServiceEligibilite.php',
        'app/Domain/Eligibilite/ServiceEligibiliteInitiale.php',
        'app/Domain/Classement/ServiceClassement.php',
    ];

    public function test_les_fichiers_de_calcul_existent_bien(): void
    {
        // Le test ne doit pas passer « par défaut » si un chemin a bougé.
        foreach (self::FICHIERS_CALCUL as $rel) {
            $this->assertFileExists(base_path($rel), "Fichier attendu introuvable : {$rel}");
        }
    }

    /**
     * @dataProvider fichiersProvider
     */
    public function test_aucune_mention_de_la_cmu_dans_le_calcul(string $rel): void
    {
        $source = file_get_contents(base_path($rel));

        foreach (['numero_cmu', "'cmu'", '"cmu"'] as $motif) {
            $this->assertStringNotContainsStringIgnoringCase(
                $motif,
                $source,
                "{$rel} ne doit JAMAIS référencer la CMU (numéro ou pièce) dans un calcul de score/éligibilité.",
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fichiersProvider(): array
    {
        return array_combine(self::FICHIERS_CALCUL, array_map(fn ($f) => [$f], self::FICHIERS_CALCUL));
    }
}
