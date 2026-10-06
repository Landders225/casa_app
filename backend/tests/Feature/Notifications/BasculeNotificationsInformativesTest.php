<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\InscriptionConfirmee;
use App\Notifications\ReinitialisationMotDePasse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lot 18bis (ADR-36) — `MAIL_NOTIFICATIONS_INFORMATIVES` : preuve de bout en
 * bout (dispatch réel -> table `jobs`), au-delà du `via()` isolé déjà couvert
 * par `EnvoiMailResilientTest`.
 */
class BasculeNotificationsInformativesTest extends TestCase
{
    use RefreshDatabase;

    public function test_desactive_aucun_job_mail_n_est_cree_pour_une_notification_informative(): void
    {
        config(['casa.mail_notifications_informatives' => false, 'queue.default' => 'database']);

        User::factory()->create()->notify(new InscriptionConfirmee);

        $this->assertDatabaseCount('jobs', 1); // database seul
        $this->assertSame(0, DB::table('jobs')->where('queue', 'mail-information')->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'mail-critique')->count());
    }

    public function test_desactive_le_canal_critique_reste_cree(): void
    {
        config(['casa.mail_notifications_informatives' => false, 'queue.default' => 'database']);

        User::factory()->create()->notify(new ReinitialisationMotDePasse('jeton-de-test'));

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'mail-critique')->count());
    }

    public function test_active_par_defaut_les_2_canaux_sont_crees(): void
    {
        config(['queue.default' => 'database']);

        User::factory()->create()->notify(new InscriptionConfirmee);

        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'mail-information')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
    }
}
