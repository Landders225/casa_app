<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Candidat\CreeContexteCandidature;
use Tests\TestCase;

/**
 * LIMITATION DE DÉBIT (Lot 10, T1).
 *
 * Avant ce lot, seuls `/login` et `/register` étaient limités : un compte
 * authentifié (ou un anonyme sur les routes publiques) pouvait marteler
 * n'importe quel endpoint. Ajouté :
 *   - `casa-public`      60/min/IP   — /api/health, /api/filieres (hors session)
 *   - `casa-api`        120/min/user — filet global du groupe authentifié
 *   - `casa-uploads`     40/min/user — dépôt de pièces (finfo + écriture disque)
 *   - `casa-candidatures` 12/min/user — anti-spam de brouillons
 *
 * Calibrage : très au-dessus de l'usage humain le plus intense (le wizard fait
 * ~40 requêtes sur 10+ min). Ce test prouve à la fois que la limite EXISTE et
 * qu'un usage normal ne la touche jamais.
 */
class RateLimitingTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    public function test_route_publique_limitee_a_60_par_minute(): void
    {
        // 60 passent, la 61e est refusée.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/health')->assertOk();
        }
        $this->getJson('/api/health')->assertStatus(429);
    }

    public function test_entete_de_limite_publique(): void
    {
        $this->getJson('/api/filieres')->assertHeader('X-RateLimit-Limit', 60);
    }

    public function test_filet_global_authentifie_annonce_120(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/me')->assertHeader('X-RateLimit-Limit', 120);
    }

    public function test_depot_de_piece_annonce_la_limite_uploads(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();
        $candidatureId = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        // Le POST échoue en validation (pas de fichier) mais traverse le throttle :
        // c'est bien la limite `casa-uploads` (40) qui est annoncée, pas la globale.
        $this->actingAs($candidat)
            ->post("/api/candidatures/{$candidatureId}/pieces/cni", [])
            ->assertHeader('X-RateLimit-Limit', 40);
    }

    public function test_creation_de_candidature_limitee_a_12_par_minute(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();
        $filiere = $this->idFiliere('cuisine');

        // Une seule candidature ouverte est permise fonctionnellement (409 ensuite),
        // mais le compteur de débit s'incrémente à CHAQUE tentative.
        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($candidat)->postJson('/api/candidatures', ['filiere_id' => $filiere]);
        }
        $this->actingAs($candidat)->postJson('/api/candidatures', ['filiere_id' => $filiere])
            ->assertStatus(429);
    }

    public function test_un_usage_normal_du_wizard_ne_touche_jamais_la_limite(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();
        $candidatureId = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        // 40 sauvegardes de réponses d'affilée (bien au-delà d'un vrai parcours
        // de 10 étapes) : toutes passent, la limite globale (120) n'est pas atteinte.
        for ($i = 0; $i < 40; $i++) {
            $this->actingAs($candidat)
                ->patchJson("/api/candidatures/{$candidatureId}/reponses", ['sc01_scolarise_actuellement' => 'non'])
                ->assertOk();
        }
    }

    public function test_login_reste_limite_independamment_du_filet_global(): void
    {
        User::factory()->create(['email' => 'x@exemple.ci']);

        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/login', ['email' => 'x@exemple.ci', 'password' => 'faux'])
                ->assertStatus(422);
        }
        $this->fromSpa()->postJson('/api/login', ['email' => 'x@exemple.ci', 'password' => 'faux'])
            ->assertStatus(429);
    }
}
