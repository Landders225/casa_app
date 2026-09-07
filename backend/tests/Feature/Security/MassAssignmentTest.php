<?php

namespace Tests\Feature\Security;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Candidat\CreeContexteCandidature;
use Tests\TestCase;

/**
 * MASS ASSIGNMENT — aucun champ interne n'est pilotable par une requête (Lot 10).
 *
 * Le code construit toujours ses payloads clé par clé (`Model::create([...])`)
 * ou via des méthodes curées (`champsVerification()`), jamais `->fill($request->all())`.
 * Les `FormRequest` marquent en `prohibited` ce qui ne doit jamais transiter
 * (rôle, e-mail, résidence…). Ce test tente de forcer chaque champ sensible et
 * vérifie qu'il n'a AUCUN effet — soit 422, soit ignoré silencieusement.
 */
class MassAssignmentTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    // --- Inscription ---------------------------------------------------------

    private function payloadInscription(array $extra = []): array
    {
        return array_merge([
            'email' => 'nouveau'.fake()->numerify('####').'@exemple.ci',
            'password' => 'MotDePasse2026',
            'password_confirmation' => 'MotDePasse2026',
            'prenom' => 'Awa', 'nom' => 'Koné', 'sexe' => 'F',
            'date_naissance' => '2004-05-01',
            'cni' => 'CI123456789', 'telephone' => '0700000000',
            'ville_residence' => 'Abidjan', 'residence_ci' => true, 'cgu' => true,
        ], $extra);
    }

    public function test_register_refuse_role_injecte(): void
    {
        $this->fromSpa()
            ->postJson('/api/register', $this->payloadInscription(['role' => 'administrateur']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseCount('utilisateur', 0);
    }

    public function test_register_ignore_actif_et_id_injectes(): void
    {
        $idForge = '11111111-1111-1111-1111-111111111111';

        $this->fromSpa()->postJson('/api/register', $this->payloadInscription([
            'actif' => false,
            'id' => $idForge,
            'cgu_acceptees_le' => '2000-01-01',
            'derniere_connexion_le' => '2000-01-01',
        ]))->assertCreated();

        $user = User::query()->firstOrFail();
        $this->assertTrue($user->actif, 'actif injecté à false ne doit pas être pris en compte');
        $this->assertNotSame($idForge, $user->id, 'id forgé ne doit pas être pris en compte');
        $this->assertTrue($user->cgu_acceptees_le->isToday(), 'cgu_acceptees_le est posé serveur, pas par le client');
    }

    // --- Création de candidature -------------------------------------------

    public function test_creation_candidature_ignore_les_colonnes_internes(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();

        $reponse = $this->actingAs($candidat)->postJson('/api/candidatures', [
            'filiere_id' => $this->idFiliere('cuisine'),
            'statut_interne' => 'evalue',
            'statut_eligibilite_interne' => 'eligible',
            'dossier_verrouille' => true,
            'evaluateur_id' => $candidat->id,
            'numero_dossier' => 'PIRATE-0001',
        ])->assertCreated();

        $candidature = Candidature::findOrFail($reponse->json('data.id'));
        $this->assertSame('brouillon', $candidature->statut_interne);
        $this->assertSame('non_verifie', $candidature->statut_eligibilite_interne);
        $this->assertFalse((bool) $candidature->dossier_verrouille);
        $this->assertNull($candidature->evaluateur_id);
        $this->assertNotSame('PIRATE-0001', $candidature->numero_dossier);
    }

    // --- Profil candidat ---------------------------------------------------

    public function test_patch_profil_refuse_email_et_residence(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat('moi@exemple.ci');

        $this->actingAs($candidat)->patchJson('/api/candidat/profil', [
            'email' => 'usurpe@exemple.ci',
            'residence_ci' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'residence_ci']);

        $this->assertSame('moi@exemple.ci', $candidat->fresh()->email);
        $this->assertTrue((bool) $candidat->fresh()->candidat->residence_ci);
    }

    public function test_patch_profil_ignore_role_et_actif_injectes(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();

        $this->actingAs($candidat)->patchJson('/api/candidat/profil', [
            'prenom' => 'Nouveau',
            'role' => 'administrateur',
            'actif' => false,
        ])->assertOk();

        $frais = $candidat->fresh();
        $this->assertSame('candidat', $frais->role);
        $this->assertTrue($frais->actif);
        $this->assertSame('Nouveau', $frais->candidat->prenom); // le champ légitime est bien pris
    }

    // --- Vérification évaluateur -----------------------------------------

    public function test_verification_ignore_le_verdict_d_eligibilite_injecte(): void
    {
        $this->seedReferentiels();
        $candidat = $this->creerCandidat();
        $candidatureId = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        $candidature = Candidature::findOrFail($candidatureId);
        $candidature->forceFill(['statut_interne' => 'en_instruction', 'statut_eligibilite_interne' => 'non_eligible'])->save();

        $evaluateur = User::factory()->evaluateur()->create();
        $evaluateur->membreEquipe()->create(['prenom' => 'Ev', 'nom' => 'Al', 'poste' => 'Évaluateur']);
        $candidature->forceFill(['evaluateur_id' => $evaluateur->membreEquipe->id])->save();

        // L'évaluateur confirme tout ; il tente aussi d'injecter le verdict.
        $this->actingAs($evaluateur)->putJson("/api/evaluateur/candidatures/{$candidatureId}/verification", [
            'nationalite_confirmee' => true,
            'diplome_verifie' => 'bac',
            'statut_eligibilite_interne' => 'non_eligible', // injection
            'statut_interne' => 'evalue', // injection
        ])->assertOk();

        $frais = $candidature->fresh();
        // Le verdict est RECALCULÉ (aucun critère restant -> eligible), pas pris du body.
        $this->assertSame('eligible', $frais->statut_eligibilite_interne);
        $this->assertSame('en_instruction', $frais->statut_interne);
    }
}
