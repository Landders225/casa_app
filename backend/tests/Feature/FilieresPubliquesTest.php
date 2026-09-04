<?php

namespace Tests\Feature;

use App\Models\Filiere;
use Database\Seeders\FiliereSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/filieres` (Lot 6a) — PREMIÈRE ROUTE PUBLIQUE du projet.
 * Liste blanche stricte des 4 champs 🟢, aucune authentification requise.
 */
class FilieresPubliquesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FiliereSeeder::class);
    }

    public function test_route_publique_accessible_sans_authentification(): void
    {
        $this->getJson('/api/filieres')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_liste_blanche_stricte_des_champs_verts(): void
    {
        $reponse = $this->getJson('/api/filieres')->assertOk();

        foreach ($reponse->json('data') as $ligne) {
            // `id` ajouté au Lot 8b-2 : nécessaire au frontend candidat pour
            // POST /api/candidatures et PUT .../classement ; c'est un UUID de
            // filière, déjà exposé au candidat authentifié — pas un secret.
            $this->assertSame(['id', 'code', 'nom', 'description', 'actif'], array_keys($ligne));
            $this->assertIsString($ligne['id']);
        }

        // Aucune donnée INTERNE (quota, icône, timestamps) ni de campagne.
        $body = $reponse->getContent();
        foreach (['quota', 'campagne', 'created_at', 'updated_at', 'icone'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $body);
        }
    }

    public function test_une_filiere_desactivee_reste_listee_avec_actif_false(): void
    {
        Filiere::where('code', 'cuisine')->update(['actif' => false]);

        $reponse = $this->getJson('/api/filieres')->assertOk();

        $cuisine = collect($reponse->json('data'))->firstWhere('code', 'cuisine');
        $this->assertFalse($cuisine['actif']);
        // Toujours listée (le front affiche « Actuellement fermé »).
        $this->assertCount(5, $reponse->json('data'));
    }
}
