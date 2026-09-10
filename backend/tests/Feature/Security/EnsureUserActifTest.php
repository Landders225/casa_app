<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `EnsureUserActif` (Lot 11b, ADR-29) — le vrai enjeu du lot.
 *
 * `AuthController::login` refuse déjà un compte désactivé, mais SEULEMENT au
 * login. Ce middleware, sur le groupe `auth:sanctum`, revérifie `actif` à
 * CHAQUE requête : une session déjà ouverte perd l'accès au PROCHAIN appel,
 * pas à l'expiration de session (jusqu'à 120 min plus tard). Indispensable
 * pour écarter un membre du jury EN URGENCE.
 */
class EnsureUserActifTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_compte_actif_conserve_l_acces(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/me')->assertOk();
    }

    public function test_un_compte_desactive_pendant_la_session_perd_l_acces_au_prochain_appel(): void
    {
        $user = User::factory()->evaluateur()->create();

        // Session ouverte, accès normal.
        $this->actingAs($user)->getJson('/api/me')->assertOk();

        // Un admin le désactive (ici : mise à jour directe — l'effet du PATCH est
        // couvert par GestionMembresEquipeTest).
        $user->forceFill(['actif' => false])->save();

        // PROCHAIN appel de la MÊME session : coupé, pas au prochain login.
        $this->actingAs($user->fresh())->getJson('/api/me')->assertStatus(401);
    }

    public function test_le_refus_ne_divulgue_pas_l_ancien_hash(): void
    {
        $user = User::factory()->inactif()->create();

        $reponse = $this->actingAs($user)->getJson('/api/me')->assertStatus(401);
        $this->assertStringNotContainsString('$2y$', $reponse->getContent());
        $this->assertSame('Votre compte a été désactivé.', $reponse->json('message'));
    }

    public function test_le_middleware_couvre_tous_les_espaces_pas_seulement_admin(): void
    {
        $candidat = User::factory()->inactif()->create();
        $this->actingAs($candidat)->getJson('/api/candidature')->assertStatus(401);

        $evaluateur = User::factory()->evaluateur()->inactif()->create();
        $this->actingAs($evaluateur)->getJson('/api/evaluateur/candidatures')->assertStatus(401);
    }
}
