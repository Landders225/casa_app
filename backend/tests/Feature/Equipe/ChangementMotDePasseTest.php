<?php

namespace Tests\Feature\Equipe;

use App\Models\JournalAudit;
use App\Models\User;
use App\Notifications\MotDePasseModifie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Lot 15a — `PUT /api/equipe/mot-de-passe`, membre d'équipe CONNECTÉ
 * (évaluateur OU admin) : self-service, distinct de la réinitialisation PAR UN
 * ADMIN (Lot 11b, `POST /admin/membres/{u}/mot-de-passe`, sur un AUTRE compte).
 *
 * Copie structurelle de `Candidat\ChangementMotDePasseTest` (Lot 13) — mêmes
 * preuves, rejouées pour les DEUX rôles d'équipe (dataProvider).
 */
class ChangementMotDePasseTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function roles(): array
    {
        return ['évaluateur' => ['evaluateur'], 'administrateur' => ['administrateur']];
    }

    private function membre(string $role): User
    {
        return $role === 'evaluateur'
            ? $this->creerEvaluateur('membre@cci.ci')
            : $this->creerAdmin('membre@cci.ci');
    }

    #[DataProvider('roles')]
    public function test_mauvais_mot_de_passe_actuel_refuse_rien_ne_change(string $role): void
    {
        Notification::fake();
        $user = $this->membre($role);

        $reponse = $this->actingAs($user)->putJson('/api/equipe/mot-de-passe', [
            'current_password' => 'MauvaisMotDePasse',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(422);

        $this->assertSame('Le mot de passe actuel est incorrect.', $reponse->json('errors.current_password.0'));
        $this->assertTrue(Hash::check('password', $user->fresh()->mot_de_passe_hash));
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('journal_audit', ['action' => 'Changement de mot de passe']);
    }

    #[DataProvider('roles')]
    public function test_changement_reussi_l_ancien_mot_de_passe_ne_fonctionne_plus(string $role): void
    {
        Notification::fake();
        $user = $this->membre($role);

        $this->fromSpa()->actingAs($user)->putJson('/api/equipe/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertOk()->assertJsonPath('message', 'Votre mot de passe a été modifié.');

        $this->assertTrue(Hash::check('NouveauMdp2026', $user->fresh()->mot_de_passe_hash));

        $this->fromSpa()->postJson('/api/login', ['email' => 'membre@cci.ci', 'password' => 'password'])
            ->assertStatus(422);
        $this->fromSpa()->postJson('/api/login', ['email' => 'membre@cci.ci', 'password' => 'NouveauMdp2026'])
            ->assertOk();

        Notification::assertSentTo($user, MotDePasseModifie::class);
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id, 'role' => $role,
            'action' => 'Changement de mot de passe', 'module' => 'Compte', 'objet' => 'membre@cci.ci',
        ]);
        $ligne = JournalAudit::where('action', 'Changement de mot de passe')->firstOrFail();
        $this->assertNull($ligne->ancienne_valeur);
        $this->assertNull($ligne->nouvelle_valeur);
    }

    #[DataProvider('roles')]
    public function test_invalide_les_autres_sessions_existantes(string $role): void
    {
        Notification::fake();
        $user = $this->membre($role);

        DB::table('sessions')->insert([
            'id' => 'autre-appareil', 'user_id' => $user->id, 'ip_address' => '10.0.0.9',
            'user_agent' => 'autre navigateur', 'payload' => base64_encode('x'), 'last_activity' => time(),
        ]);

        $this->fromSpa()->actingAs($user)->putJson('/api/equipe/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'autre-appareil']);

        // La session COURANTE, elle, survit : un appel authentifié suivant fonctionne.
        $this->actingAs($user->fresh())->getJson('/api/admin/membres')->assertStatus($role === 'administrateur' ? 200 : 403);
    }

    #[DataProvider('roles')]
    public function test_nouveau_mot_de_passe_trop_faible_refuse(string $role): void
    {
        $user = $this->membre($role);

        $this->actingAs($user)->putJson('/api/equipe/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'faible', 'password_confirmation' => 'faible',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_requiert_authentification(): void
    {
        $this->putJson('/api/equipe/mot-de-passe', [
            'current_password' => 'password', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(401);
    }

    public function test_reserve_a_l_equipe_un_candidat_est_refuse(): void
    {
        $candidat = $this->creerCandidat('cand@cci.ci');

        $this->actingAs($candidat)->putJson('/api/equipe/mot-de-passe', [
            'current_password' => 'password', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026',
        ])->assertStatus(403);
    }
}
