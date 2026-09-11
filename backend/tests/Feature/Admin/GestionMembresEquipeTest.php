<?php

namespace Tests\Feature\Admin;

use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Lot 11b — GESTION DES COMPTES DE L'ÉQUIPE (ADR-29). L'ouverture de
 * l'autorisation en écriture : lister / créer / (dés)activer / réinitialiser.
 *
 * Ce que ce test verrouille :
 *  - liste blanche stricte — JAMAIS le hash ni un mot de passe en clair ;
 *  - rôle validé serveur — {evaluateur, administrateur} seulement, sinon 422
 *    SANS aucune écriture (comme `casa:create-membre`) ;
 *  - mass assignment — `actif` / `id` / `is_admin` forgés → 422, aucun effet ;
 *  - garde-fous G1 (pas d'auto-désactivation) et G2 (jamais 0 admin actif) ;
 *  - audit de CHAQUE acte, auteur = l'admin connecté ;
 *  - un id de candidat dans l'URL → 404.
 *
 * La matrice d'autorisation complète (évaluateur/candidat → 403, invité → 401)
 * est prouvée par `Security/MatriceAutorisationTest` (introspection de routes).
 */
class GestionMembresEquipeTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->creerAdmin('coordination@cci.ci');
    }

    /** @return array<string, mixed> */
    private function payloadCreation(array $extra = []): array
    {
        return array_merge([
            'email' => 'nouvelle.jury@cci.ci',
            'role' => 'evaluateur',
            'prenom' => 'Awa',
            'nom' => 'Traoré',
            'poste' => 'Jury filière cuisine',
        ], $extra);
    }

    // --- Liste ------------------------------------------------------------

    public function test_liste_les_evaluateurs_et_les_admins_avec_leur_charge(): void
    {
        $this->creerEvaluateur('eval@cci.ci');

        $reponse = $this->actingAs($this->admin)->getJson('/api/admin/membres')->assertOk();

        $emails = collect($reponse->json('data'))->pluck('email')->all();
        $this->assertContains('coordination@cci.ci', $emails);
        $this->assertContains('eval@cci.ci', $emails);

        foreach ($reponse->json('data') as $ligne) {
            $this->assertSame(
                ['id', 'email', 'role', 'actif', 'prenom', 'nom', 'poste', 'derniere_connexion_le', 'dossiers_affectes', 'dossiers_evalues'],
                array_keys($ligne),
            );
            $this->assertIsInt($ligne['dossiers_affectes']);
            $this->assertIsInt($ligne['dossiers_evalues']);
        }
    }

    public function test_la_liste_ne_contient_jamais_de_hash_ni_de_mot_de_passe(): void
    {
        $this->creerEvaluateur('eval@cci.ci');

        $body = $this->actingAs($this->admin)->getJson('/api/admin/membres')->assertOk()->getContent();

        foreach (['mot_de_passe_hash', 'password', '$2y$', '$argon', 'remember_token'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $body);
        }
    }

    public function test_un_candidat_n_apparait_jamais_dans_la_liste(): void
    {
        $this->creerCandidat('candidat@cci.ci');

        $roles = collect(
            $this->actingAs($this->admin)->getJson('/api/admin/membres')->assertOk()->json('data')
        )->pluck('role')->unique()->all();

        $this->assertEqualsCanonicalizing(['administrateur'], $roles); // seul l'admin du setUp
    }

    // --- Création --------------------------------------------------------

    public function test_cree_un_evaluateur_le_mot_de_passe_temporaire_permet_de_se_connecter(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->postJson('/api/admin/membres', $this->payloadCreation())
            ->assertCreated();

        $motDePasse = $reponse->json('mot_de_passe_temporaire');
        $this->assertIsString($motDePasse);
        $this->assertGreaterThanOrEqual(10, strlen($motDePasse));
        $this->assertMatchesRegularExpression('/[a-z]/', $motDePasse);
        $this->assertMatchesRegularExpression('/[A-Z]/', $motDePasse);
        $this->assertMatchesRegularExpression('/\d/', $motDePasse);

        // Le corps n'expose ni hash ni le mot de passe ailleurs que le champ dédié.
        $this->assertArrayNotHasKey('mot_de_passe_hash', $reponse->json('data'));
        $this->assertStringNotContainsString('$2y$', $reponse->getContent());

        $cree = User::where('email', 'nouvelle.jury@cci.ci')->firstOrFail();
        $this->assertSame('evaluateur', $cree->role);
        $this->assertTrue($cree->actif);
        $this->assertTrue(Hash::check($motDePasse, $cree->mot_de_passe_hash));
        $this->assertDatabaseHas('membre_equipe', ['utilisateur_id' => $cree->id, 'poste' => 'Jury filière cuisine']);

        // Audit : auteur = l'admin connecté (PAS auto-provisionnement), sans « (console) ».
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'role' => 'administrateur',
            'action' => 'Création de compte evaluateur',
            'module' => 'Utilisateurs',
            'objet' => 'nouvelle.jury@cci.ci',
        ]);

        // Le mot de passe temporaire fonctionne réellement.
        $this->fromSpa()->postJson('/api/login', [
            'email' => 'nouvelle.jury@cci.ci',
            'password' => $motDePasse,
        ])->assertOk()->assertJsonPath('data.role', 'evaluateur');
    }

    public function test_cree_un_administrateur(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/membres', $this->payloadCreation(['email' => 'admin2@cci.ci', 'role' => 'administrateur']))
            ->assertCreated();

        $this->assertSame('administrateur', User::where('email', 'admin2@cci.ci')->firstOrFail()->role);
        $this->assertDatabaseHas('journal_audit', [
            'action' => 'Création de compte administrateur',
            'objet' => 'admin2@cci.ci',
        ]);
    }

    /** @return array<string, array{0:string}> */
    public static function rolesInterdits(): array
    {
        return [
            'candidat' => ['candidat'],
            'rôle arbitraire' => ['superadmin'],
            'vide' => [''],
        ];
    }

    #[DataProvider('rolesInterdits')]
    public function test_refuse_un_role_non_autorise_sans_aucune_ecriture(string $role): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/membres', $this->payloadCreation(['role' => $role]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('utilisateur', ['email' => 'nouvelle.jury@cci.ci']);
        $this->assertDatabaseMissing('journal_audit', ['objet' => 'nouvelle.jury@cci.ci']);
    }

    public function test_mass_assignment_actif_id_is_admin_forges_sont_refuses(): void
    {
        $idForge = '11111111-1111-1111-1111-111111111111';

        $this->actingAs($this->admin)->postJson('/api/admin/membres', $this->payloadCreation([
            'actif' => false,
            'id' => $idForge,
            'is_admin' => true,
            'password' => 'ChoisiParLePirate1',
        ]))->assertStatus(422)->assertJsonValidationErrors(['actif', 'id', 'is_admin', 'password']);

        $this->assertDatabaseMissing('utilisateur', ['email' => 'nouvelle.jury@cci.ci']);
        $this->assertDatabaseMissing('utilisateur', ['id' => $idForge]);
    }

    public function test_refuse_un_email_deja_pris(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/membres', $this->payloadCreation(['email' => 'coordination@cci.ci']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // --- (Dés)activation ------------------------------------------------

    public function test_desactive_un_evaluateur_qui_perd_l_acces_et_trace_l_audit(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", ['actif' => false])
            ->assertOk()
            ->assertJsonPath('data.actif', false);

        $this->assertFalse($eval->fresh()->actif);
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'action' => 'Désactivation de compte',
            'objet' => 'eval@cci.ci',
            'nouvelle_valeur' => 'actif=false',
        ]);

        // Il ne peut plus se connecter (message générique).
        $this->fromSpa()->postJson('/api/login', ['email' => 'eval@cci.ci', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_reactive_un_evaluateur(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');
        $eval->forceFill(['actif' => false])->save();

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", ['actif' => true])
            ->assertOk()
            ->assertJsonPath('data.actif', true);

        $this->assertTrue($eval->fresh()->actif);
        $this->assertDatabaseHas('journal_audit', ['action' => 'Activation de compte', 'objet' => 'eval@cci.ci']);
    }

    public function test_g1_un_admin_ne_peut_pas_se_desactiver_lui_meme(): void
    {
        $this->creerAdmin('autre.admin@cci.ci'); // il reste un autre admin : G2 ne s'applique pas

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$this->admin->id}", ['actif' => false])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Vous ne pouvez pas désactiver votre propre compte.']);

        $this->assertTrue($this->admin->fresh()->actif);
        $this->assertDatabaseMissing('journal_audit', ['action' => 'Désactivation de compte']);
    }

    public function test_g2_impossible_de_desactiver_le_dernier_administrateur_actif(): void
    {
        // Seul $this->admin est administrateur. Il tente de se désactiver :
        // G2 (dernier admin actif) est évalué AVANT G1 (soi-même) → c'est le
        // message le plus parlant qui remonte.
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$this->admin->id}", ['actif' => false])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Impossible de désactiver le dernier administrateur actif.']);

        $this->assertTrue($this->admin->fresh()->actif);
        $this->assertDatabaseMissing('journal_audit', ['action' => 'Désactivation de compte']);
    }

    public function test_g2_un_admin_peut_desactiver_un_autre_admin_tant_qu_il_en_reste_un(): void
    {
        $autre = $this->creerAdmin('autre.admin@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$autre->id}", ['actif' => false])
            ->assertOk()
            ->assertJsonPath('data.actif', false);

        $this->assertFalse($autre->fresh()->actif);
        // Il ne reste plus qu'un admin actif ($this->admin) : le désactiver est
        // maintenant refusé.
        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$this->admin->id}", ['actif' => false])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Impossible de désactiver le dernier administrateur actif.']);
    }

    // --- Réinitialisation du mot de passe ------------------------------

    public function test_reinitialise_le_mot_de_passe_renvoie_le_nouveau_jamais_l_ancien_hash(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');
        $ancienHash = $eval->mot_de_passe_hash;

        $reponse = $this->actingAs($this->admin)
            ->postJson("/api/admin/membres/{$eval->id}/mot-de-passe")
            ->assertOk();

        $nouveau = $reponse->json('mot_de_passe_temporaire');
        $this->assertIsString($nouveau);
        $this->assertStringNotContainsString($ancienHash, $reponse->getContent());
        $this->assertStringNotContainsString('$2y$', $reponse->getContent());

        $eval->refresh();
        $this->assertNotSame($ancienHash, $eval->mot_de_passe_hash);
        $this->assertTrue(Hash::check($nouveau, $eval->mot_de_passe_hash));

        // Audit sans aucune valeur de mot de passe.
        $ligne = JournalAudit::where('action', 'Réinitialisation du mot de passe')->firstOrFail();
        $this->assertSame($this->admin->id, $ligne->auteur_id);
        $this->assertSame('eval@cci.ci', $ligne->objet);
        $this->assertNull($ligne->ancienne_valeur);
        $this->assertNull($ligne->nouvelle_valeur);
        $this->assertStringNotContainsString($nouveau, (string) json_encode($ligne->toArray()));
    }

    public function test_la_liste_apres_reinitialisation_ne_montre_toujours_pas_le_mot_de_passe(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');
        $nouveau = $this->actingAs($this->admin)
            ->postJson("/api/admin/membres/{$eval->id}/mot-de-passe")->json('mot_de_passe_temporaire');

        $body = $this->actingAs($this->admin)->getJson('/api/admin/membres')->getContent();
        $this->assertStringNotContainsString($nouveau, $body);
    }

    // --- Cible invalide ------------------------------------------------

    public function test_un_id_de_candidat_dans_l_url_repond_404(): void
    {
        $candidat = $this->creerCandidat('candidat@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$candidat->id}", ['actif' => false])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/membres/{$candidat->id}/mot-de-passe")
            ->assertNotFound();
    }

    public function test_un_id_inconnu_repond_404(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/admin/membres/00000000-0000-0000-0000-000000000000', ['actif' => false])
            ->assertNotFound();
    }

    // --- Édition d'identité (Lot 15a) -----------------------------------

    public function test_admin_modifie_prenom_nom_poste_et_trace_l_audit(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", ['prenom' => 'Aïcha', 'nom' => 'Koffi', 'poste' => 'Jury filière CV'])
            ->assertOk()
            ->assertJsonPath('data.prenom', 'Aïcha')
            ->assertJsonPath('data.nom', 'Koffi')
            ->assertJsonPath('data.poste', 'Jury filière CV');

        $eval->refresh();
        $this->assertSame('Aïcha', $eval->membreEquipe->prenom);
        $this->assertSame('Koffi', $eval->membreEquipe->nom);
        $this->assertSame('Jury filière CV', $eval->membreEquipe->poste);

        $ligne = JournalAudit::where('action', "Modification d'identité")->firstOrFail();
        $this->assertSame($this->admin->id, $ligne->auteur_id);
        $this->assertSame('eval@cci.ci', $ligne->objet);
        $this->assertStringContainsString('Aïcha', $ligne->nouvelle_valeur);
        // L'audit ne se réduit pas à un booléen : l'ancienne identité y figure aussi.
        $this->assertStringContainsString('prenom=', $ligne->ancienne_valeur);
    }

    public function test_edition_identite_ne_touche_pas_au_statut_actif(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');
        $this->assertTrue($eval->actif);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", ['prenom' => 'Nouveau', 'nom' => 'Nom', 'poste' => 'Poste'])
            ->assertOk();

        $this->assertTrue($eval->fresh()->actif);
    }

    public function test_edition_identite_partielle_refusee_les_3_champs_sont_lies(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", ['prenom' => 'Solo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nom', 'poste']);

        // Rien n'a changé.
        $this->assertNotSame('Solo', $eval->fresh()->membreEquipe->prenom);
        $this->assertDatabaseMissing('journal_audit', ['action' => "Modification d'identité"]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function champsInterdits(): array
    {
        return [
            'role' => [['role' => 'administrateur'], 'role'],
            'email' => [['email' => 'pirate@cci.ci'], 'email'],
            'password' => [['password' => 'Piratage2026'], 'password'],
            'mot_de_passe_hash' => [['mot_de_passe_hash' => '$2y$hack'], 'mot_de_passe_hash'],
        ];
    }

    #[DataProvider('champsInterdits')]
    public function test_edition_identite_avec_un_champ_interdit_glisse_dedans_est_refusee(array $champForge, string $cleErreur): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');
        $roleAvant = $eval->role;

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", array_merge(
                ['prenom' => 'Test', 'nom' => 'Test', 'poste' => 'Test'],
                $champForge,
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors($cleErreur);

        // Aucune écriture partielle : ni l'identité, ni a fortiori le rôle.
        $this->assertSame($roleAvant, $eval->fresh()->role);
        $this->assertNotSame('Test', $eval->fresh()->membreEquipe->prenom);
    }

    public function test_un_evaluateur_ne_peut_pas_editer_l_identite_meme_la_sienne(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');

        // La cible EST l'auteur lui-même — vérifie qu'aucune exception n'est
        // faite pour « éditer son propre poste ».
        $this->actingAs($eval)
            ->patchJson("/api/admin/membres/{$eval->id}", ['prenom' => 'Moi', 'nom' => 'Meme', 'poste' => 'Autopromotion'])
            ->assertStatus(403);

        $this->assertNotSame('Moi', $eval->fresh()->membreEquipe->prenom);
    }

    public function test_payload_vide_ni_actif_ni_identite_est_refuse(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", [])
            ->assertStatus(422);
    }

    public function test_actif_et_identite_dans_le_meme_appel_appliquent_les_deux(): void
    {
        $eval = $this->creerEvaluateur('eval@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$eval->id}", [
                'actif' => false, 'prenom' => 'Combiné', 'nom' => 'Test', 'poste' => 'Poste',
            ])
            ->assertOk()
            ->assertJsonPath('data.actif', false)
            ->assertJsonPath('data.prenom', 'Combiné');

        $this->assertDatabaseHas('journal_audit', ['action' => 'Désactivation de compte']);
        $this->assertDatabaseHas('journal_audit', ['action' => "Modification d'identité"]);
    }

    public function test_un_id_de_candidat_dans_l_url_repond_404_pour_l_edition_d_identite(): void
    {
        $candidat = $this->creerCandidat('candidat@cci.ci');

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/membres/{$candidat->id}", ['prenom' => 'X', 'nom' => 'Y', 'poste' => 'Z'])
            ->assertNotFound();
    }
}
