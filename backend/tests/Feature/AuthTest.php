<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ComptesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_ERROR = 'E-mail ou mot de passe incorrect.';

    public function test_csrf_cookie_endpoint_is_reachable(): void
    {
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
    }

    public function test_login_succeeds_and_updates_derniere_connexion(): void
    {
        $user = User::factory()->create(['email' => 'a@casa-demo.ci']);
        $this->assertNull($user->derniere_connexion_le);

        $response = $this->fromSpa()->postJson('/api/login', [
            'email' => 'a@casa-demo.ci',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'a@casa-demo.ci')
            ->assertJsonPath('data.role', 'candidat');

        $this->assertNotNull($user->fresh()->derniere_connexion_le);
    }

    public function test_login_response_never_exposes_password_hash(): void
    {
        User::factory()->create(['email' => 'b@casa-demo.ci']);

        $response = $this->fromSpa()->postJson('/api/login', [
            'email' => 'b@casa-demo.ci',
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('mot_de_passe_hash', $response->getContent());
        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'c@casa-demo.ci']);

        $this->fromSpa()->postJson('/api/login', ['email' => 'c@casa-demo.ci', 'password' => 'mauvais'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', self::GENERIC_ERROR);
    }

    public function test_login_fails_with_unknown_email_same_message(): void
    {
        $this->fromSpa()->postJson('/api/login', ['email' => 'inconnu@casa-demo.ci', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', self::GENERIC_ERROR);
    }

    public function test_inactive_account_receives_the_generic_failure_not_a_disabled_hint(): void
    {
        User::factory()->inactif()->create(['email' => 'off@casa-demo.ci']);

        $response = $this->fromSpa()->postJson('/api/login', [
            'email' => 'off@casa-demo.ci',
            'password' => 'password', // mot de passe CORRECT
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.email.0', self::GENERIC_ERROR);
        $this->assertStringNotContainsString('désactivé', $response->getContent());
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'd@casa-demo.ci']);

        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/login', ['email' => 'd@casa-demo.ci', 'password' => 'mauvais'])
                ->assertStatus(422);
        }

        $this->fromSpa()->postJson('/api/login', ['email' => 'd@casa-demo.ci', 'password' => 'mauvais'])
            ->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_returns_current_user_and_profil_without_sensitive_fields(): void
    {
        $user = User::factory()->administrateur()->create();
        $user->membreEquipe()->create([
            'prenom' => 'Prisca', 'nom' => 'Yéo', 'poste' => 'Coordinatrice projet CASA',
        ]);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('data.role', 'administrateur')
            ->assertJsonPath('data.profil.poste', 'Coordinatrice projet CASA')
            ->assertJsonMissingPath('data.mot_de_passe_hash');
        $this->assertStringNotContainsString('mot_de_passe_hash', $response->getContent());
    }

    public function test_logout_invalidates_the_session(): void
    {
        User::factory()->create(['email' => 'e@casa-demo.ci']);

        $this->fromSpa()->postJson('/api/login', ['email' => 'e@casa-demo.ci', 'password' => 'password'])->assertOk();

        // Le harness partage le conteneur entre sous-requêtes ; on oublie les
        // guards résolus pour que chaque appel reparte d'un état neuf (comme un
        // process PHP distinct en production).
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->getJson('/api/me')->assertOk(); // session valide -> authentifié

        $this->fromSpa()->postJson('/api/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->fromSpa()->getJson('/api/me')->assertStatus(401); // session invalidée
    }

    public function test_seeded_demo_accounts_can_log_in(): void
    {
        $this->seed(ComptesDemoSeeder::class);

        $comptes = [
            'candidat@casa-demo.ci' => 'candidat',
            'evaluateur@casa-demo.ci' => 'evaluateur',
            'admin@casa-demo.ci' => 'administrateur',
        ];

        foreach ($comptes as $email => $role) {
            $this->fromSpa()->postJson('/api/login', ['email' => $email, 'password' => 'Demo2026!'])
                ->assertOk()
                ->assertJsonPath('data.role', $role);
            $this->fromSpa()->postJson('/api/logout')->assertNoContent();
        }
    }
}
