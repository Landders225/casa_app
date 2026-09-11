<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\User;
use App\Notifications\ResultatsPublies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Lot 12b (ADR-33) — mail « les résultats sont disponibles ».
 *
 * PREUVE MAÎTRESSE (la plus critique des 4 — ~240 candidats en production) :
 * le mail est STRICTEMENT IDENTIQUE (sujet + corps, ZÉRO exception) quelle que
 * soit la décision (`retenu` / `liste_attente` / `non_retenu` / `indisponible`).
 * Complétée par la preuve de non-blocage (envoi en jobs indépendants, jamais
 * synchrone) et d'isolation des échecs (un job corrompu n'empêche pas les
 * autres, cf. `PublicationController::publier`).
 */
class ResultatsPubliesNotificationTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private Campagne $campagne;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin-notif@casa-demo.ci');
    }

    private function url(): string
    {
        return "/api/admin/campagnes/{$this->campagne->id}/publier";
    }

    /** Candidature DÉCIDÉE, sans passer par le calcul de classement (Lot 5a déjà testé ailleurs). */
    private function candidatAvecDecision(string $decision): User
    {
        $user = $this->creerCandidat('candidat-'.Str::random(10).'@casa-demo.ci');

        $candidature = Candidature::create([
            'candidat_id' => $user->candidat->id,
            'campagne_id' => $this->campagne->id,
            'filiere_id' => $this->idFiliere('cuisine'),
            'numero_dossier' => sprintf('CASA-2026-%06d', ++$this->seq),
            'statut_interne' => 'evalue',
            'cqp_confirme' => true,
        ]);

        DecisionCandidature::create([
            'candidature_id' => $candidature->id,
            'rang' => $decision === 'indisponible' ? null : 1,
            'decision' => $decision,
            'motif_interne' => 'Motif interne — jamais communiqué',
            'motif_communicable' => 'Motif communicable de test',
        ]);

        return $user;
    }

    public function test_mail_strictement_identique_pour_les_4_decisions(): void
    {
        Notification::fake();

        $retenu = $this->candidatAvecDecision('retenu');
        $listeAttente = $this->candidatAvecDecision('liste_attente');
        $nonRetenu = $this->candidatAvecDecision('non_retenu');
        $indisponible = $this->candidatAvecDecision('indisponible');

        $this->actingAs($this->admin)->postJson($this->url())->assertOk();

        $rendus = [];
        $sujets = [];
        foreach ([$retenu, $listeAttente, $nonRetenu, $indisponible] as $u) {
            Notification::assertSentTo($u, ResultatsPublies::class, function ($n) use (&$rendus, &$sujets, $u) {
                $mail = $n->toMail($u);
                $sujets[] = $mail->subject;
                $rendus[] = (string) $mail->render();

                return true;
            });
        }

        // AUCUNE exception cette fois (contrairement au mail soumission) :
        // 4 décisions différentes -> 1 seul sujet distinct, 1 seul corps distinct.
        $this->assertCount(1, array_unique($sujets), 'Le sujet doit être identique pour toutes les décisions.');
        $this->assertCount(1, array_unique($rendus), 'Le corps doit être identique pour toutes les décisions.');
    }

    public function test_liste_noire_aucune_decision_ni_score_ni_rang_dans_le_mail(): void
    {
        Notification::fake();

        $u = $this->candidatAvecDecision('non_retenu');
        $this->actingAs($this->admin)->postJson($this->url())->assertOk();

        Notification::assertSentTo($u, ResultatsPublies::class, function ($n) use ($u) {
            $corps = (string) $n->toMail($u)->render();
            foreach (['retenu', 'liste_attente', 'non_retenu', 'indisponible', 'rang', 'score', 'motif', 'décision', 'felicit'] as $mot) {
                $this->assertStringNotContainsStringIgnoringCase($mot, $corps, "« {$mot} » ne doit pas apparaître dans le mail");
            }

            return true;
        });
    }

    public function test_seuls_les_candidats_avec_decision_sont_notifies(): void
    {
        Notification::fake();

        $avecDecision = $this->candidatAvecDecision('retenu');
        $sansDecision = $this->creerCandidat('sans-decision@casa-demo.ci'); // aucune candidature décidée

        $this->candidatAvecDecision('non_retenu'); // pour satisfaire le garde-fou "au moins une décision"

        $this->actingAs($this->admin)->postJson($this->url())->assertOk();

        Notification::assertSentTo($avecDecision, ResultatsPublies::class);
        Notification::assertNotSentTo($sansDecision, ResultatsPublies::class);
    }

    public function test_un_seul_mail_par_candidat_meme_avec_plusieurs_candidatures_decidees(): void
    {
        Notification::fake();

        $user = $this->creerCandidat('double-candidature@casa-demo.ci');

        foreach (['cuisine', 'buanderie'] as $filiere) {
            $candidature = Candidature::create([
                'candidat_id' => $user->candidat->id,
                'campagne_id' => $this->campagne->id,
                'filiere_id' => $this->idFiliere($filiere),
                'numero_dossier' => sprintf('CASA-2026-%06d', ++$this->seq),
                'statut_interne' => 'evalue',
                'cqp_confirme' => true,
            ]);
            DecisionCandidature::create([
                'candidature_id' => $candidature->id,
                'rang' => 1,
                'decision' => 'retenu',
                'motif_interne' => null,
                'motif_communicable' => null,
            ]);
        }

        $this->actingAs($this->admin)->postJson($this->url())->assertOk();

        Notification::assertSentTimes(ResultatsPublies::class, 1);
    }

    public function test_publication_met_en_file_un_job_independant_par_destinataire_sans_envoi_synchrone(): void
    {
        config(['queue.default' => 'database']);

        $destinataires = [];
        foreach (['retenu', 'liste_attente', 'non_retenu', 'indisponible'] as $decision) {
            $destinataires[] = $this->candidatAvecDecision($decision);
        }
        // Volume plus réaliste : une vingtaine de décisions supplémentaires.
        for ($i = 0; $i < 20; $i++) {
            $destinataires[] = $this->candidatAvecDecision('non_retenu');
        }

        $this->assertDatabaseCount('jobs', 0);

        $debut = microtime(true);
        $this->actingAs($this->admin)->postJson($this->url())->assertOk();
        $duree = microtime(true) - $debut;

        // 24 destinataires x 2 canaux (mail + database, Lot 12c) -> 1 job
        // `SendQueuedNotifications` indépendant PAR CANAL PAR DESTINATAIRE
        // (`NotificationSender::queueNotification` boucle sur `via()` et
        // dispatche un job par itération) — AUCUN envoi SMTP exécuté ici (queue
        // database, jamais traitée).
        $this->assertDatabaseCount('jobs', count($destinataires) * 2);
        $this->assertLessThan(5.0, $duree, 'La publication doit rester synchrone et rapide : elle ne doit pas attendre les envois.');
    }

    public function test_echec_d_un_job_isole_n_empeche_pas_le_traitement_des_autres(): void
    {
        config(['queue.default' => 'database']);

        $this->candidatAvecDecision('retenu');
        $this->candidatAvecDecision('non_retenu');
        $this->candidatAvecDecision('liste_attente');

        $this->actingAs($this->admin)->postJson($this->url())->assertOk();
        // 3 destinataires x 2 canaux (mail + database, Lot 12c).
        $this->assertDatabaseCount('jobs', 6);

        // Corrompt le PREMIER job en base (commande sérialisée illisible) : simule
        // un échec sans dépendre du comportement du transport mail. `pop()` reste
        // capable de décoder l'enveloppe JSON (donc les AUTRES jobs se traitent
        // normalement) ; seule la reconstruction de LA commande corrompue échoue.
        $premier = DB::table('jobs')->orderBy('id')->first();
        $payload = json_decode($premier->payload, true);
        $payload['data']['command'] = 'CORROMPU:'.$payload['data']['command'];
        DB::table('jobs')->where('id', $premier->id)->update(['payload' => json_encode($payload)]);

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        // Le job corrompu échoue, SEUL, et atterrit en `failed_jobs`.
        $this->assertDatabaseCount('failed_jobs', 1);
        // Les 2 AUTRES jobs, eux, ont été traités normalement — la file est vide,
        // AUCUNE cascade d'échec ne les a bloqués.
        $this->assertDatabaseCount('jobs', 0);
    }
}
