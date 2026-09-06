<?php

namespace Tests\Feature\Evaluateur;

use App\Domain\Scoring\ScoreDossier;
use App\Domain\Scoring\ServiceScoring;
use App\Models\Candidature;
use App\Models\Grille;
use Database\Seeders\GrilleBaremeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * EXACTITUDE DU SCORE SERVEUR (/65) — le cœur du Lot 4b.
 *
 * Pour chaque cas, le score attendu est calculé À LA MAIN depuis
 * App_maquette/assets/js/scoring.js (`computeScores`) et comparé à la valeur
 * rendue par `ServiceScoring`, qui lit le barème EN BASE (grille active) —
 * total ET détail par rubrique.
 *
 *   A  profil fort        → 64,2
 *   B  profil faible      → 18,1
 *   C  limite langues     → 9,7   (langues 6,6667 : preuve du rééchelonnage /9→/10)
 *   D  MO.04 = 0 / = 5    → 33,0 / 48,0   (motivation 0,0000 / 15,0000)
 *   E  SC.04 via vérif 4a → 45,0 (non vérifié) / 46,5 (bepc)
 */
class ExactitudeScoreDossierTest extends TestCase
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
     * Construit une candidature scorable avec des réponses maîtrisées.
     *
     * @param  array<string, mixed>  $reponses  colonnes de `reponse_formulaire` (+ `mo04_note_etoiles`)
     * @param  list<array{0:string,1:string}>  $experiences  [domaine, duree_categorie]
     */
    private function candidature(array $reponses, ?string $diplome = null, array $experiences = []): Candidature
    {
        $candidat = $this->creerCandidat();
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $candidature = Candidature::findOrFail($id);

        $note = $reponses['mo04_note_etoiles'] ?? null;
        unset($reponses['mo04_note_etoiles']);

        $candidature->reponseFormulaire->forceFill($reponses)->save();
        if ($note !== null) {
            $candidature->reponseFormulaire->mo04_note_etoiles = $note;
            $candidature->reponseFormulaire->save();
        }

        if ($diplome !== null) {
            $candidature->verification()->create([
                'diplome_verifie' => $diplome,
                'nationalite_confirmee' => true,
                'verifie_le' => now(),
            ]);
        }

        foreach ($experiences as [$domaine, $duree]) {
            $candidature->experiences()->create(['domaine' => $domaine, 'duree_categorie' => $duree]);
        }

        return $candidature->fresh()->load(['reponseFormulaire', 'verification', 'experiences']);
    }

    private function calculer(Candidature $candidature): ScoreDossier
    {
        return $this->scoring->calculer($candidature, Grille::active());
    }

    /**
     * @param  array<string, string>  $rubriquesAttendues  code => score attendu (4 décimales)
     */
    private function assertScore(ScoreDossier $score, string $totalAttendu, array $rubriquesAttendues): void
    {
        $this->assertSame($totalAttendu, number_format($score->total, 1, '.', ''), 'score total /65');
        foreach ($rubriquesAttendues as $code => $attendu) {
            $this->assertSame(
                $attendu,
                number_format($score->rubrique($code)->score, 4, '.', ''),
                "rubrique {$code}",
            );
        }
    }

    public function test_cas_A_profil_fort(): void
    {
        $score = $this->calculer($this->candidature(
            [
                'sc01_scolarise_actuellement' => 'non',      // 1,5
                'sc02_derniere_classe' => 'terminale',       // 4,5
                'sc03_document_justifiant_niveau' => 'oui',   // 1,5
                'sc05_beneficiaire_formation_actuelle' => 'non', // 1,5
                'se02_orphelin' => 'oui',                    // 3,25
                'se03_situation_emploi' => 'sans_emploi',    // 3,25
                'se04_source_revenu' => 'parent',            // 3,25
                'se06_soutien_menage' => 'oui',              // 3,25
                'langue_ecrit' => 3, 'langue_parle' => 3, 'langue_comprehension' => 3,
                'info_word' => 3, 'info_excel' => 3, 'info_internet' => 3, // fr 6 + info 3 = raw9 9 -> 10
                'di02_contraintes' => 'aucune',              // 10
                'mo04_note_etoiles' => 5,                    // 5/5*15 = 15
            ],
            diplome: 'bac',                                  // SC.04 -> 3
            experiences: [['hotellerie', 'plus_12'], ['restauration', '6_12']], // 2 domaines + 24 mois
        ));

        // scolaire 12 ; socioEco 13 ; experience 2/3*2,5 + 1*2,5 = 4,1667 ;
        // langues 10 ; motivation 15 ; dispo 10  -> Σ 64,1667 -> 64,2
        $this->assertScore($score, '64.2', [
            'scolaire' => '12.0000',
            'socioEco' => '13.0000',
            'experience' => '4.1667',
            'langues' => '10.0000',
            'motivation' => '15.0000',
            'disponibilite' => '10.0000',
        ]);
    }

    public function test_cas_B_profil_faible(): void
    {
        $score = $this->calculer($this->candidature(
            [
                'sc01_scolarise_actuellement' => 'non',      // 1,5
                'sc02_derniere_classe' => '3e',              // 1,5
                'sc03_document_justifiant_niveau' => 'non',  // 0
                'sc05_beneficiaire_formation_actuelle' => 'non', // 1,5
                'se02_orphelin' => 'non',
                'se03_situation_emploi' => 'stage',
                'se04_source_revenu' => 'agr',
                'se06_soutien_menage' => 'non',
                'langue_ecrit' => 2, 'langue_parle' => 2, 'langue_comprehension' => 2, // fr moy 2 -> 4
                'info_word' => 1, 'info_excel' => 1, 'info_internet' => 1,             // info moy 1 -> 1
                'di02_contraintes' => 'gerable',            // 5
                'mo04_note_etoiles' => 1,                    // 3
            ],
            diplome: 'cap',                                  // 0
        ));

        // scolaire 4,5 ; socioEco 0 ; experience 0 ; langues 5/9*10 = 5,5556 ;
        // motivation 3 ; dispo 5  -> Σ 18,0556 -> 18,1
        $this->assertScore($score, '18.1', [
            'scolaire' => '4.5000',
            'socioEco' => '0.0000',
            'experience' => '0.0000',
            'langues' => '5.5556',
            'motivation' => '3.0000',
            'disponibilite' => '5.0000',
        ]);
    }

    public function test_cas_C_limite_langues_prouve_le_reechelonnage_9_sur_10(): void
    {
        $score = $this->calculer($this->candidature([
            'sc01_scolarise_actuellement' => 'non',          // 1,5
            'sc02_derniere_classe' => 'cap',                 // 0
            'sc03_document_justifiant_niveau' => 'non',      // 0
            'sc05_beneficiaire_formation_actuelle' => 'non', // 1,5
            'se02_orphelin' => 'non',
            'se03_situation_emploi' => 'stage',
            'se04_source_revenu' => 'agr',
            'se06_soutien_menage' => 'non',
            'langue_ecrit' => 3, 'langue_parle' => 3, 'langue_comprehension' => 3, // frScore 6
            'info_word' => 0, 'info_excel' => 0, 'info_internet' => 0,             // infoScore 0
            'di02_contraintes' => 'bloquante',              // 0
            'mo04_note_etoiles' => 0,                        // 0
        ]));
        // pas de vérification -> SC.04 = 0

        // raw9 = 6 mais score langues = 6/9*10 = 6,6667 (≠ 6)
        $this->assertScore($score, '9.7', [
            'scolaire' => '3.0000',
            'socioEco' => '0.0000',
            'experience' => '0.0000',
            'langues' => '6.6667',
            'motivation' => '0.0000',
            'disponibilite' => '0.0000',
        ]);
    }

    public function test_cas_D_motivation_MO04_a_zero_puis_cinq_etoiles(): void
    {
        $base = [
            'sc01_scolarise_actuellement' => 'non',
            'sc02_derniere_classe' => 'terminale',
            'sc03_document_justifiant_niveau' => 'oui',
            'sc05_beneficiaire_formation_actuelle' => 'non',
            'se02_orphelin' => 'non',
            'se03_situation_emploi' => 'sans_emploi',        // 3,25
            'se04_source_revenu' => 'aucune',
            'se06_soutien_menage' => 'non',
            'langue_ecrit' => 3, 'langue_parle' => 2, 'langue_comprehension' => 3,
            'info_word' => 2, 'info_excel' => 1, 'info_internet' => 2,             // raw9 = 7 -> 7,7778
            'di02_contraintes' => 'aucune',
        ];

        // scolaire 12 ; socioEco 3,25 ; experience 0 ; langues 7,7778 ; dispo 10
        $zero = $this->calculer($this->candidature($base + ['mo04_note_etoiles' => 0], diplome: 'bac'));
        $this->assertScore($zero, '33.0', ['motivation' => '0.0000']);

        $cinq = $this->calculer($this->candidature($base + ['mo04_note_etoiles' => 5], diplome: 'bac'));
        $this->assertScore($cinq, '48.0', ['motivation' => '15.0000']);
    }

    public function test_cas_E_SC04_provient_de_la_verification_evaluateur(): void
    {
        $base = [
            'sc01_scolarise_actuellement' => 'non',          // 1,5
            'sc02_derniere_classe' => 'terminale',           // 4,5
            'sc03_document_justifiant_niveau' => 'oui',       // 1,5
            'sc05_beneficiaire_formation_actuelle' => 'non', // 1,5
            'se02_orphelin' => 'non',
            'se03_situation_emploi' => 'sans_emploi',         // 3,25
            'se04_source_revenu' => 'aucune',
            'se06_soutien_menage' => 'non',
            'langue_ecrit' => 3, 'langue_parle' => 2, 'langue_comprehension' => 3,
            'info_word' => 2, 'info_excel' => 1, 'info_internet' => 2,             // raw9 7 -> 7,7778
            'di02_contraintes' => 'aucune',
            'mo04_note_etoiles' => 5,
        ];

        // Sans vérification : SC.04 -> 0, scolaire = 9,0
        $sansVerif = $this->calculer($this->candidature($base, diplome: null));
        $this->assertScore($sansVerif, '45.0', ['scolaire' => '9.0000']);

        // Diplôme bepc confirmé : SC.04 -> 1,5, scolaire = 10,5
        $bepc = $this->calculer($this->candidature($base, diplome: 'bepc'));
        $this->assertScore($bepc, '46.5', ['scolaire' => '10.5000']);
    }

    public function test_le_bareme_vient_de_la_base_pas_du_code(): void
    {
        // On modifie le poids de la rubrique langues en base : le score doit suivre.
        $candidature = $this->candidature([
            'langue_ecrit' => 3, 'langue_parle' => 3, 'langue_comprehension' => 3,
            'info_word' => 3, 'info_excel' => 3, 'info_internet' => 3, // raw9 = 9
            'mo04_note_etoiles' => 0,
        ]);
        $this->assertSame('10.0000', number_format($this->calculer($candidature)->rubrique('langues')->score, 4, '.', ''));

        DB::table('rubrique')
            ->where('code', 'langues')
            ->update(['max_points' => 20]); // poids doublé, hors norme, pour la démonstration

        $candidature->refresh()->load('reponseFormulaire');
        $this->assertSame('20.0000', number_format($this->calculer($candidature)->rubrique('langues')->score, 4, '.', ''));
    }
}
