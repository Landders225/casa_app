<?php

namespace Tests\Feature\Auth;

use App\Models\Campagne;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /api/register` — annulation de la tolérance ADR-42 (ADR-44) : les
 * inscriptions sont closes quand aucune campagne n'est ouverte ET qu'un cycle
 * a déjà eu lieu (au moins une campagne `cloturee`). AVANT toute première
 * ouverture (aucune campagne, ou seulement des `brouillon`), l'inscription
 * reste autorisée — ce n'est volontairement PAS testé ici au-delà du cas
 * `brouillon` : le cas « zéro campagne » est déjà couvert par toute la suite
 * `InscriptionTest`, qui ne seed aucune campagne et reste verte.
 */
class InscriptionFermetureCampagneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'inscription-'.Str::random(8).'@example.ci',
            'password' => 'MotDePasse2026',
            'password_confirmation' => 'MotDePasse2026',
            'prenom' => 'Awa',
            'nom' => 'Koné',
            'sexe' => 'F',
            'date_naissance' => '2001-05-14',
            'cni' => 'CI'.Str::random(9),
            'telephone' => '0708091011',
            'ville_residence' => 'Abidjan - Yopougon',
            'residence_ci' => true,
            'cgu' => true,
        ], $overrides);
    }

    private function creerCampagne(string $statut): Campagne
    {
        return Campagne::create([
            'nom' => 'Cohorte test',
            'statut' => $statut,
            'date_ouverture' => '2026-01-01',
            'date_cloture' => '2026-02-01',
            'places_totales' => 10,
        ]);
    }

    public function test_bloquee_quand_aucune_campagne_ouverte_et_un_cycle_deja_clos(): void
    {
        $this->creerCampagne('cloturee');

        $this->fromSpa()->postJson('/api/register', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Les inscriptions sont closes pour le moment.')
            ->assertJsonPath('code', 'campagne_fermee');

        $this->assertDatabaseCount('utilisateur', 0);
    }

    public function test_autorisee_avant_toute_premiere_ouverture_campagne_en_brouillon(): void
    {
        $this->creerCampagne('brouillon');

        $this->fromSpa()->postJson('/api/register', $this->payload())
            ->assertCreated();

        $this->assertDatabaseCount('utilisateur', 1);
    }

    public function test_autorisee_quand_une_campagne_est_ouverte(): void
    {
        $this->creerCampagne('ouverte');

        $this->fromSpa()->postJson('/api/register', $this->payload())
            ->assertCreated();

        $this->assertDatabaseCount('utilisateur', 1);
    }

    public function test_redevient_possible_apres_reouverture_exceptionnelle(): void
    {
        $campagne = $this->creerCampagne('cloturee');

        $this->fromSpa()->postJson('/api/register', $this->payload())->assertStatus(409);

        $admin = User::factory()->create(['role' => 'administrateur', 'email' => 'admin-test@example.ci']);

        $this->artisan('casa:rouvrir-campagne', [
            'campagne' => $campagne->id,
            '--nouvelle-cloture' => now()->addMonth()->format('Y-m-d'),
            '--raison' => 'Test de non-régression',
            '--operateur' => $admin->email,
        ])->expectsConfirmation('Confirmer la réouverture exceptionnelle ?', 'yes')
            ->assertSuccessful();

        $this->fromSpa()->postJson('/api/register', $this->payload())
            ->assertCreated();
    }
}
