<?php

namespace Tests\Feature\Console;

use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateMembreTest extends TestCase
{
    use RefreshDatabase;

    private const PWD_PROMPT = 'Mot de passe (min. 10 caractères, majuscule + minuscule + chiffre)';

    public function test_cree_un_evaluateur_par_defaut_avec_audit(): void
    {
        $this->artisan('casa:create-membre', ['email' => 'awa.evaluatrice@cci.ci'])
            ->expectsQuestion(self::PWD_PROMPT, 'MotDePasse2026')
            ->expectsQuestion('Confirmer le mot de passe', 'MotDePasse2026')
            ->expectsQuestion('Prénom', 'Awa')
            ->expectsQuestion('Nom', 'Traoré')
            ->expectsQuestion('Poste', 'Jury filière cuisine')
            ->assertSuccessful();

        $user = User::where('email', 'awa.evaluatrice@cci.ci')->firstOrFail();
        $this->assertSame('evaluateur', $user->role);
        $this->assertTrue($user->actif);
        $this->assertNotNull($user->cgu_acceptees_le);
        $this->assertTrue(password_verify('MotDePasse2026', $user->mot_de_passe_hash));

        $membre = MembreEquipe::where('utilisateur_id', $user->id)->firstOrFail();
        $this->assertSame('Awa', $membre->prenom);
        $this->assertSame('Traoré', $membre->nom);
        $this->assertSame('Jury filière cuisine', $membre->poste);

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id,
            'role' => 'evaluateur',
            'action' => 'Création de compte evaluateur (console)',
            'objet' => 'awa.evaluatrice@cci.ci',
        ]);
    }

    public function test_cree_un_administrateur_via_l_option_role(): void
    {
        $this->artisan('casa:create-membre', ['email' => 'admin2@cci.ci', '--role' => 'administrateur'])
            ->expectsQuestion(self::PWD_PROMPT, 'MotDePasse2026')
            ->expectsQuestion('Confirmer le mot de passe', 'MotDePasse2026')
            ->expectsQuestion('Prénom', 'Kofi')
            ->expectsQuestion('Nom', 'Mensah')
            ->expectsQuestion('Poste', 'Administrateur')
            ->assertSuccessful();

        $user = User::where('email', 'admin2@cci.ci')->firstOrFail();
        $this->assertSame('administrateur', $user->role);

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id,
            'action' => 'Création de compte administrateur (console)',
            'objet' => 'admin2@cci.ci',
        ]);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function rolesInterdits(): array
    {
        return [
            'candidat' => ['candidat'],
            'rôle arbitraire' => ['superadmin'],
            'vide' => [''],
        ];
    }

    #[DataProvider('rolesInterdits')]
    public function test_refuse_un_role_non_autorise_sans_aucune_ecriture(string $role): void
    {
        // Aucun `expectsQuestion` : l'échec est immédiat, avant tout prompt.
        $this->artisan('casa:create-membre', ['email' => 'x@cci.ci', '--role' => $role])
            ->assertExitCode(2);

        $this->assertDatabaseCount('utilisateur', 0);
        $this->assertDatabaseCount('membre_equipe', 0);
        $this->assertDatabaseCount('journal_audit', 0);
    }

    public function test_refuse_un_email_deja_pris(): void
    {
        User::factory()->evaluateur()->create(['email' => 'occupe@cci.ci']);

        $this->artisan('casa:create-membre', ['email' => 'occupe@cci.ci'])
            ->expectsOutputToContain('Un compte existe déjà')
            ->assertFailed();

        $this->assertSame(1, User::where('email', 'occupe@cci.ci')->count());
        $this->assertDatabaseCount('membre_equipe', 0);
    }

    public function test_refuse_un_mot_de_passe_trop_faible(): void
    {
        $this->artisan('casa:create-membre', ['email' => 'faible@cci.ci'])
            ->expectsQuestion(self::PWD_PROMPT, 'court1A')
            ->expectsQuestion('Confirmer le mot de passe', 'court1A')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('utilisateur', ['email' => 'faible@cci.ci']);
        $this->assertDatabaseCount('journal_audit', 0);
    }

    public function test_refuse_une_confirmation_qui_ne_correspond_pas(): void
    {
        $this->artisan('casa:create-membre', ['email' => 'mismatch@cci.ci'])
            ->expectsQuestion(self::PWD_PROMPT, 'MotDePasse2026')
            ->expectsQuestion('Confirmer le mot de passe', 'AutreChose2026')
            ->assertExitCode(2);

        $this->assertDatabaseMissing('utilisateur', ['email' => 'mismatch@cci.ci']);
    }

    public function test_refuse_un_email_invalide(): void
    {
        $this->artisan('casa:create-membre', ['email' => 'pas-un-email'])
            ->assertExitCode(2);

        $this->assertDatabaseCount('utilisateur', 0);
    }
}
