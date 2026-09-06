<?php

namespace Tests\Feature\Console;

use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_cree_un_administrateur_complet_avec_audit(): void
    {
        $this->artisan('casa:create-admin', ['email' => 'coord@casa.example.org'])
            ->expectsQuestion('Mot de passe (min. 10 caractères, majuscule + minuscule + chiffre)', 'MotDePasse2026')
            ->expectsQuestion('Confirmer le mot de passe', 'MotDePasse2026')
            ->expectsQuestion('Prénom', 'Awa')
            ->expectsQuestion('Nom', 'Coordination')
            ->expectsQuestion('Poste', 'Coordinatrice')
            ->assertSuccessful();

        $user = User::where('email', 'coord@casa.example.org')->firstOrFail();
        $this->assertSame('administrateur', $user->role);
        $this->assertTrue($user->actif);
        $this->assertNotNull($user->cgu_acceptees_le);
        $this->assertTrue(password_verify('MotDePasse2026', $user->mot_de_passe_hash));

        $membre = MembreEquipe::where('utilisateur_id', $user->id)->firstOrFail();
        $this->assertSame('Awa', $membre->prenom);
        $this->assertSame('Coordinatrice', $membre->poste);

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id,
            'action' => 'Création de compte administrateur (console)',
            'objet' => 'coord@casa.example.org',
        ]);
    }

    public function test_refuse_un_email_deja_pris(): void
    {
        User::factory()->create(['email' => 'occupe@casa.example.org']);

        $this->artisan('casa:create-admin', ['email' => 'occupe@casa.example.org'])
            ->expectsOutputToContain('Un compte existe déjà')
            ->assertFailed();

        $this->assertSame(1, User::where('email', 'occupe@casa.example.org')->count());
        $this->assertDatabaseCount('membre_equipe', 0);
    }

    public function test_refuse_un_mot_de_passe_trop_faible(): void
    {
        $this->artisan('casa:create-admin', ['email' => 'faible@casa.example.org'])
            ->expectsQuestion('Mot de passe (min. 10 caractères, majuscule + minuscule + chiffre)', 'court1A')
            ->expectsQuestion('Confirmer le mot de passe', 'court1A')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('utilisateur', ['email' => 'faible@casa.example.org']);
    }

    public function test_refuse_une_confirmation_qui_ne_correspond_pas(): void
    {
        $this->artisan('casa:create-admin', ['email' => 'mismatch@casa.example.org'])
            ->expectsQuestion('Mot de passe (min. 10 caractères, majuscule + minuscule + chiffre)', 'MotDePasse2026')
            ->expectsQuestion('Confirmer le mot de passe', 'AutreChose2026')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('utilisateur', ['email' => 'mismatch@casa.example.org']);
    }

    public function test_refuse_un_email_invalide(): void
    {
        $this->artisan('casa:create-admin', ['email' => 'pas-un-email'])
            ->assertExitCode(2);

        $this->assertDatabaseCount('utilisateur', 0);
    }
}
