<?php

namespace Tests\Feature\Console;

use Database\Seeders\CampagneSeeder;
use Database\Seeders\FiliereSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedReferentielTest extends TestCase
{
    use RefreshDatabase;

    public function test_amorce_le_referentiel_sans_les_comptes_demo(): void
    {
        // RefreshDatabase repart d'une base migrée VIDE (pas de seeders).
        $this->assertDatabaseCount('filiere', 0);
        $this->assertDatabaseCount('grille', 0);

        $this->artisan('casa:seed-referentiel')->assertSuccessful();

        // Référentiel présent…
        $this->assertGreaterThan(0, DB::table('filiere')->count());
        $this->assertGreaterThan(0, DB::table('grille')->count());
        $this->assertGreaterThan(0, DB::table('type_document')->count());
        $this->assertDatabaseHas('campagne', ['nom' => 'Cohorte 1 — 2026']);

        // …mais AUCUN compte de démonstration.
        $this->assertDatabaseMissing('utilisateur', ['email' => 'admin@casa-demo.ci']);
        $this->assertDatabaseMissing('utilisateur', ['email' => 'candidat@casa-demo.ci']);
        $this->assertDatabaseCount('utilisateur', 0);
    }

    public function test_est_idempotent_et_prudent(): void
    {
        $this->artisan('casa:seed-referentiel')->assertSuccessful();
        $filieres = DB::table('filiere')->count();

        // 2e passage sans --force : ne touche à rien.
        $this->artisan('casa:seed-referentiel')
            ->expectsOutputToContain('déjà présent')
            ->assertSuccessful();

        $this->assertSame($filieres, DB::table('filiere')->count());
    }

    public function test_filiere_et_campagne_seeders_sont_rejouables_sans_dupliquer(): void
    {
        // Teste directement les 2 seeders corrigés (pas la commande complète :
        // GrilleBaremeSeeder, lui, n'est PAS idempotent — insert brut, contrainte
        // `one_active_grille` — et reste hors périmètre de cette correction,
        // tracé séparément dans docs/POINTS-OUVERTS.md).
        $this->seed(FiliereSeeder::class);
        $this->seed(CampagneSeeder::class);

        $filieres = DB::table('filiere')->count();
        $competences = DB::table('filiere_competence')->count();
        $campagnes = DB::table('campagne')->count();
        $campagneId = DB::table('campagne')->where('nom', 'Cohorte 1 — 2026')->value('id');
        $quotas = DB::table('campagne_filiere')->count();
        $this->assertGreaterThan(0, $filieres);
        $this->assertGreaterThan(0, $quotas);

        // Rejoués une 2e fois : convergent vers le même état, ne dupliquent rien
        // (incohérence trouvée à la revue pré-prod, corrigée ici).
        $this->seed(FiliereSeeder::class);
        $this->seed(CampagneSeeder::class);

        $this->assertSame($filieres, DB::table('filiere')->count());
        $this->assertSame($competences, DB::table('filiere_competence')->count());
        $this->assertSame($campagnes, DB::table('campagne')->count());
        $this->assertSame($campagneId, DB::table('campagne')->where('nom', 'Cohorte 1 — 2026')->value('id'));
        $this->assertSame($quotas, DB::table('campagne_filiere')->count());
    }
}
