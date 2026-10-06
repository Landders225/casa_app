<?php

namespace Tests\Unit\Notifications;

use App\Notifications\InscriptionConfirmee;
use App\Notifications\Middleware\ToleranceSmtpTemporaire;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FauxJobNotification;
use Tests\TestCase;

class ToleranceSmtpTemporaireTest extends TestCase
{
    public function test_code_421_est_traite_comme_temporaire_et_relance_le_job(): void
    {
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 1);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            throw new TransportException('421 4.4.2 Message submission rate exceeded', 421);
        });

        $this->assertSame([30], $job->relances);
        $this->assertNull($job->echecDefinitif);
    }

    public function test_code_550_est_definitif_et_echoue_immediatement_sans_relance(): void
    {
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 1);
        $exception = new TransportException('550 adresse invalide', 550);

        (new ToleranceSmtpTemporaire)->handle($job, function () use ($exception) {
            throw $exception;
        });

        $this->assertSame($exception, $job->echecDefinitif);
        $this->assertSame([], $job->relances);
    }

    public function test_code_inconnu_est_traite_comme_temporaire_par_prudence(): void
    {
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 1);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            throw new TransportException('Connexion coupée', 0);
        });

        $this->assertSame([30], $job->relances);
        $this->assertNull($job->echecDefinitif);
    }

    public function test_le_delai_croit_avec_le_numero_de_tentative(): void
    {
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 3);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            throw new TransportException('421', 421);
        });

        $this->assertSame([120], $job->relances);
    }

    public function test_le_delai_est_plafonne(): void
    {
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 10);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            throw new TransportException('421', 421);
        });

        $this->assertSame([600], $job->relances);
    }

    public function test_une_exception_non_smtp_n_est_pas_interceptee(): void
    {
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 1);

        $this->expectException(RuntimeException::class);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            throw new RuntimeException('bug applicatif, sans rapport avec le SMTP');
        });
    }

    public function test_un_envoi_reussi_ne_declenche_ni_relance_ni_echec(): void
    {
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 1);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            // rien : envoi réussi
        });

        $this->assertSame([], $job->relances);
        $this->assertNull($job->echecDefinitif);
    }

    public function test_le_journal_d_une_relance_ne_contient_aucune_donnee_personnelle(): void
    {
        Log::spy();
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 2);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            throw new TransportException(
                '421 4.4.2 rejeté pour candidat-secret@example.test',
                421
            );
        });

        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            $this->assertSame(['notification', 'code_smtp', 'tentative', 'delai_secondes'], array_keys($context));
            $this->assertStringNotContainsString('candidat-secret', $message);
            $this->assertStringNotContainsString('candidat-secret', (string) json_encode($context));

            return true;
        });
    }

    public function test_le_journal_d_un_echec_definitif_ne_contient_aucune_donnee_personnelle(): void
    {
        Log::spy();
        $job = new FauxJobNotification(new InscriptionConfirmee, tentative: 1);

        (new ToleranceSmtpTemporaire)->handle($job, function () {
            throw new TransportException(
                '550 adresse rejetée : candidat-secret@example.test',
                550
            );
        });

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
            $this->assertSame(['notification', 'code_smtp', 'tentative'], array_keys($context));
            $this->assertStringNotContainsString('candidat-secret', $message);
            $this->assertStringNotContainsString('candidat-secret', (string) json_encode($context));

            return true;
        });
    }
}
