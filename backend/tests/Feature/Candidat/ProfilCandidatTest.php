<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profil candidat (Lot 7) — GET / PATCH /api/candidat/profil.
 * Propriétaire uniquement (pas de paramètre d'URL), liste blanche 🟢,
 * `email` / `residence_ci` / nationalité / diplôme non éditables (ADR-07).
 */
class ProfilCandidatTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    public function test_get_profil_renvoie_les_8_champs_verts_et_rien_d_autre(): void
    {
        $user = $this->creerCandidat('cand@example.ci', [
            'prenom' => 'Koffi', 'nom' => 'Yao', 'sexe' => 'H',
            'date_naissance' => '2000-03-15', 'ville_residence' => 'Bouaké',
        ]);

        $response = $this->actingAs($user)->getJson('/api/candidat/profil')->assertOk();

        $response->assertExactJson(['data' => [
            'id' => $user->candidat->id,
            'prenom' => 'Koffi',
            'nom' => 'Yao',
            'sexe' => 'H',
            'date_naissance' => '2000-03-15',
            'cni' => $user->candidat->cni,
            'telephone' => '0700000000',
            'ville_residence' => 'Bouaké',
            'residence_ci' => true,
        ]]);
        $this->assertStringNotContainsString('mot_de_passe_hash', $response->getContent());
        $this->assertStringNotContainsString('utilisateur_id', $response->getContent());
    }

    public function test_patch_profil_modifie_l_etat_civil_et_journalise(): void
    {
        $user = $this->creerCandidat('cand@example.ci');

        $this->actingAs($user)->patchJson('/api/candidat/profil', [
            'prenom' => 'Awa', 'telephone' => '0102030405', 'ville_residence' => 'San-Pédro',
        ])->assertOk()
            ->assertJsonPath('data.prenom', 'Awa')
            ->assertJsonPath('data.telephone', '0102030405')
            ->assertJsonPath('data.ville_residence', 'San-Pédro');

        $this->assertDatabaseHas('candidat', [
            'utilisateur_id' => $user->id, 'prenom' => 'Awa', 'telephone' => '0102030405',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $user->id,
            'action' => 'Mise à jour du profil',
            'module' => 'Compte',
        ]);
    }

    public function test_patch_sans_changement_effectif_ne_journalise_pas(): void
    {
        $user = $this->creerCandidat('cand@example.ci', ['prenom' => 'Test']);

        $this->actingAs($user)->patchJson('/api/candidat/profil', ['prenom' => 'Test'])->assertOk();

        $this->assertDatabaseMissing('journal_audit', ['action' => 'Mise à jour du profil']);
    }

    public function test_un_candidat_ne_touche_que_son_propre_profil(): void
    {
        $a = $this->creerCandidat('a@example.ci', ['nom' => 'AAA']);
        $b = $this->creerCandidat('b@example.ci', ['nom' => 'BBB']);

        $this->actingAs($a)->patchJson('/api/candidat/profil', ['nom' => 'MODIFIE'])->assertOk();

        $this->assertSame('MODIFIE', $a->candidat->fresh()->nom);
        $this->assertSame('BBB', $b->candidat->fresh()->nom); // intact
    }

    public function test_residence_ci_non_modifiable(): void
    {
        $user = $this->creerCandidat('cand@example.ci');

        $this->actingAs($user)->patchJson('/api/candidat/profil', ['residence_ci' => false])
            ->assertStatus(422)->assertJsonValidationErrors('residence_ci');

        $this->assertTrue($user->candidat->fresh()->residence_ci);
    }

    public function test_email_non_modifiable_via_le_profil(): void
    {
        $user = $this->creerCandidat('cand@example.ci');

        $this->actingAs($user)->patchJson('/api/candidat/profil', ['email' => 'nouveau@example.ci'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('cand@example.ci', $user->fresh()->email);
    }

    public function test_adr07_nationalite_et_diplome_prohibes(): void
    {
        $user = $this->creerCandidat('cand@example.ci');

        $this->actingAs($user)->patchJson('/api/candidat/profil', [
            'nationalite' => 'ivoirienne', 'diplome_verifie' => 'bac',
        ])->assertStatus(422)->assertJsonValidationErrors(['nationalite', 'diplome_verifie']);
    }

    public function test_patch_date_naissance_hors_tranche_refuse(): void
    {
        $user = $this->creerCandidat('cand@example.ci', ['date_naissance' => '2000-01-01']);

        $this->actingAs($user)->patchJson('/api/candidat/profil', ['date_naissance' => '1990-01-01'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Le programme CASA s'adresse aux personnes de 18 à 30 ans."]);

        $this->assertSame('2000-01-01', $user->candidat->fresh()->date_naissance->toDateString());
    }

    public function test_profil_requiert_authentification(): void
    {
        $this->getJson('/api/candidat/profil')->assertStatus(401);
        $this->patchJson('/api/candidat/profil', ['prenom' => 'X'])->assertStatus(401);
    }

    public function test_profil_reserve_au_role_candidat(): void
    {
        foreach ([User::factory()->evaluateur()->create(), User::factory()->administrateur()->create()] as $intrus) {
            $this->actingAs($intrus)->getJson('/api/candidat/profil')->assertStatus(403);
            $this->actingAs($intrus)->patchJson('/api/candidat/profil', ['prenom' => 'X'])->assertStatus(403);
        }
    }

    public function test_compte_candidat_sans_profil_404(): void
    {
        $user = User::factory()->create(['role' => 'candidat']); // pas de ligne candidat

        $this->actingAs($user)->getJson('/api/candidat/profil')->assertStatus(404);
    }
}
