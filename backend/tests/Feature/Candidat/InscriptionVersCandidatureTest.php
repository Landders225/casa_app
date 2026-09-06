<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Non-régression du Lot 3a après le Lot 7 : le parcours COMPLET
 * inscription -> candidature fonctionne SANS aucun seeder de compte.
 *
 * Avant le Lot 7, tester le 3a exigeait un `candidat` pré-existant (seedé ou
 * fabriqué). ADR-13 est désormais comblé : `POST /api/register` crée le compte,
 * `POST /api/candidatures` crée la candidature (code 3a inchangé).
 */
class InscriptionVersCandidatureTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels(); // filières + campagne « Cohorte 1 » ouverte
    }

    public function test_parcours_complet_inscription_puis_candidature_sans_seeder(): void
    {
        // 1. Inscription -> compte créé + session ouverte (auto-login).
        $this->fromSpa()->postJson('/api/register', [
            'email' => 'nouvelle.candidate@example.ci',
            'password' => 'MonMotDePasse2026',
            'password_confirmation' => 'MonMotDePasse2026',
            'prenom' => 'Aya', 'nom' => 'Traoré', 'sexe' => 'F',
            'date_naissance' => '2002-11-20',
            'cni' => 'CI0099887766', 'telephone' => '0709081011',
            'ville_residence' => 'Abidjan - Cocody',
            'residence_ci' => true, 'cgu' => true,
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        // 2. Candidature (endpoint 3a, inchangé) avec la session d'inscription.
        $response = $this->fromSpa()->postJson('/api/candidatures', [
            'filiere_id' => $this->idFiliere('cuisine'),
        ])->assertCreated()
            ->assertJsonPath('data.statut_public', 'brouillon')
            ->assertJsonPath('data.filiere.code', 'cuisine')
            ->assertJsonPath('data.campagne.nom', 'Cohorte 1 — 2026');

        $numero = $response->json('data.numero_dossier');
        $this->assertMatchesRegularExpression('/^CASA-2026-\d{6}$/', $numero);

        $candidature = Candidature::firstWhere('numero_dossier', $numero);
        $this->assertNotNull($candidature->reponseFormulaire);       // 1-1 vide
        $this->assertCount(5, $candidature->classement);             // préférences pré-remplies
        $this->assertSame(
            $this->idFiliere('cuisine'),
            $candidature->classement()->where('rang', 1)->value('filiere_id'),
        );

        // 3. Lecture de sa candidature + de son profil.
        $this->fromSpa()->getJson('/api/candidature')->assertOk()->assertJsonPath('data.numero_dossier', $numero);
        $this->fromSpa()->getJson('/api/candidat/profil')->assertOk()->assertJsonPath('data.prenom', 'Aya');
    }

    public function test_candidature_impossible_sans_inscription_prealable(): void
    {
        // Un compte candidat SANS profil (cas résiduel : ne devrait plus arriver
        // via /register, mais le garde-fou 3a reste un filet de sécurité).
        $user = User::factory()->create(['role' => 'candidat']);

        $this->actingAs($user)->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->assertStatus(422);
    }
}
