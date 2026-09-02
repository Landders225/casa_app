<?php

namespace Tests\Feature\Admin;

use App\Domain\Classement\ServiceClassement;
use App\Models\Campagne;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * EXACTITUDE DU CLASSEMENT (/100) — chaque critère de départage forcé isolément.
 *
 * Classement attendu calculé À LA MAIN depuis App_maquette/assets/js/scoring.js
 * (`computeScoreFinal` + `rankCandidatsParFiliere`) et `classement.html`
 * (`computeRanking`) vs `ServiceClassement`.
 *
 *   A  égalité de score → départage MIXITÉ (F avant H)
 *   B  égalité de score + mixité → départage VULNÉRABILITÉ (NEET)
 *   C  + vulnérabilité → départage EXPÉRIENCE SECTEUR (hôtellerie-restauration)
 *   D  + expérience → départage MOTIVATION (mo04)
 *   E  égalité totale → départage FINAL DÉTERMINISTE (date_soumission) — D-5a-1
 *   F  coupe de quota : dernier retenu / premier liste d'attente / premier non retenu
 */
class ExactitudeClassementTest extends TestCase
{
    use CreeContexteClassement;
    use RefreshDatabase;

    private Campagne $campagne;
    private User $evaluateur;
    private ServiceClassement $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedContexteClassement();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->service = app(ServiceClassement::class);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Domain\Classement\LigneClassement>
     */
    private function lignesCuisine(): \Illuminate\Support\Collection
    {
        return collect($this->service->calculer($this->campagne->fresh())->lignes)
            ->where('filiereCode', 'cuisine')
            ->sortBy(fn ($l) => $l->rang ?? PHP_INT_MAX)
            ->values();
    }

    public function test_cas_A_departage_par_mixite(): void
    {
        $f = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, ['sexe' => 'F']);
        $h = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, ['sexe' => 'H']);

        $lignes = $this->lignesCuisine();

        $this->assertSame('60.0', number_format($lignes[0]->scoreFinal, 1, '.', ''));
        $this->assertSame('60.0', number_format($lignes[1]->scoreFinal, 1, '.', ''));
        $this->assertSame($f->id, $lignes[0]->candidatureId, 'candidate F classée 1re (mixité)');
        $this->assertSame($h->id, $lignes[1]->candidatureId);
        $this->assertSame(1, $lignes[0]->rang);
        $this->assertSame(2, $lignes[1]->rang);
    }

    public function test_cas_B_departage_par_vulnerabilite(): void
    {
        // Mêmes score et mixité (F) : la vulnérabilité NEET tranche.
        $vulnerable = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'se02' => 'oui', // orphelin → +2
        ]);
        $ordinaire = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'se02' => 'non', 'se06' => 'non', 'se05' => '0', // vuln 0
        ]);

        $lignes = $this->lignesCuisine();
        $this->assertSame($vulnerable->id, $lignes[0]->candidatureId);
        $this->assertSame(2, $lignes[0]->departage['vulnerabilite']);
        $this->assertSame(0, $lignes[1]->departage['vulnerabilite']);
        $this->assertSame($ordinaire->id, $lignes[1]->candidatureId);
    }

    public function test_cas_C_departage_par_experience_secteur(): void
    {
        // Score, mixité, vulnérabilité égaux : l'expérience secteur tranche.
        $secteur = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'domaines' => ['restauration'],
        ]);
        $horsSecteur = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'domaines' => ['commerce'], // valide mais hors {hotellerie, restauration}
        ]);

        $lignes = $this->lignesCuisine();
        $this->assertSame($secteur->id, $lignes[0]->candidatureId);
        $this->assertTrue($lignes[0]->departage['experience_secteur']);
        $this->assertFalse($lignes[1]->departage['experience_secteur']);
        $this->assertSame($horsSecteur->id, $lignes[1]->candidatureId);
    }

    public function test_cas_D_departage_par_motivation(): void
    {
        $forte = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'mo04' => 5,
        ]);
        $faible = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'mo04' => 2,
        ]);

        $lignes = $this->lignesCuisine();
        $this->assertSame($forte->id, $lignes[0]->candidatureId);
        $this->assertSame(5, $lignes[0]->departage['mo04']);
        $this->assertSame($faible->id, $lignes[1]->candidatureId);
    }

    public function test_cas_E_egalite_totale_departage_final_deterministe(): void
    {
        // Tous les 5 critères égaux → date_soumission croissant (D-5a-1).
        $tot = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'date_soumission' => now()->subDays(5),
        ]);
        $tard = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0, [
            'sexe' => 'F', 'date_soumission' => now()->subDays(2),
        ]);

        $lignes = $this->lignesCuisine();
        $this->assertSame($tot->id, $lignes[0]->candidatureId, 'soumis le plus tôt classé 1er');
        $this->assertSame($tard->id, $lignes[1]->candidatureId);

        // Déterminisme : deux calculs successifs donnent le même ordre.
        $this->assertSame(
            $this->lignesCuisine()->pluck('candidatureId')->all(),
            $this->lignesCuisine()->pluck('candidatureId')->all(),
        );
    }

    public function test_cas_F_coupe_de_quota_et_liste_attente(): void
    {
        DB::table('campagne_filiere')
            ->where('campagne_id', $this->campagne->id)
            ->where('filiere_id', $this->idFiliere('cuisine'))
            ->update(['quota' => 2]);

        // 12 candidats, scores finaux distincts 95,0 → 84,0 (dossier 60 + entretien 35..24).
        for ($i = 0; $i < 12; $i++) {
            $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 35.0 - $i);
        }

        $lignes = $this->lignesCuisine();

        $this->assertCount(12, $lignes);
        $this->assertSame('95.0', number_format($lignes[0]->scoreFinal, 1, '.', ''));
        $this->assertSame('84.0', number_format($lignes[11]->scoreFinal, 1, '.', ''));

        $this->assertSame('retenu', $lignes[0]->decision);        // rang 1
        $this->assertSame('retenu', $lignes[1]->decision);        // rang 2 = quota → dernier retenu
        $this->assertSame('liste_attente', $lignes[2]->decision); // rang 3 → premier liste d'attente
        $this->assertSame('liste_attente', $lignes[9]->decision); // rang 10 = quota + 8 → dernier liste d'attente
        $this->assertSame('non_retenu', $lignes[10]->decision);   // rang 11 → premier non retenu
        $this->assertSame('non_retenu', $lignes[11]->decision);
    }

    public function test_le_quota_vient_de_la_base(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0 - $i);
        }

        // quota par défaut 24 → les 3 sont retenus
        $this->assertSame(['retenu', 'retenu', 'retenu'], $this->lignesCuisine()->pluck('decision')->all());

        DB::table('campagne_filiere')
            ->where('campagne_id', $this->campagne->id)
            ->where('filiere_id', $this->idFiliere('cuisine'))
            ->update(['quota' => 1]);

        $this->assertSame(['retenu', 'liste_attente', 'liste_attente'], $this->lignesCuisine()->pluck('decision')->all());
    }
}
