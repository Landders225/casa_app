<?php

namespace Tests\Unit\Notifications;

use App\Notifications\InscriptionConfirmee;
use App\Notifications\Middleware\EtalementEnvoiMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FauxJobNotification;
use Tests\TestCase;

/**
 * Lot 18ter (ADR-37) — `EtalementEnvoiMail` : espacement RÉEL (un envoi
 * toutes les `60 / mail_max_per_minute` secondes), pas un simple compteur
 * par fenêtre qui laisserait passer une rafale. Logique testée ici de façon
 * déterministe (`Carbon::setTestNow`) ; la preuve avec un VRAI serveur SMTP
 * qui horodate (Mailpit, gaps mesurés ~6s pour 10/min) est documentée dans
 * docs/DEPLOIEMENT.md §9.4bis — non reproduite ici (nécessite un temps réel
 * écoulé, inadapté à une suite automatisée rapide).
 */
class EtalementEnvoiMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-01-01 00:00:00');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notification(string $id): InscriptionConfirmee
    {
        $n = new InscriptionConfirmee;
        $n->id = $id;

        return $n;
    }

    public function test_le_premier_job_part_immediatement_sans_relance(): void
    {
        config(['casa.mail_max_per_minute' => 10]); // intervalle = 6s
        $job = new FauxJobNotification($this->notification('job-1'));
        $envoye = false;

        (new EtalementEnvoiMail)->handle($job, function () use (&$envoye) {
            $envoye = true;
        });

        $this->assertTrue($envoye);
        $this->assertSame([], $job->relances);
    }

    public function test_un_second_job_immediat_est_retarde_de_l_intervalle_exact(): void
    {
        config(['casa.mail_max_per_minute' => 10]); // intervalle = 6s

        (new EtalementEnvoiMail)->handle(new FauxJobNotification($this->notification('job-1')), fn () => null);

        $job2 = new FauxJobNotification($this->notification('job-2'));
        $envoye = false;
        (new EtalementEnvoiMail)->handle($job2, function () use (&$envoye) {
            $envoye = true;
        });

        $this->assertFalse($envoye);
        $this->assertSame([6], $job2->relances);
    }

    public function test_une_relance_du_meme_job_relit_son_creneau_sans_en_reserver_un_nouveau(): void
    {
        // ⚠️ Preuve directe du piège corrigé : une PROPRIÉTÉ D'INSTANCE ne
        // survit pas à un `release()` réel (Illuminate\Queue\DatabaseQueue
        // ::release() repousse le payload brut d'ORIGINE, jamais l'objet
        // muté) — la mémorisation doit passer par le cache, PAS par `$this`.
        // On simule ici une relance par une NOUVELLE instance de middleware
        // (exactement ce qu'un `release()` réel produit : l'objet est
        // désérialisé à nouveau).
        config(['casa.mail_max_per_minute' => 10]); // intervalle = 6s

        (new EtalementEnvoiMail)->handle(new FauxJobNotification($this->notification('job-1')), fn () => null);

        $job2 = new FauxJobNotification($this->notification('job-2'));
        (new EtalementEnvoiMail)->handle($job2, fn () => null); // relance 1, créneau réservé = t0+6

        // Relance 2 : NOUVELLE instance de middleware (comme un vrai retry),
        // toujours avant l'heure — doit RELIRE le même créneau (t0+6), pas
        // en réserver un autre plus loin.
        $envoye = false;
        (new EtalementEnvoiMail)->handle($job2, function () use (&$envoye) {
            $envoye = true;
        });

        $this->assertFalse($envoye);
        $this->assertSame([6, 6], $job2->relances, 'le créneau relu doit rester identique (6s), pas en repousser un nouveau');

        // Le temps avance jusqu'au créneau réservé : cette fois, ça part.
        Carbon::setTestNow(Carbon::now()->addSeconds(6));
        $envoye = false;
        (new EtalementEnvoiMail)->handle($job2, function () use (&$envoye) {
            $envoye = true;
        });
        $this->assertTrue($envoye);
    }

    public function test_la_cadence_est_partagee_entre_plusieurs_jobs_consecutifs(): void
    {
        config(['casa.mail_max_per_minute' => 10]); // intervalle = 6s
        $delais = [];

        foreach (['job-1', 'job-2', 'job-3', 'job-4'] as $id) {
            $job = new FauxJobNotification($this->notification($id));
            (new EtalementEnvoiMail)->handle($job, fn () => null);
            $delais[] = $job->relances[0] ?? 0;
        }

        // 1er immédiat (0), puis +6s, +12s, +18s : cadence STRICTEMENT
        // régulière, jamais de rafale.
        $this->assertSame([0, 6, 12, 18], $delais);
    }

    public function test_l_intervalle_suit_mail_max_per_minute(): void
    {
        config(['casa.mail_max_per_minute' => 30]); // intervalle = 2s

        (new EtalementEnvoiMail)->handle(new FauxJobNotification($this->notification('job-1')), fn () => null);

        $job2 = new FauxJobNotification($this->notification('job-2'));
        (new EtalementEnvoiMail)->handle($job2, fn () => null);

        $this->assertSame([2], $job2->relances);
    }
}
