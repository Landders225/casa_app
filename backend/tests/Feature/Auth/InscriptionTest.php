<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inscription candidat (Lot 7, POST /api/register) — comble ADR-13.
 * Sécurité (email unique, mot de passe robuste, throttle, pas d'écriture
 * partielle), éligibilité initiale (âge + résidence, ferme D-3c-1), ADR-07.
 */
class InscriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'awa.kone@example.ci',
            'password' => 'MotDePasse2026',
            'password_confirmation' => 'MotDePasse2026',
            'prenom' => 'Awa',
            'nom' => 'Koné',
            'sexe' => 'F',
            'date_naissance' => '2001-05-14',
            'cni' => 'CI0012345678',
            'telephone' => '0708091011',
            'ville_residence' => 'Abidjan - Yopougon',
            'residence_ci' => true,
            'cgu' => true,
        ], $overrides);
    }

    public function test_inscription_cree_le_compte_ouvre_la_session_et_renvoie_201_sans_hash(): void
    {
        $response = $this->fromSpa()->postJson('/api/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'awa.kone@example.ci')
            ->assertJsonPath('data.role', 'candidat')
            ->assertJsonPath('data.profil.prenom', 'Awa')
            ->assertJsonPath('data.profil.nom', 'Koné')
            ->assertJsonMissingPath('data.profil.residence_ci_editable')
            ->assertJsonMissingPath('data.mot_de_passe_hash');

        $this->assertStringNotContainsString('mot_de_passe_hash', $response->getContent());
        $this->assertStringNotContainsString('$2y$', $response->getContent());

        $this->assertDatabaseHas('utilisateur', ['email' => 'awa.kone@example.ci', 'role' => 'candidat', 'actif' => true]);
        $this->assertDatabaseHas('candidat', ['prenom' => 'Awa', 'nom' => 'Koné', 'residence_ci' => true]);

        // Session ouverte : /api/me répond 200 juste après (auto-login).
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->getJson('/api/me')->assertOk()->assertJsonPath('data.email', 'awa.kone@example.ci');

        // Le mot de passe fourni permet de se connecter (hash effectif).
        $user = User::firstWhere('email', 'awa.kone@example.ci');
        $this->assertTrue(password_verify('MotDePasse2026', $user->getAuthPassword()));

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id,
            'action' => 'Inscription candidat',
            'module' => 'Compte',
        ]);
    }

    public function test_email_deja_pris_message_explicite(): void
    {
        User::factory()->create(['email' => 'awa.kone@example.ci']);

        $this->fromSpa()->postJson('/api/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Un compte existe déjà pour cette adresse e-mail.');

        $this->assertDatabaseCount('candidat', 0);
    }

    /**
     * @dataProvider motsDePasseFaibles
     */
    public function test_mot_de_passe_faible_rejete(string $mauvais): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload([
            'password' => $mauvais,
            'password_confirmation' => $mauvais,
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('utilisateur', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function motsDePasseFaibles(): array
    {
        return [
            'trop court' => ['Court1A'],
            'sans majuscule' => ['motdepasse2026'],
            'sans minuscule' => ['MOTDEPASSE2026'],
            'sans chiffre' => ['MotDePasseSansChiffre'],
        ];
    }

    public function test_confirmation_mot_de_passe_incoherente_422(): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload([
            'password_confirmation' => 'autre-chose-2026',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * @dataProvider agesHorsTranche
     */
    public function test_age_hors_tranche_refuse_sans_creer_de_compte(string $dateNaissance): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload(['date_naissance' => $dateNaissance]))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Le programme CASA s'adresse aux personnes de 18 à 30 ans."]);

        $this->assertDatabaseCount('utilisateur', 0);
        $this->assertDatabaseCount('candidat', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function agesHorsTranche(): array
    {
        return [
            '17 ans' => ['2009-06-01'],   // < 18 au 2026-09-03
            '31 ans' => ['1994-01-01'],   // > 30
        ];
    }

    public function test_residence_hors_ci_refuse(): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload(['residence_ci' => false]))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Le programme CASA est réservé aux personnes résidant en Côte d'Ivoire."]);

        $this->assertDatabaseCount('utilisateur', 0);
    }

    public function test_residence_ci_obligatoire(): void
    {
        $payload = $this->payload();
        unset($payload['residence_ci']);

        $this->fromSpa()->postJson('/api/register', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('residence_ci');
    }

    public function test_cgu_obligatoires_et_horodatees(): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload())->assertCreated();

        $user = User::firstWhere('email', 'awa.kone@example.ci');
        $this->assertNotNull($user->cgu_acceptees_le);
        // Horodatage effectif, proche de « maintenant » (acte tracé, Q2c).
        $this->assertLessThan(5, abs($user->cgu_acceptees_le->diffInSeconds(now())));
    }

    public function test_cgu_refusees_422(): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload(['cgu' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('cgu');

        $this->assertDatabaseCount('utilisateur', 0);
    }

    public function test_adr07_nationalite_et_diplome_sont_prohibes_jamais_persistes(): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload([
            'nationalite' => 'ivoirienne',
            'diplome' => 'bac',
            'diplome_verifie' => 'bac',
        ]))->assertStatus(422)->assertJsonValidationErrors(['nationalite', 'diplome', 'diplome_verifie']);

        $this->assertDatabaseCount('candidat', 0);
    }

    public function test_role_ne_peut_pas_etre_choisi(): void
    {
        $this->fromSpa()->postJson('/api/register', $this->payload(['role' => 'administrateur']))
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_etat_civil_invalide_ne_cree_rien(): void
    {
        // email/mot de passe valides mais sexe hors énumération : la validation
        // barre AVANT toute écriture (a fortiori avant la transaction).
        $this->fromSpa()->postJson('/api/register', $this->payload(['sexe' => 'X']))
            ->assertStatus(422)->assertJsonValidationErrors('sexe');

        $this->assertDatabaseCount('utilisateur', 0);
        $this->assertDatabaseCount('candidat', 0);
    }

    public function test_inscription_rate_limited_par_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->fromSpa()->postJson('/api/register', $this->payload(['email' => "essai{$i}@example.ci"]))
                ->assertCreated();
        }

        $this->fromSpa()->postJson('/api/register', $this->payload(['email' => 'essai-de-trop@example.ci']))
            ->assertStatus(429);
    }
}
