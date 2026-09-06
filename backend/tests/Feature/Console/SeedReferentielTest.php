<?php

namespace Tests\Feature\Console;

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
}
