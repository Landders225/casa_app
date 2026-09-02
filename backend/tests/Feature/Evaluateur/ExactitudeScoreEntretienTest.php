<?php

namespace Tests\Feature\Evaluateur;

use App\Domain\Scoring\ScoreEntretien;
use App\Domain\Scoring\ServiceScoring;
use App\Models\Candidature;
use App\Models\Grille;
use App\Models\SousCritereEntretien;
use Database\Seeders\GrilleBaremeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * EXACTITUDE DU SCORE ENTRETIEN (/35) — même exigence qu'au Lot 4b.
 *
 * Score attendu calculé À LA MAIN depuis App_maquette/assets/js/scoring.js
 * (`computeEntretienScore`) vs `ServiceScoring::calculerEntretien` (barème lu en
 * base) — total ET détail par rubrique.
 *
 *   A  entretien fort   → 33,0
 *   B  entretien faible  → 9,0
 *   C  candidat absent   → 0,0
 *   E  demi-points       → 34,5
 *   + une sous-note stockée au-delà de son max est BORNÉE au calcul (scoring.js Math.min)
 */
class ExactitudeScoreEntretienTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private ServiceScoring $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seed(GrilleBaremeSeeder::class);
        $this->scoring = app(ServiceScoring::class);
    }

    /**
     * @param  array<string, float|int>  $notes  code sous-critère => points (stockés bruts, sans borne)
     */
    private function candidatureAvecEntretien(array $notes, string $presence = 'present'): Candidature
    {
        $evaluateur = $this->creerEvaluateur();
        $candidature = $this->verrouillerDossier(
            $this->candidatureAffectee($this->creerCandidat(), $evaluateur),
        );

        $candidature->entretien()->create([
            'statut' => 'realise',
            'date' => '2026-07-06',
            'heure' => '09:00',
            'lieu' => 'Le Plateau',
            'evaluateur_id' => $evaluateur->membreEquipe->id,
            'presence' => $presence,
        ]);

        $parCode = SousCritereEntretien::all()->keyBy('code');
        foreach ($notes as $code => $points) {
            DB::table('note_sous_critere_entretien')->insert([
                'entretien_id' => $candidature->id,
                'sous_critere_id' => $parCode[$code]->id,
                'points_attribues' => $points,
            ]);
        }

        return $candidature->fresh()->load('entretien.notes');
    }

    private function calculer(Candidature $candidature): ScoreEntretien
    {
        return $this->scoring->calculerEntretien($candidature, Grille::active());
    }

    /**
     * @param  array<string, string>  $rubriques  code => score attendu (1 décimale)
     */
    private function assertScore(ScoreEntretien $score, string $total, array $rubriques): void
    {
        $this->assertSame($total, number_format($score->total, 1, '.', ''), 'score entretien /35');
        foreach ($rubriques as $code => $attendu) {
            $this->assertSame(
                $attendu,
                number_format($score->rubrique($code)->score, 1, '.', ''),
                "rubrique {$code}",
            );
        }
    }

    public function test_cas_A_entretien_fort(): void
    {
        $score = $this->calculer($this->candidatureAvecEntretien([
            'PRES.01' => 3, 'PRES.02' => 2, 'PRES.03' => 3,   // 8
            'REL.01' => 3, 'REL.02' => 2, 'REL.03' => 4,       // 9
            'EO.01' => 3, 'EO.02' => 3, 'EO.03' => 2,          // 8
            'MOE.01' => 3, 'MOE.02' => 3, 'MOE.03' => 2,       // 8
        ]));

        $this->assertScore($score, '33.0', [
            'presentation' => '8.0',
            'relationnel' => '9.0',
            'expressionOrale' => '8.0',
            'motivationEntretien' => '8.0',
        ]);
    }

    public function test_cas_B_entretien_faible(): void
    {
        $score = $this->calculer($this->candidatureAvecEntretien([
            'PRES.01' => 1, 'PRES.02' => 0, 'PRES.03' => 1,   // 2
            'REL.01' => 1, 'REL.02' => 1, 'REL.03' => 1,       // 3
            'EO.01' => 1, 'EO.02' => 0, 'EO.03' => 1,          // 2
            'MOE.01' => 1, 'MOE.02' => 1, 'MOE.03' => 0,       // 2
        ]));

        $this->assertScore($score, '9.0', [
            'presentation' => '2.0',
            'relationnel' => '3.0',
            'expressionOrale' => '2.0',
            'motivationEntretien' => '2.0',
        ]);
    }

    public function test_cas_C_candidat_absent(): void
    {
        // Aucune sous-note, presence = absent.
        $score = $this->calculer($this->candidatureAvecEntretien([], presence: 'absent'));

        $this->assertScore($score, '0.0', [
            'presentation' => '0.0',
            'relationnel' => '0.0',
            'expressionOrale' => '0.0',
            'motivationEntretien' => '0.0',
        ]);
    }

    public function test_cas_E_demi_points(): void
    {
        $score = $this->calculer($this->candidatureAvecEntretien([
            'PRES.01' => 2.5, 'PRES.02' => 2, 'PRES.03' => 3,  // 7.5
            'REL.01' => 3, 'REL.02' => 3, 'REL.03' => 4,        // 10
            'EO.01' => 3, 'EO.02' => 3, 'EO.03' => 2,           // 8
            'MOE.01' => 3, 'MOE.02' => 3, 'MOE.03' => 3,        // 9
        ]));

        $this->assertScore($score, '34.5', [
            'presentation' => '7.5',
            'relationnel' => '10.0',
            'expressionOrale' => '8.0',
            'motivationEntretien' => '9.0',
        ]);
    }

    public function test_une_sous_note_stockee_au_dela_du_max_est_bornee_au_calcul(): void
    {
        // REL.03 stocké à 5 alors que son max est 4 (l'API le refuserait, cf.
        // NotationEntretienTest ; ici on prouve la borne défensive du service).
        $score = $this->calculer($this->candidatureAvecEntretien([
            'REL.01' => 3, 'REL.02' => 3, 'REL.03' => 5,
        ]));

        $this->assertSame('10.0', number_format($score->rubrique('relationnel')->score, 1, '.', ''));
        $this->assertSame('4.0', number_format(
            collect($score->sousNotes)->firstWhere('code', 'REL.03')->points,
            1, '.', '',
        ));
    }

    public function test_le_bareme_entretien_vient_de_la_base(): void
    {
        $candidature = $this->candidatureAvecEntretien(['REL.03' => 4]);
        $this->assertSame('4.0', number_format($this->calculer($candidature)->rubrique('relationnel')->score, 1, '.', ''));

        // On double le max de REL.03 en base : la borne suit (4 n'est plus plafonné).
        DB::table('sous_critere_entretien')->where('code', 'REL.03')->update(['max_points' => 8]);

        $candidature = $candidature->fresh()->load('entretien.notes');
        $this->assertSame('4.0', number_format($this->calculer($candidature)->rubrique('relationnel')->score, 1, '.', ''));

        // ... et si on remonte la note à 8, elle passe (max relevé en base).
        DB::table('note_sous_critere_entretien')
            ->where('entretien_id', $candidature->id)
            ->update(['points_attribues' => 8]);
        $candidature = $candidature->fresh()->load('entretien.notes');
        $this->assertSame('8.0', number_format($this->calculer($candidature)->rubrique('relationnel')->score, 1, '.', ''));
    }
}
