<?php

namespace Tests\Feature\Evaluateur;

use App\Models\Candidature;
use App\Models\User;
use App\Notifications\EntretienPlanifie;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lot 12b (ADR-33) — mail de convocation à l'entretien.
 *
 * Envoyé UNE SEULE FOIS, à la première planification (première saisie de
 * date/heure/lieu). Contenu : uniquement date/heure/lieu, jamais de score ou
 * de barème (résidu de risque documenté en ADR-33 point d : structurellement,
 * seuls des candidats éligibles et affectés peuvent recevoir ce mail).
 */
class EntretienPlanifieNotificationTest extends TestCase
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
        $this->evaluateur = $this->creerEvaluateur('eval-entretien@casa-demo.ci');
        $this->candidatUser = $this->creerCandidat('cand-entretien@casa-demo.ci');
        $this->candidature = $this->verrouillerDossier(
            $this->candidatureAffectee($this->candidatUser, $this->evaluateur),
        );
    }

    private function url(string $suffixe = ''): string
    {
        return "/api/evaluateur/candidatures/{$this->candidature->id}/entretien{$suffixe}";
    }

    public function test_mail_envoye_a_la_premiere_planification(): void
    {
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();

        Notification::assertSentTo($this->candidatUser, EntretienPlanifie::class, function ($n) {
            $corps = (string) $n->toMail($this->candidatUser)->render();
            $this->assertStringContainsString('06/07/2026', $corps);
            $this->assertStringContainsString('09:00', $corps);
            $this->assertStringContainsString('Le Plateau', $corps);
            foreach (['score', 'barème', 'note', 'grille', 'evalue'] as $mot) {
                $this->assertStringNotContainsStringIgnoringCase($mot, $corps, "« {$mot} » ne doit pas apparaître dans le mail");
            }

            return true;
        });
        Notification::assertSentTimes(EntretienPlanifie::class, 1);
    }

    public function test_aucun_2e_mail_a_une_replanification(): void
    {
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();

        // Replanification : heure/lieu changent — pas de 2e mail (point ouvert 🟠 hors Lot 12b).
        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-07', 'heure' => '14:00', 'lieu' => '2 Plateaux Vallons',
        ])->assertOk();

        Notification::assertSentTimes(EntretienPlanifie::class, 1);
    }

    public function test_aucun_mail_a_la_saisie_de_presence_notes_sans_replanification(): void
    {
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();

        $this->actingAs($this->evaluateur)->putJson($this->url(), ['presence' => 'present'])->assertOk();

        Notification::assertSentTimes(EntretienPlanifie::class, 1);
    }

    public function test_notification_est_mise_en_file_pas_envoyee_en_sync(): void
    {
        Notification::fake();

        $this->actingAs($this->evaluateur)->putJson($this->url(), [
            'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
        ])->assertOk();

        Notification::assertSentTo($this->candidatUser, EntretienPlanifie::class, function ($n) {
            return in_array(ShouldQueue::class, class_implements($n), true);
        });
    }
}
