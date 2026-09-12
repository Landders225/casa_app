<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\User;
use App\Notifications\EntretienReplanifie;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lot 15b — mail de modification d'un entretien déjà planifié.
 *
 * Se déclenche UNIQUEMENT quand au moins une des 3 valeurs date/heure/lieu
 * change réellement sur un entretien qui EXISTAIT déjà (comparaison
 * normalisée — cf. `EntretienController::update`). Contenu : uniquement
 * date/heure/lieu, jamais de score ou de barème (même garde-fou que
 * `EntretienPlanifie`, ADR-33).
 */
class EntretienReplanifieNotificationTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evaluateur;

    private User $candidatUser;

    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->evaluateur = $this->creerEvaluateur('eval-replanif@casa-demo.ci');
        $this->candidatUser = $this->creerCandidat('cand-replanif@casa-demo.ci');
        $this->candidature = $this->verrouillerDossier(
            $this->candidatureAffectee($this->candidatUser, $this->evaluateur),
        );
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/evaluateur/candidatures/{$this->candidature->id}/entretien{$suffixe}";
    }

    private function planifier(string $date = '2026-07-06', string $heure = '09:00', string $lieu = 'Le Plateau'): void
    {
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => $date, 'heure' => $heure, 'lieu' => $lieu,
        ])->assertOk();
    }

    public function test_mail_envoye_si_la_date_change(): void
    {
        $this->planifier();
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-10', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();

        Notification::assertSentTo($this->candidatUser, EntretienReplanifie::class, function ($n) {
            $corps = (string) $n->toMail($this->candidatUser)->render();
            $this->assertStringContainsString('10/07/2026', $corps);
            $this->assertStringContainsString('09:00', $corps);
            $this->assertStringContainsString('Le Plateau', $corps);
            $this->assertStringContainsString('reprogrammé', mb_strtolower($corps));
            foreach (['score', 'barème', 'note', 'grille', 'evalue'] as $mot) {
                $this->assertStringNotContainsStringIgnoringCase($mot, $corps, "« {$mot} » ne doit pas apparaître dans le mail");
            }

            return true;
        });
        Notification::assertSentTimes(EntretienReplanifie::class, 1);
    }

    public function test_mail_envoye_si_seule_l_heure_change(): void
    {
        $this->planifier();
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['heure' => '15:00'])->assertOk();

        Notification::assertSentTimes(EntretienReplanifie::class, 1);
    }

    public function test_mail_envoye_si_seul_le_lieu_change(): void
    {
        $this->planifier();
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['lieu' => '2 Plateaux Vallons'])->assertOk();

        Notification::assertSentTimes(EntretienReplanifie::class, 1);
    }

    public function test_aucun_mail_si_date_heure_lieu_renvoyes_identiques(): void
    {
        $this->planifier();
        Notification::fake();

        // Même valeurs, envoyées à nouveau — aucun changement réel.
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();

        Notification::assertSentTimes(EntretienReplanifie::class, 0);
    }

    public function test_aucun_mail_sur_presence_observation_notes_seules(): void
    {
        $this->planifier();
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'presence' => 'present', 'observation' => 'RAS',
        ])->assertOk();

        Notification::assertSentTimes(EntretienReplanifie::class, 0);
    }

    public function test_notification_est_mise_en_file_pas_envoyee_en_sync(): void
    {
        $this->planifier();
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['heure' => '16:00'])->assertOk();

        Notification::assertSentTo($this->candidatUser, EntretienReplanifie::class, function ($n) {
            return in_array(ShouldQueue::class, class_implements($n), true);
        });
    }

    public function test_canal_database_sans_score_ni_barème(): void
    {
        $this->planifier();
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['lieu' => '2 Plateaux Vallons'])->assertOk();

        Notification::assertSentTo($this->candidatUser, EntretienReplanifie::class, function ($n) {
            $donnees = $n->toDatabase($this->candidatUser);
            $this->assertSame('entretien', $donnees['categorie']);
            $this->assertStringContainsString('2 Plateaux Vallons', $donnees['message']);
            foreach (['score', 'barème', 'note', 'grille'] as $mot) {
                $this->assertStringNotContainsStringIgnoringCase($mot, (string) json_encode($donnees), "« {$mot} » ne doit pas apparaître");
            }

            return true;
        });
    }
}
