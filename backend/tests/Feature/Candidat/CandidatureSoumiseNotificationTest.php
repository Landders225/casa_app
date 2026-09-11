<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidature;
use App\Models\User;
use App\Notifications\CandidatureSoumise;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lot 12b (ADR-33) — mail de confirmation de soumission.
 *
 * PREUVE MAÎTRESSE : le mail est STRICTEMENT IDENTIQUE (sujet + corps) que le
 * candidat soit éligible ou non, à l'exception du seul `numero_dossier`. Même
 * garantie que la réponse HTTP (SoumissionAntiFuiteTest), transposée au canal
 * e-mail — c'est exactement le risque que l'ajout de ce mail aurait pu
 * réintroduire.
 */
class CandidatureSoumiseNotificationTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
    }

    /**
     * @param  array<string,mixed>  $reponses
     */
    private function candidatureComplete(User $user, array $reponses = []): Candidature
    {
        $id = $this->actingAs($user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        return $this->rendreCandidatureComplete(Candidature::findOrFail($id), $reponses);
    }

    public function test_mail_octet_pour_octet_identique_eligible_vs_non_eligible(): void
    {
        Notification::fake();

        $alice = $this->creerCandidat('alice-soumission@casa-demo.ci');
        $bob = $this->creerCandidat('bob-soumission@casa-demo.ci');

        $ca = $this->candidatureComplete($alice);
        $cb = $this->candidatureComplete($bob, ['di01_disponible_lun_ven' => 'non']); // 1 critère -> non éligible

        $this->actingAs($alice)->postJson("/api/candidatures/{$ca->id}/soumettre")->assertOk();
        $this->actingAs($bob)->postJson("/api/candidatures/{$cb->id}/soumettre")->assertOk();

        // En base, l'éligibilité diffère bien (sinon le test ne prouverait rien).
        $this->assertSame('soumis', $ca->fresh()->statut_interne);
        $this->assertSame('non_eligible', $cb->fresh()->statut_interne);

        $mailA = null;
        $mailB = null;
        Notification::assertSentTo($alice, CandidatureSoumise::class, function ($n) use (&$mailA, $alice) {
            $mailA = $n->toMail($alice);

            return true;
        });
        Notification::assertSentTo($bob, CandidatureSoumise::class, function ($n) use (&$mailB, $bob) {
            $mailB = $n->toMail($bob);

            return true;
        });

        // Sujet strictement identique.
        $this->assertSame($mailA->subject, $mailB->subject);

        // Corps rendu identique après normalisation du SEUL champ qui doit varier.
        $rendu = fn ($mail) => str_replace([$ca->numero_dossier, $cb->numero_dossier], '<NUMERO>', $mail->render());
        $this->assertSame($rendu($mailA), $rendu($mailB));

        // Chacun reçoit bien SON propre numéro (pas celui de l'autre).
        $this->assertStringContainsString($ca->numero_dossier, $mailA->render());
        $this->assertStringContainsString($cb->numero_dossier, $mailB->render());
    }

    public function test_liste_noire_aucun_terme_de_statut_dans_le_mail(): void
    {
        Notification::fake();

        $bob = $this->creerCandidat('bob-listenoire@casa-demo.ci');
        $cb = $this->candidatureComplete($bob, ['di01_disponible_lun_ven' => 'non']);
        $this->actingAs($bob)->postJson("/api/candidatures/{$cb->id}/soumettre")->assertOk();

        Notification::assertSentTo($bob, CandidatureSoumise::class, function ($n) use ($bob) {
            $corps = $n->toMail($bob)->render();
            foreach (['eligib', 'non_eligible', 'statut_interne', 'critere', 'eliminatoire', 'DI.01', 'rejet', 'refus'] as $mot) {
                $this->assertStringNotContainsStringIgnoringCase($mot, $corps, "« {$mot} » ne doit pas apparaître dans le mail");
            }

            return true;
        });
    }

    public function test_notification_est_mise_en_file_pas_envoyee_en_sync(): void
    {
        Notification::fake();

        $user = $this->creerCandidat('queue-soumission@casa-demo.ci');
        $c = $this->candidatureComplete($user);
        $this->actingAs($user)->postJson("/api/candidatures/{$c->id}/soumettre")->assertOk();

        Notification::assertSentTo($user, CandidatureSoumise::class, function ($n) {
            return in_array(ShouldQueue::class, class_implements($n), true);
        });
    }

    public function test_aucun_mail_a_la_re_soumission_deja_soumise(): void
    {
        Notification::fake();

        $user = $this->creerCandidat('resoumission@casa-demo.ci');
        $c = $this->candidatureComplete($user);
        $this->actingAs($user)->postJson("/api/candidatures/{$c->id}/soumettre")->assertOk();
        $this->actingAs($user)->postJson("/api/candidatures/{$c->id}/soumettre")->assertStatus(409);

        Notification::assertSentTimes(CandidatureSoumise::class, 1);
    }
}
