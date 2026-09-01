<?php

namespace Tests\Unit;

use App\Domain\Candidature\ChampsFormulaire;
use Database\Seeders\GrilleBaremeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Non-dérive : les énumérations de ChampsFormulaire (transcrites de scoring.js)
 * doivent correspondre exactement aux `option_item.valeur` du barème seedé
 * (Lot 1). Si l'un change sans l'autre, ce test casse.
 */
class ChampsFormulaireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GrilleBaremeSeeder::class);
    }

    public function test_enums_alignes_sur_les_options_du_bareme_seede(): void
    {
        foreach (ChampsFormulaire::ITEM_PAR_CHAMP as $champ => $codeItem) {
            $valeursBareme = DB::table('option_item')
                ->join('item', 'item.id', '=', 'option_item.item_id')
                ->where('item.code', $codeItem)
                ->pluck('option_item.valeur')
                ->sort()
                ->values()
                ->all();

            $valeursMap = collect(ChampsFormulaire::ENUMS[$champ])->sort()->values()->all();

            $this->assertSame(
                $valeursBareme,
                $valeursMap,
                "Le champ « {$champ} » ({$codeItem}) diverge du barème seedé.",
            );
        }
    }

    public function test_champs_interdits_couvrent_la_note_evaluateur(): void
    {
        $this->assertContains('mo04_note_etoiles', ChampsFormulaire::INTERDITS);
        $this->assertContains('cqp_confirme', ChampsFormulaire::INTERDITS);
    }
}
