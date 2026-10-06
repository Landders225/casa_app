<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\InscriptionConfirmee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TransportSmtpSimule;
use Tests\TestCase;

/**
 * Lot 18 (ADR-35) — résilience de l'envoi SMTP : un 421 (ou un code
 * temporaire inconnu) ne doit JAMAIS faire tomber une notification dans
 * `failed_jobs`, un 5xx DOIT y tomber immédiatement, et une rafale de 200
 * notifications doit se vider SANS aucun échec définitif.
 *
 * Utilise `Tests\Support\TransportSmtpSimule` (faux transport EN MÉMOIRE,
 * déterministe) + `Carbon::setTestNow()` pour ne pas attendre réellement les
 * délais de backoff/limiteur — rapide, adapté à la suite automatisée.
 *
 * ⚠️ Ces tests NE remplacent PAS la preuve « vrai worker » exigée
 * séparément (retryUntil() qui neutralise --tries=3 à travers des process
 * `artisan queue:work --once` RÉELLEMENT séparés, avec un VRAI serveur SMTP
 * jetable) — voir docs/DEPLOIEMENT.md §9.4 pour cette preuve-là.
 */
class ResilienceSmtpTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activerTransportSimule(array $codes): void
    {
        TransportSmtpSimule::programmer($codes);
        Mail::extend('smtp-simule', fn () => new TransportSmtpSimule);
        config([
            'mail.mailers.smtp-simule' => ['transport' => 'smtp-simule'],
            'mail.default' => 'smtp-simule',
            'queue.default' => 'database',
        ]);
    }

    public function test_rejet_421_est_retente_puis_reussit_sans_jamais_echouer(): void
    {
        $this->activerTransportSimule([421, 0]);
        $utilisateur = User::factory()->create();

        $utilisateur->notify(new InscriptionConfirmee);

        $this->assertDatabaseCount('jobs', 2); // mail + database (Lot 12c)
        $this->assertDatabaseCount('failed_jobs', 0);

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 10]);

        // Le canal database a réussi tout de suite ; le job mail a été REJETÉ
        // (421) -> remis en file avec délai (release()), donc toujours
        // présent, PAS dans failed_jobs malgré --tries=3 déjà consommé une
        // fois (retryUntil() prend le relais, cf. EnvoiMailResilient).
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 1);

        // Avance le temps au-delà du délai de release() (30s) — on ne teste
        // pas ICI le délai réel, déjà couvert par ToleranceSmtpTemporaireTest.
        Carbon::setTestNow(now()->addSeconds(31));
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 10]);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(2, TransportSmtpSimule::nombreAppels());
    }

    public function test_rejet_550_echoue_immediatement_sans_relance_et_n_affecte_pas_le_canal_database(): void
    {
        $this->activerTransportSimule([550]);
        $utilisateur = User::factory()->create();

        $utilisateur->notify(new InscriptionConfirmee);
        $this->assertDatabaseCount('jobs', 2);

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 10]);

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(1, TransportSmtpSimule::nombreAppels(), 'un 5xx ne doit jamais être retenté');
        // Canal `database` : complètement indépendant, pas affecté par
        // l'échec du canal mail (confirmé : 1 ligne malgré le job mail en échec).
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_rafale_de_200_notifications_sans_aucun_echec_definitif(): void
    {
        config(['casa.mail_max_per_minute' => 50]);

        // Mélange réaliste : un rejet 421 toutes les 10 tentatives, succès sinon.
        $codes = [];
        for ($i = 1; $i <= 250; $i++) {
            $codes[] = ($i % 10 === 0) ? 421 : 0;
        }
        $this->activerTransportSimule($codes);

        $utilisateurs = User::factory()->count(200)->create();
        foreach ($utilisateurs as $utilisateur) {
            $utilisateur->notify(new InscriptionConfirmee);
        }

        $this->assertDatabaseCount('jobs', 400); // 200 x 2 canaux (mail + database)

        $tours = 0;
        while (DB::table('jobs')->count() > 0 && $tours < 50) {
            Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 10]);

            if (DB::table('jobs')->count() > 0) {
                // Laisse passer la fenêtre du limiteur de débit ET les
                // délais de relance (jamais plus de 600s) sans attendre réellement.
                Carbon::setTestNow(now()->addSeconds(61));
            }
            $tours++;
        }

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 200);
    }
}
