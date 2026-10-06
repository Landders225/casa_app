<?php

namespace Tests\Unit\Notifications;

use App\Notifications\CandidatureSoumise;
use App\Notifications\EntretienPlanifie;
use App\Notifications\EntretienReplanifie;
use App\Notifications\InscriptionConfirmee;
use App\Notifications\Middleware\EtalementEnvoiMail;
use App\Notifications\Middleware\ToleranceSmtpTemporaire;
use App\Notifications\MotDePasseModifie;
use App\Notifications\ReinitialisationMotDePasse;
use App\Notifications\ResultatsPublies;
use ReflectionClass;
use Tests\TestCase;

/**
 * Lot 18 (ADR-35) — câblage du trait `EnvoiMailResilient` lui-même (pas la
 * logique de classification SMTP, couverte par `ToleranceSmtpTemporaireTest`).
 * Lot 18bis (ADR-36) — files prioritaires + coupe-circuit informatif,
 * classification des 7 classes.
 */
class EnvoiMailResilientTest extends TestCase
{
    /** @var array<int, string> */
    private const INFORMATIVES = [
        InscriptionConfirmee::class,
        CandidatureSoumise::class,
        EntretienPlanifie::class,
        EntretienReplanifie::class,
        ResultatsPublies::class,
    ];

    /** @var array<int, string> */
    private const CRITIQUES = [
        ReinitialisationMotDePasse::class,
        MotDePasseModifie::class,
    ];

    /** Construit sans appeler le constructeur (certains exigent des args sans rapport avec via()/viaQueues()). */
    private function construireSansConstructeur(string $classe): object
    {
        return (new ReflectionClass($classe))->newInstanceWithoutConstructor();
    }

    public function test_les_5_notifications_informatives_vont_sur_la_file_mail_information(): void
    {
        foreach (self::INFORMATIVES as $classe) {
            $this->assertSame(
                ['mail' => 'mail-information'],
                $this->construireSansConstructeur($classe)->viaQueues(),
                "{$classe} doit router mail vers mail-information"
            );
        }
    }

    public function test_les_2_notifications_critiques_vont_sur_la_file_mail_critique(): void
    {
        foreach (self::CRITIQUES as $classe) {
            $this->assertSame(
                ['mail' => 'mail-critique'],
                $this->construireSansConstructeur($classe)->viaQueues(),
                "{$classe} doit router mail vers mail-critique"
            );
        }
    }

    public function test_une_notification_informative_perd_le_canal_mail_si_desactivee(): void
    {
        config(['casa.mail_notifications_informatives' => false]);

        foreach (self::INFORMATIVES as $classe) {
            $this->assertSame(
                ['database'],
                $this->construireSansConstructeur($classe)->via((object) ['id' => 1]),
                "{$classe} doit perdre le canal mail (pas la base) quand le réglage est désactivé"
            );
        }
    }

    public function test_une_notification_informative_garde_les_2_canaux_par_defaut(): void
    {
        config(['casa.mail_notifications_informatives' => true]);

        foreach (self::INFORMATIVES as $classe) {
            $this->assertSame(['mail', 'database'], $this->construireSansConstructeur($classe)->via((object) ['id' => 1]));
        }
    }

    public function test_les_notifications_critiques_ignorent_totalement_le_reglage(): void
    {
        config(['casa.mail_notifications_informatives' => false]);

        foreach (self::CRITIQUES as $classe) {
            $this->assertSame(
                ['mail'],
                $this->construireSansConstructeur($classe)->via((object) ['id' => 1]),
                "{$classe} ne doit jamais être coupée par ce réglage"
            );
        }
    }

    public function test_le_canal_mail_recoit_l_etalement_et_la_tolerance_smtp(): void
    {
        $notification = new InscriptionConfirmee;
        $notifiable = (object) ['id' => 1];

        $middlewares = $notification->middleware($notifiable, 'mail');

        $this->assertCount(2, $middlewares);
        $this->assertInstanceOf(EtalementEnvoiMail::class, $middlewares[0]);
        $this->assertInstanceOf(ToleranceSmtpTemporaire::class, $middlewares[1]);
    }

    public function test_le_canal_database_ne_recoit_aucun_middleware(): void
    {
        $notification = new InscriptionConfirmee;
        $notifiable = (object) ['id' => 1];

        $this->assertSame([], $notification->middleware($notifiable, 'database'));
    }

    public function test_retry_until_est_fixe_a_six_heures(): void
    {
        $notification = new InscriptionConfirmee;

        $this->assertEqualsWithDelta(
            now()->addHours(6)->getTimestamp(),
            $notification->retryUntil()->getTimestamp(),
            5
        );
    }

    public function test_le_plancher_de_backoff_est_de_30_secondes(): void
    {
        $notification = new InscriptionConfirmee;

        $this->assertSame(30, $notification->backoff());
    }
}
