<?php

namespace Tests\Feature\Console;

use App\Mail\TestEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lot 12a — `casa:test-email` + infra de file d'attente (ADR-31).
 *
 * Tout est en mode CAPTURE (`Mail::fake()`) — aucun envoi réel, aucun SMTP
 * contacté. Ce qui est verrouillé :
 *  - défaut = envoi SYNCHRONE (pas la file) ;
 *  - `--queue` = mise en file (teste la chaîne worker) ;
 *  - e-mail invalide → aucun envoi, exit 2 ;
 *  - le contenu de l'e-mail ne fuit AUCUN secret de config ;
 *  - les tables `jobs` / `failed_jobs` existent après `migrate`.
 */
class TestEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_envoi_synchrone_par_defaut(): void
    {
        Mail::fake();

        $this->artisan('casa:test-email', ['destinataire' => 'admin@exemple.ci'])
            ->assertSuccessful();

        Mail::assertSent(TestEmail::class, fn (TestEmail $m) => $m->hasTo('admin@exemple.ci'));
        Mail::assertNothingQueued();
    }

    public function test_option_queue_met_l_email_dans_la_file(): void
    {
        Mail::fake();

        $this->artisan('casa:test-email', ['destinataire' => 'admin@exemple.ci', '--queue' => true])
            ->assertSuccessful();

        Mail::assertQueued(TestEmail::class, fn (TestEmail $m) => $m->hasTo('admin@exemple.ci'));
        Mail::assertNotSent(TestEmail::class);
    }

    public function test_un_email_invalide_ne_declenche_aucun_envoi(): void
    {
        Mail::fake();

        $this->artisan('casa:test-email', ['destinataire' => 'pas-un-email'])
            ->assertExitCode(2);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_avertit_quand_le_transport_est_log(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();

        $this->artisan('casa:test-email', ['destinataire' => 'admin@exemple.ci'])
            ->expectsOutputToContain('ÉCRIT DANS LES LOGS')
            ->assertSuccessful();
    }

    public function test_le_contenu_de_l_email_ne_fuit_aucun_secret(): void
    {
        // Un mot de passe SMTP fictif est chargé dans la config résolue.
        config(['mail.mailers.smtp.password' => 'MOT-DE-PASSE-SMTP-FICTIF-XYZ']);
        config(['mail.mailers.smtp.host' => 'smtp.fournisseur-fictif.test']);

        $rendu = (new TestEmail('smtp', now()->toDateTimeString()))->render();

        $this->assertStringContainsString('e-mail de test', mb_strtolower($rendu));
        $this->assertStringContainsString(config('app.name'), $rendu);

        foreach (['MOT-DE-PASSE-SMTP-FICTIF-XYZ', 'smtp.fournisseur-fictif.test', config('app.key')] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $rendu);
        }
    }

    public function test_les_tables_de_file_existent_apres_migrate(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }
}
