<?php

namespace Tests\Feature\Candidat;

use App\Models\JournalAudit;
use App\Models\User;
use App\Notifications\MotDePasseModifie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lot 13 — `PUT /api/candidat/mot-de-passe` (ADR-32), candidat CONNECTÉ.
 *
 * Verrouillé : le mot de passe ACTUEL est exigé et vérifié ; le changement
 * invalide les AUTRES sessions (jamais la session courante) ; audit sans
 * valeur ; e-mail de confirmation.
 */
class ChangementMotDePasseTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    public function test_mauvais_mot_de_passe_actuel_refuse_rien_ne_change(): void
    {
        Notification::fake();
        $user = $this->creerCandidat('cand@cci.ci');

        $reponse = $this->actingAs($user)->putJson('/api/candidat/mot-de-passe', [
            'current_password' => 'MauvaisMotDePasse',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(422);

        $this->assertSame('Le mot de passe actuel est incorrect.', $reponse->json('errors.current_password.0'));
        $this->assertTrue(Hash::check('password', $user->fresh()->mot_de_passe_hash)); // inchangé
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('journal_audit', ['action' => 'Changement de mot de passe']);
    }

    public function test_changement_reussi_l_ancien_mot_de_passe_ne_fonctionne_plus(): void
    {
        Notification::fake();
        $user = $this->creerCandidat('cand@cci.ci');

        $this->fromSpa()->actingAs($user)->putJson('/api/candidat/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertOk()->assertJsonPath('message', 'Votre mot de passe a été modifié.');

        $this->assertTrue(Hash::check('NouveauMdp2026', $user->fresh()->mot_de_passe_hash));

        $this->fromSpa()->postJson('/api/login', ['email' => 'cand@cci.ci', 'password' => 'password'])
            ->assertStatus(422); // ancien : refusé
        $this->fromSpa()->postJson('/api/login', ['email' => 'cand@cci.ci', 'password' => 'NouveauMdp2026'])
            ->assertOk(); // nouveau : accepté

        Notification::assertSentTo($user, MotDePasseModifie::class);
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id, 'role' => 'candidat',
            'action' => 'Changement de mot de passe', 'module' => 'Compte', 'objet' => 'cand@cci.ci',
        ]);
        // Aucune valeur de mot de passe dans l'audit.
        $ligne = JournalAudit::where('action', 'Changement de mot de passe')->firstOrFail();
        $this->assertNull($ligne->ancienne_valeur);
        $this->assertNull($ligne->nouvelle_valeur);
    }

    public function test_invalide_les_autres_sessions_existantes(): void
    {
        Notification::fake();
        $user = $this->creerCandidat('cand@cci.ci');

        // Simule un « autre appareil » déjà connecté sous ce compte.
        DB::table('sessions')->insert([
            'id' => 'autre-appareil', 'user_id' => $user->id, 'ip_address' => '10.0.0.9',
            'user_agent' => 'autre navigateur', 'payload' => base64_encode('x'), 'last_activity' => time(),
        ]);

        $this->fromSpa()->actingAs($user)->putJson('/api/candidat/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'autre-appareil']);

        // Le contexte d'authentification n'est pas cassé par l'opération : un
        // appel authentifié suivant fonctionne toujours (preuve E2E complémentaire
        // que la session COURANTE, elle, survit — cf. profil-mot-de-passe.spec.js).
        $this->actingAs($user->fresh())->getJson('/api/candidat/profil')->assertOk();
    }

    public function test_nouveau_mot_de_passe_identique_a_l_actuel_refuse(): void
    {
        $user = $this->creerCandidat('cand@cci.ci');

        $this->actingAs($user)->putJson('/api/candidat/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_nouveau_mot_de_passe_trop_faible_refuse(): void
    {
        $user = $this->creerCandidat('cand@cci.ci');

        $this->actingAs($user)->putJson('/api/candidat/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'faible', 'password_confirmation' => 'faible',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_confirmation_qui_ne_correspond_pas_refusee(): void
    {
        $user = $this->creerCandidat('cand@cci.ci');

        $this->actingAs($user)->putJson('/api/candidat/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'AutreChose2026',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_requiert_authentification(): void
    {
        $this->putJson('/api/candidat/mot-de-passe', [
            'current_password' => 'password', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(401);
    }

    public function test_reserve_au_role_candidat(): void
    {
        foreach ([User::factory()->evaluateur()->create(), User::factory()->administrateur()->create()] as $intrus) {
            $this->actingAs($intrus)->putJson('/api/candidat/mot-de-passe', [
                'current_password' => 'password', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
            ])->assertStatus(403);
        }
    }
}
