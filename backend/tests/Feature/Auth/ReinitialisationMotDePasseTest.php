<?php

namespace Tests\Feature\Auth;

use App\Notifications\MotDePasseModifie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Candidat\CreeContexteCandidature;
use Tests\TestCase;

/**
 * Lot 13 — `POST /api/mot-de-passe/reinitialiser` (ADR-32).
 *
 * Vérifie : succès réel (login avec le nouveau mdp, ancien rejeté), usage
 * UNIQUE du token, EXPIRATION, et que les deux échecs (« mauvais token » /
 * « mauvais e-mail ») rendent le MÊME message générique (anti-énumération).
 */
class ReinitialisationMotDePasseTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private const MESSAGE_INVALIDE = 'Ce lien de réinitialisation est invalide ou a expiré.';

    public function test_reinitialisation_reussie_change_le_mot_de_passe_et_coupe_les_sessions(): void
    {
        Notification::fake();
        $user = $this->creerCandidat('cand@cci.ci');
        $token = Password::broker()->createToken($user);

        // Une session « ouverte » existante (simulée) doit être purgée.
        \DB::table('sessions')->insert([
            'id' => 'session-fantome', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => base64_encode('x'), 'last_activity' => time(),
        ]);

        $reponse = $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => $token, 'email' => 'cand@cci.ci',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertOk();

        $this->assertSame('Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.', $reponse->json('message'));
        $this->assertTrue(Hash::check('NouveauMdp2026', $user->fresh()->mot_de_passe_hash));

        // Sessions purgées.
        $this->assertDatabaseMissing('sessions', ['id' => 'session-fantome']);

        // Ancien mot de passe rejeté, nouveau accepté.
        $this->fromSpa()->postJson('/api/login', ['email' => 'cand@cci.ci', 'password' => 'password'])
            ->assertStatus(422);
        $this->fromSpa()->postJson('/api/login', ['email' => 'cand@cci.ci', 'password' => 'NouveauMdp2026'])
            ->assertOk();

        Notification::assertSentTo($user, MotDePasseModifie::class);
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id,
            'action' => 'Réinitialisation du mot de passe (lien oublié)',
            'objet' => 'cand@cci.ci',
        ]);
    }

    public function test_le_token_est_a_usage_unique(): void
    {
        $user = $this->creerCandidat('cand@cci.ci');
        $token = Password::broker()->createToken($user);

        $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => $token, 'email' => 'cand@cci.ci',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertOk();

        // Rejoué avec un AUTRE mot de passe : refusé, le premier reste en place.
        $reponse = $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => $token, 'email' => 'cand@cci.ci',
            'password' => 'EncoreUnAutre2026', 'password_confirmation' => 'EncoreUnAutre2026',
        ])->assertStatus(422);

        $this->assertSame(self::MESSAGE_INVALIDE, $reponse->json('message'));
        $this->assertTrue(Hash::check('NouveauMdp2026', $user->fresh()->mot_de_passe_hash));
    }

    public function test_un_token_expire_est_refuse(): void
    {
        $user = $this->creerCandidat('cand@cci.ci');
        $token = Password::broker()->createToken($user);

        // `expire` = 60 min (config/auth.php) : on recule created_at de 61 min.
        \DB::table('password_reset_tokens')
            ->where('email', 'cand@cci.ci')
            ->update(['created_at' => now()->subMinutes(61)]);

        $reponse = $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => $token, 'email' => 'cand@cci.ci',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(422);

        $this->assertSame(self::MESSAGE_INVALIDE, $reponse->json('message'));
        $this->assertTrue(Hash::check('password', $user->fresh()->mot_de_passe_hash));
    }

    /**
     * MÊME message que le token soit faux OU que l'e-mail n'ait pas de token —
     * c'est le point central de l'anti-énumération à cette étape.
     */
    public function test_token_invalide_et_email_inconnu_rendent_le_meme_message(): void
    {
        $user = $this->creerCandidat('cand@cci.ci');
        Password::broker()->createToken($user);

        $reponseMauvaisToken = $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => 'un-token-invente', 'email' => 'cand@cci.ci',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(422);

        $reponseEmailInconnu = $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => 'peu-importe', 'email' => 'jamais-vu@cci.ci',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(422);

        $this->assertSame($reponseMauvaisToken->json('message'), $reponseEmailInconnu->json('message'));
        $this->assertSame(self::MESSAGE_INVALIDE, $reponseMauvaisToken->json('message'));
    }

    public function test_mot_de_passe_trop_faible_refuse_en_422_de_champ(): void
    {
        $user = $this->creerCandidat('cand@cci.ci');
        $token = Password::broker()->createToken($user);

        $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => $token, 'email' => 'cand@cci.ci',
            'password' => 'faible', 'password_confirmation' => 'faible',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_throttle_ip_6_par_minute(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
                'token' => 'x', 'email' => "x{$i}@cci.ci", 'password' => 'aaa', 'password_confirmation' => 'aaa',
            ])->assertStatus(422); // token invalide, mais PAS 429 — on teste juste le seuil
        }

        $this->fromSpa()->postJson('/api/mot-de-passe/reinitialiser', [
            'token' => 'x', 'email' => 'x7@cci.ci', 'password' => 'aaa', 'password_confirmation' => 'aaa',
        ])->assertStatus(429);
    }
}
