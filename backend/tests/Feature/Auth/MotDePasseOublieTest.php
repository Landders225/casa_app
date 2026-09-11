<?php

namespace Tests\Feature\Auth;

use App\Notifications\ReinitialisationMotDePasse;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Candidat\CreeContexteCandidature;
use Tests\TestCase;

/**
 * Lot 13 — `POST /api/mot-de-passe/oubli` (ADR-32). Le POINT CENTRAL du lot :
 * la réponse ne doit JAMAIS révéler si un compte existe pour l'adresse fournie.
 */
class MotDePasseOublieTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    /**
     * LA preuve d'indiscernabilité : comparaison OCTET À OCTET (statut + corps)
     * entre un e-mail enregistré et un e-mail inconnu.
     */
    public function test_reponse_octet_pour_octet_identique_email_existant_ou_inconnu(): void
    {
        Notification::fake();
        $this->creerCandidat('existe@cci.ci');

        $reponseExistant = $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'existe@cci.ci']);
        $reponseInconnu = $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'jamais-vu@cci.ci']);

        $this->assertSame(200, $reponseExistant->getStatusCode());
        $this->assertSame($reponseExistant->getStatusCode(), $reponseInconnu->getStatusCode());
        $this->assertSame($reponseExistant->getContent(), $reponseInconnu->getContent());
        $this->assertSame(
            'Si un compte existe pour cette adresse, un lien de réinitialisation vient d\'être envoyé.',
            $reponseExistant->json('message'),
        );
    }

    public function test_email_existant_recoit_bien_la_notification_email_inconnu_non(): void
    {
        Notification::fake();
        $user = $this->creerCandidat('existe@cci.ci');

        $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'existe@cci.ci']);
        $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'jamais-vu@cci.ci']);

        Notification::assertSentTo($user, ReinitialisationMotDePasse::class);
        Notification::assertSentTimes(ReinitialisationMotDePasse::class, 1); // pas de 2e envoi pour l'inconnu

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'existe@cci.ci']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'jamais-vu@cci.ci']);
    }

    public function test_la_notification_est_mise_en_file_pas_envoyee_en_sync(): void
    {
        Notification::fake();
        $user = $this->creerCandidat('existe@cci.ci');

        $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'existe@cci.ci']);

        Notification::assertSentTo($user, ReinitialisationMotDePasse::class, function ($notification) {
            return in_array(ShouldQueue::class, class_implements($notification), true);
        });
    }

    public function test_deux_demandes_rapprochees_meme_email_toujours_200_generique_sans_2e_envoi(): void
    {
        Notification::fake();
        $this->creerCandidat('existe@cci.ci');

        $premiere = $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'existe@cci.ci']);
        $seconde = $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'existe@cci.ci']);

        $premiere->assertOk();
        $seconde->assertOk(); // le throttle NATIF du broker (60s) est absorbé, jamais exposé
        $this->assertSame($premiere->getContent(), $seconde->getContent());

        // Le broker interne (60s/email) empêche le 2e envoi — la réponse HTTP, elle, ne change pas.
        Notification::assertSentTimes(ReinitialisationMotDePasse::class, 1);
    }

    public function test_email_mal_forme_est_une_422_de_champ_pas_une_fuite_de_compte(): void
    {
        Notification::fake();

        $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'pas-un-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    public function test_throttle_ip_6_par_minute(): void
    {
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => "x{$i}@cci.ci"])->assertOk();
        }

        $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'x7@cci.ci'])->assertStatus(429);
    }

    public function test_endpoint_public_aucune_authentification_requise(): void
    {
        Notification::fake();

        $this->fromSpa()->postJson('/api/mot-de-passe/oubli', ['email' => 'peu-importe@cci.ci'])->assertOk();
    }

    /**
     * Régression : la vue de l'e-mail est un texte brut, pas du HTML — `{{ }}`
     * échappe quand même (Blade ne distingue pas), ce qui transformait le « & »
     * entre `token=` et `email=` en `&amp;` et coupait le paramètre `email` du
     * lien reçu. La vue doit utiliser `{!! !!}` pour ce lien précis.
     */
    public function test_le_lien_de_l_email_n_est_pas_html_echappe(): void
    {
        $rendu = view('emails.reinitialisation-mot-de-passe', [
            'appName' => 'CASA',
            'lien' => config('app.url').'/mot-de-passe/nouveau?token=un-token&email=cand%40cci.ci',
            'expireDansMinutes' => 60,
        ])->render();

        $this->assertStringNotContainsString('&amp;', $rendu);
        $this->assertStringContainsString('token=un-token&email=cand%40cci.ci', $rendu);
    }
}
