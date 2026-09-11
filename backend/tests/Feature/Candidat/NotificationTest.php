<?php

namespace Tests\Feature\Candidat;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\User;
use App\Notifications\CandidatureSoumise;
use App\Notifications\InscriptionConfirmee;
use App\Notifications\ResultatsPublies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Lot 12c — historique in-app des notifications (canal `database`, Lot
 * 12b/ADR-33). Table `notifications` créée par ce lot (migration Lot 12c,
 * `uuidMorphs` — cf. docstring migration) : ces tests, en écrivant/lisant de
 * VRAIES lignes liées à un `User` UUID, sont eux-mêmes la preuve que la
 * correction du stub fonctionne (une jointure `bigint`/`uuid` cassée
 * échouerait silencieusement en filtrant tout, pas en erreur SQL explicite).
 */
class NotificationTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();
    }

    public function test_liste_les_notifications_du_candidat_connecte_triees_par_date_desc(): void
    {
        $user = $this->creerCandidat('liste@casa-demo.ci');
        $user->notify(new InscriptionConfirmee);
        // Horodatage forcé dans le passé : la colonne `created_at` (précision
        // seconde par défaut) peut être identique pour 2 notifications émises
        // dans le même test — on rend l'ordre déterministe plutôt que de
        // dépendre d'un timing réel.
        $user->notifications()->latest()->first()->forceFill(['created_at' => now()->subMinute()])->save();
        $user->notify(new CandidatureSoumise('CASA-2026-000123'));

        $reponse = $this->actingAs($user)->getJson('/api/candidat/notifications')->assertOk();

        $items = $reponse->json('data');
        $this->assertCount(2, $items);
        // La plus récente (CandidatureSoumise, envoyée en second) en premier.
        $this->assertSame('soumission', $items[0]['categorie']);
        $this->assertSame('inscription', $items[1]['categorie']);
        $this->assertFalse($items[0]['lue']);
    }

    public function test_uuid_morphs_relie_reellement_la_notification_a_l_utilisateur_uuid(): void
    {
        $user = $this->creerCandidat('uuid@casa-demo.ci');
        $this->assertIsString($user->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $user->id);

        $user->notify(new InscriptionConfirmee);

        // Ligne réellement en base, `notifiable_id` = l'UUID de CE user (pas un
        // bigint, pas tronqué) — la preuve directe que uuidMorphs fonctionne.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => InscriptionConfirmee::class,
        ]);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_isolation_un_candidat_ne_voit_pas_les_notifications_d_un_autre(): void
    {
        $alice = $this->creerCandidat('alice-notif@casa-demo.ci');
        $bob = $this->creerCandidat('bob-notif@casa-demo.ci');
        $alice->notify(new InscriptionConfirmee);

        $reponseBob = $this->actingAs($bob)->getJson('/api/candidat/notifications')->assertOk();
        $this->assertCount(0, $reponseBob->json('data'));

        $reponseAlice = $this->actingAs($alice)->getJson('/api/candidat/notifications')->assertOk();
        $this->assertCount(1, $reponseAlice->json('data'));
    }

    public function test_isolation_marquer_lue_la_notification_d_un_autre_404(): void
    {
        $alice = $this->creerCandidat('alice-marque@casa-demo.ci');
        $bob = $this->creerCandidat('bob-marque@casa-demo.ci');
        $alice->notify(new InscriptionConfirmee);
        $idNotifAlice = $alice->notifications()->first()->id;

        $this->actingAs($bob)->patchJson("/api/candidat/notifications/{$idNotifAlice}/lue")
            ->assertStatus(404);

        // Toujours non lue — Bob n'a rien pu changer.
        $this->assertNull($alice->notifications()->first()->read_at);
    }

    public function test_role_scope_evaluateur_et_admin_refuses_403(): void
    {
        $this->creerCandidat('candidat-scope@casa-demo.ci');
        $evaluateur = $this->creerEvaluateur('eval-scope@casa-demo.ci');
        $admin = $this->creerAdmin('admin-scope@casa-demo.ci');

        foreach ([$evaluateur, $admin] as $intrus) {
            $this->actingAs($intrus)->getJson('/api/candidat/notifications')->assertStatus(403);
            $this->actingAs($intrus)->getJson('/api/candidat/notifications/compteur')->assertStatus(403);
            $this->actingAs($intrus)->postJson('/api/candidat/notifications/marquer-tout-lu')->assertStatus(403);
        }
    }

    public function test_marquer_une_notification_lue(): void
    {
        $user = $this->creerCandidat('marque-une@casa-demo.ci');
        $user->notify(new InscriptionConfirmee);
        $id = $user->notifications()->first()->id;

        $reponse = $this->actingAs($user)->patchJson("/api/candidat/notifications/{$id}/lue")->assertOk();
        $this->assertTrue($reponse->json('data.lue'));
        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_compteur_et_marquer_tout_lu(): void
    {
        $user = $this->creerCandidat('compteur@casa-demo.ci');
        $user->notify(new InscriptionConfirmee);
        $user->notify(new CandidatureSoumise('CASA-2026-000999'));

        $this->actingAs($user)->getJson('/api/candidat/notifications/compteur')
            ->assertOk()
            ->assertJsonPath('data.non_lues', 2);

        $this->actingAs($user)->postJson('/api/candidat/notifications/marquer-tout-lu')->assertOk();

        $this->actingAs($user)->getJson('/api/candidat/notifications/compteur')
            ->assertOk()
            ->assertJsonPath('data.non_lues', 0);
    }

    public function test_marquer_tout_lu_est_une_seule_requete_pas_une_boucle(): void
    {
        $user = $this->creerCandidat('perf-marquage@casa-demo.ci');
        for ($i = 0; $i < 10; $i++) {
            $user->notify(new InscriptionConfirmee);
        }

        DB::enableQueryLog();
        $this->actingAs($user)->postJson('/api/candidat/notifications/marquer-tout-lu')->assertOk();
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $updates = array_filter($requetes, fn ($q) => str_starts_with(strtoupper(trim($q['query'])), 'UPDATE'));
        // UNE seule requête UPDATE pour marquer les 10 lignes — pas 10.
        $this->assertCount(1, $updates, 'marquer-tout-lu doit être 1 UPDATE, pas une boucle de N.');
    }

    public function test_notification_sans_compte_du_candidat_absente_du_compteur_negatif(): void
    {
        // Non-régression triviale : un candidat sans aucune notification reçoit 0, pas une erreur.
        $user = $this->creerCandidat('zero@casa-demo.ci');

        $this->actingAs($user)->getJson('/api/candidat/notifications/compteur')
            ->assertOk()
            ->assertJsonPath('data.non_lues', 0);
        $this->actingAs($user)->getJson('/api/candidat/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * PREUVE MAÎTRESSE (rejoue le test du Lot 12b, mais sur le JSON STOCKÉ) :
     * le contenu persisté pour `ResultatsPublies` doit être STRICTEMENT
     * identique pour les 4 décisions possibles.
     */
    public function test_indiscernabilite_du_contenu_stocke_pour_resultats_publies(): void
    {
        $campagne = Campagne::ouverte()->firstOrFail();
        $filiere = $this->idFiliere('cuisine');
        $seq = 0;
        $destinataires = [];

        foreach (['retenu', 'liste_attente', 'non_retenu', 'indisponible'] as $decision) {
            $user = $this->creerCandidat('resultats-'.Str::random(8).'@casa-demo.ci');
            $candidature = Candidature::create([
                'candidat_id' => $user->candidat->id,
                'campagne_id' => $campagne->id,
                'filiere_id' => $filiere,
                'numero_dossier' => sprintf('CASA-2026-NOTIF%03d', ++$seq),
                'statut_interne' => 'evalue',
                'cqp_confirme' => true,
            ]);
            DecisionCandidature::create([
                'candidature_id' => $candidature->id,
                'rang' => $decision === 'indisponible' ? null : 1,
                'decision' => $decision,
            ]);
            $user->notify(new ResultatsPublies($campagne->nom));
            $destinataires[] = $user;
        }

        $donnees = array_map(
            fn (User $u) => $u->notifications()->where('type', ResultatsPublies::class)->firstOrFail()->data,
            $destinataires,
        );

        // AUCUNE exception : les 4 tableaux `data` doivent être rigoureusement égaux.
        $this->assertCount(1, array_unique(array_map('serialize', $donnees)));
        foreach ($donnees as $d) {
            foreach (['retenu', 'liste_attente', 'non_retenu', 'indisponible', 'rang', 'score', 'motif'] as $mot) {
                $this->assertStringNotContainsStringIgnoringCase($mot, json_encode($d), "« {$mot} » ne doit pas apparaître dans le contenu stocké");
            }
        }
    }
}
