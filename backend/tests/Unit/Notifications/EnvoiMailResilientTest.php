<?php

namespace Tests\Unit\Notifications;

use App\Notifications\InscriptionConfirmee;
use App\Notifications\Middleware\ToleranceSmtpTemporaire;
use Illuminate\Queue\Middleware\RateLimited;
use Tests\TestCase;

/**
 * Lot 18 (ADR-35) — câblage du trait `EnvoiMailResilient` lui-même (pas la
 * logique de classification SMTP, couverte par `ToleranceSmtpTemporaireTest`).
 */
class EnvoiMailResilientTest extends TestCase
{
    public function test_le_canal_mail_recoit_le_limiteur_de_debit_et_la_tolerance_smtp(): void
    {
        $notification = new InscriptionConfirmee;
        $notifiable = (object) ['id' => 1];

        $middlewares = $notification->middleware($notifiable, 'mail');

        $this->assertCount(2, $middlewares);
        $this->assertInstanceOf(RateLimited::class, $middlewares[0]);
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
