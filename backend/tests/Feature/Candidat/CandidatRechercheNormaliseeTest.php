<?php

namespace Tests\Feature\Candidat;

use App\Models\Campagne;
use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ComptesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Admin\CreeContexteClassement;
use Tests\TestCase;

/**
 * `candidat.recherche_normalisee` (lot recherche-admin, ADR-39) : remplissage
 * des lignes EXISTANTES (backfill), propagation sur toute écriture de
 * prenom/nom, et non-fuite dans les réponses JSON. Jetons fictifs uniquement
 * — aucune donnée réelle de candidat.
 */
class CandidatRechercheNormaliseeTest extends TestCase
{
    use CreeContexteClassement;
    use RefreshDatabase;

    /**
     * A — BACKFILL : une ligne `candidat` insérée HORS du hook
     * (`DB::table()->insert()`, comme `ComptesDemoSeeder`) a sa colonne vide
     * par défaut. `Candidat::rafraichirRechercheNormalisee()` — la méthode
     * appelée par la migration — doit la remplir et rendre la candidature
     * rattachée trouvable par la VRAIE recherche admin, SANS passer par
     * Eloquent : `updated_at` doit rester EXACTEMENT identique (un backfill
     * n'est pas une modification réelle de la ligne, et ne doit pas non plus
     * déclencher `Candidat::booted()`).
     */
    public function test_le_backfill_remplit_les_lignes_inserees_hors_du_hook_et_les_rend_cherchables(): void
    {
        $this->seedReferentiels();
        $admin = $this->creerAdmin('admin@casa-demo.ci');

        $utilisateurId = (string) Str::uuid();
        $candidatId = (string) Str::uuid();
        // Date FIXE (pas `now()`) : sert de valeur de référence pour prouver
        // que le backfill ne la touche pas, quelle que soit la durée du test.
        $updatedAtAvant = '2020-06-15 10:00:00';
        DB::table('utilisateur')->insert([
            'id' => $utilisateurId,
            'email' => 'fixture-backfill@casa-demo.ci',
            'mot_de_passe_hash' => 'x',
            'role' => 'candidat',
            'actif' => true,
            'cree_le' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('candidat')->insert([
            'id' => $candidatId,
            'utilisateur_id' => $utilisateurId,
            'prenom' => 'Backfill',
            'nom' => "N'Testeur",
            // Colonne par défaut vide — exactement ce que produit un insert
            // brut qui ne traverse pas `Candidat::booted()`.
            'recherche_normalisee' => '',
            'sexe' => 'F',
            'date_naissance' => '2000-01-01',
            'cni' => 'CI000000000',
            'telephone' => '0700000000',
            'ville_residence' => 'Abidjan - Cocody',
            'residence_ci' => true,
            'created_at' => $updatedAtAvant,
            'updated_at' => $updatedAtAvant,
        ]);
        $candidature = Candidature::create([
            'candidat_id' => $candidatId,
            'campagne_id' => Campagne::ouverte()->firstOrFail()->id,
            'filiere_id' => $this->idFiliere('cuisine'),
            'numero_dossier' => 'CASA-2026-900001',
            'cqp_confirme' => true,
        ]);
        $candidature->forceFill(['statut_interne' => 'soumis', 'date_soumission' => now()])->saveQuietly();

        $this->assertSame('', DB::table('candidat')->where('id', $candidatId)->value('recherche_normalisee'));
        // Avant backfill : la recherche ne trouve rien (colonne vide).
        $this->actingAs($admin)->postJson('/api/admin/candidatures', ['recherche' => 'ntesteur'])
            ->assertOk()->assertJsonCount(0, 'data');

        Candidat::rafraichirRechercheNormalisee();

        $ligne = DB::table('candidat')->where('id', $candidatId)->first();
        $this->assertSame(Candidat::normaliserRecherche('Backfill', "N'Testeur"), $ligne->recherche_normalisee);
        // `updated_at` STRICTEMENT inchangé — preuve que le backfill n'est
        // pas passé par `save()` (qui l'aurait mis à jour automatiquement).
        // `CarbonImmutable::parse` normalise le format retourné par PostgreSQL
        // (`+00` de fuseau sur un `timestamptz` lu hors Eloquent) sans changer
        // l'instant comparé.
        $this->assertTrue(CarbonImmutable::parse($updatedAtAvant)->eq(CarbonImmutable::parse($ligne->updated_at)));

        // Après backfill : trouvable, apostrophe comprise.
        $this->actingAs($admin)->postJson('/api/admin/candidatures', ['recherche' => 'ntesteur'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', 'CASA-2026-900001');
    }

    /**
     * B — ÉCRITURE : `PATCH /api/candidat/profil` modifie prenom puis nom —
     * la colonne doit suivre à chaque fois, via le hook réel (pas un appel
     * direct à `normaliserRecherche()`).
     */
    public function test_modifier_prenom_puis_nom_via_le_profil_met_a_jour_la_colonne(): void
    {
        $candidatUser = $this->creerCandidat(profil: ['prenom' => 'Avant', 'nom' => 'Initial']);

        $this->actingAs($candidatUser)->patchJson('/api/candidat/profil', ['prenom' => 'Après'])->assertOk();
        $candidatUser->candidat->refresh();
        $this->assertSame(Candidat::normaliserRecherche('Après', 'Initial'), $candidatUser->candidat->recherche_normalisee);

        $this->actingAs($candidatUser)->patchJson('/api/candidat/profil', ['nom' => 'Final'])->assertOk();
        $candidatUser->candidat->refresh();
        $this->assertSame(Candidat::normaliserRecherche('Après', 'Final'), $candidatUser->candidat->recherche_normalisee);
    }

    /**
     * B (gap corrigé) — `ComptesDemoSeeder` écrit `candidat` via
     * `DB::table()->insert()` (hors hook) : sans le correctif apporté à ce
     * seeder (ajout explicite de `recherche_normalisee`), le persona candidat
     * de démo ne serait jamais trouvé par la recherche.
     */
    public function test_le_candidat_du_comptesdemoseeder_est_cherchable(): void
    {
        $this->seed(ComptesDemoSeeder::class);
        $admin = User::where('email', 'admin@casa-demo.ci')->first();

        $colonne = DB::table('candidat')
            ->join('utilisateur', 'utilisateur.id', '=', 'candidat.utilisateur_id')
            ->where('utilisateur.email', 'candidat@casa-demo.ci')
            ->value('candidat.recherche_normalisee');

        $this->assertNotSame('', $colonne);
        $this->assertSame(Candidat::normaliserRecherche('Aya', 'Konan'), $colonne);

        // La recherche elle-même reste 200 même sans candidature pour ce
        // persona (le seeder ne crée aucun dossier) — ce qui est testé ici est
        // la colonne du PERSONA lui-même, déjà prouvée non vide ci-dessus.
        $this->actingAs($admin)->postJson('/api/admin/candidatures', ['recherche' => 'konan'])->assertOk();
    }

    /**
     * C.1 — NON-FUITE : `recherche_normalisee` ($hidden) ne doit apparaître
     * dans AUCUNE réponse JSON (profil candidat, fiche évaluateur, recherche
     * admin) — y compris avec un terme de recherche qui PROUVE que la
     * recherche a fonctionné (pas un faux négatif par liste vide).
     */
    public function test_recherche_normalisee_est_absente_de_toutes_les_reponses_json(): void
    {
        $this->seedContexteClassement();
        $campagne = Campagne::ouverte()->firstOrFail();
        $admin = $this->creerAdmin('admin@casa-demo.ci');
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $candidature = $this->candidatureEvaluee($campagne, $evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => 'Jeton', 'nom' => "N'Fictif",
        ]);
        $candidatUser = $candidature->candidat->utilisateur;

        $reponseProfil = $this->actingAs($candidatUser)->getJson('/api/candidat/profil')->assertOk();
        $this->assertStringNotContainsString('recherche_normalisee', $reponseProfil->getContent());

        $reponseFicheEvaluateur = $this->actingAs($evaluateur)
            ->getJson("/api/evaluateur/candidatures/{$candidature->id}")
            ->assertOk();
        $this->assertStringNotContainsString('recherche_normalisee', $reponseFicheEvaluateur->getContent());

        $reponseRechercheAdmin = $this->actingAs($admin)
            ->postJson('/api/admin/candidatures', ['recherche' => 'nfictif'])
            ->assertOk()
            ->assertJsonCount(1, 'data'); // preuve que la recherche a bien matché.
        $this->assertStringNotContainsString('recherche_normalisee', $reponseRechercheAdmin->getContent());
    }

    /**
     * Défense en profondeur au niveau du modèle lui-même (pas seulement les
     * Resources) — `$hidden` exclut la colonne même d'une sérialisation JSON
     * directe du modèle, qu'aucune Resource n'intercepte.
     */
    public function test_hidden_exclut_la_colonne_d_une_serialisation_directe_du_modele(): void
    {
        $candidatUser = $this->creerCandidat(profil: ['prenom' => 'Direct', 'nom' => 'Serialise']);
        $candidat = $candidatUser->candidat->fresh();

        $this->assertNotSame('', $candidat->recherche_normalisee); // la colonne EXISTE bien en base.
        $this->assertArrayNotHasKey('recherche_normalisee', $candidat->toArray());
        $this->assertStringNotContainsString('recherche_normalisee', $candidat->toJson());
    }

    /**
     * COMPATIBILITÉ ANCIEN CODE : `recherche_normalisee` a un DÉFAUT SQL
     * (`''`), pas seulement `nullable` — un `INSERT` qui ne la mentionne pas
     * DU TOUT (exactement ce que fait un code candidat/inscription pas
     * encore au courant de cette colonne, si la migration a déjà tourné)
     * doit réussir sans erreur. C'est la moitié « colonne déjà là, code pas
     * encore neuf » de la fenêtre de déploiement ; l'autre moitié (code neuf
     * déjà en place sans la colonne → 500) est documentée dans
     * `docs/DEPLOIEMENT.md` § 12.4.
     */
    public function test_la_colonne_a_un_defaut_et_accepte_un_insert_qui_ne_la_mentionne_pas(): void
    {
        $utilisateurId = (string) Str::uuid();
        DB::table('utilisateur')->insert([
            'id' => $utilisateurId,
            'email' => 'fixture-ancien-code@casa-demo.ci',
            'mot_de_passe_hash' => 'x',
            'role' => 'candidat',
            'actif' => true,
            'cree_le' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // AUCUNE clé `recherche_normalisee` ici — exactement ce qu'écrirait
        // un `INSERT` rédigé avant que cette colonne n'existe.
        DB::table('candidat')->insert([
            'id' => (string) Str::uuid(),
            'utilisateur_id' => $utilisateurId,
            'prenom' => 'Ancien',
            'nom' => 'Code',
            'sexe' => 'F',
            'date_naissance' => '2000-01-01',
            'cni' => 'CI111111111',
            'telephone' => '0700000001',
            'ville_residence' => 'Abidjan - Cocody',
            'residence_ci' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            '',
            DB::table('candidat')->where('utilisateur_id', $utilisateurId)->value('recherche_normalisee'),
        );
    }

    /**
     * CORRECTIF PROD : un candidat enregistré en lettres Unicode « stylisées »
     * (ici un jeton fictif en italique mathématique, même bloc que le cas
     * réel constaté — U+1D400–U+1D7FF) doit être trouvable par un terme tapé
     * en ASCII simple. Avant le correctif NFKC, `recherche_normalisee`
     * ressortait vide pour un tel nom et cette recherche échouait.
     */
    public function test_terme_ascii_retrouve_un_candidat_enregistre_en_lettres_stylisees(): void
    {
        $this->seedContexteClassement();
        $campagne = Campagne::ouverte()->firstOrFail();
        $admin = $this->creerAdmin('admin@casa-demo.ci');
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        // "𝑇𝑒𝑠𝑡" (italique mathématique) + "𝐀𝐰𝐚" (gras mathématique) — jetons
        // fictifs, construits caractère par caractère, pas un nom réel.
        $candidature = $this->candidatureEvaluee($campagne, $evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => '𝑇𝑒𝑠𝑡', 'nom' => '𝐀𝐰𝐚',
        ]);

        $this->assertSame('test awa', $candidature->candidat->fresh()->recherche_normalisee);

        $this->actingAs($admin)->postJson('/api/admin/candidatures', ['recherche' => 'test awa'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $candidature->numero_dossier);
    }

    /**
     * Garde-fou : un candidat dont le nom ne contient AUCUNE lettre
     * translittérable, même après NFKC (ici des idéogrammes fictifs —
     * jamais un vrai nom), a une `recherche_normalisee` légitimement vide —
     * mais reste trouvable par numéro de dossier, qui ne dépend pas de cette
     * colonne. La recherche par nom, elle, ne le trouve pas (comportement
     * attendu et documenté, pas un bug : rien à translittérer).
     */
    public function test_candidat_avec_normalisation_vide_reste_trouvable_par_numero_dossier(): void
    {
        $this->seedContexteClassement();
        $campagne = Campagne::ouverte()->firstOrFail();
        $admin = $this->creerAdmin('admin@casa-demo.ci');
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $candidature = $this->candidatureEvaluee($campagne, $evaluateur, 'cuisine', 60.0, 30.0, [
            'prenom' => '測試', 'nom' => '案例',
        ]);

        $this->assertSame('', $candidature->candidat->fresh()->recherche_normalisee);

        $dossierPartiel = substr($candidature->numero_dossier, -6);
        $this->actingAs($admin)->postJson('/api/admin/candidatures', ['recherche' => $dossierPartiel])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_dossier', $candidature->numero_dossier);
    }

    /**
     * Migration `2026_10_16_100000_refresh_recherche_normalisee_apres_correctif_unicode` :
     * reproduit l'état réel constaté en prod (une ligne dont
     * `recherche_normalisee` est restée `''` à cause de l'ancienne fonction
     * sans NFKC, sur un nom en lettres stylisées) puis rejoue
     * `Candidat::rafraichirRechercheNormalisee()` — exactement ce que fait
     * cette migration — pour prouver qu'elle corrige bien la ligne.
     */
    public function test_la_migration_de_correctif_unicode_corrige_une_ligne_restee_vide(): void
    {
        $utilisateurId = (string) Str::uuid();
        $candidatId = (string) Str::uuid();
        DB::table('utilisateur')->insert([
            'id' => $utilisateurId,
            'email' => 'fixture-correctif-unicode@casa-demo.ci',
            'mot_de_passe_hash' => 'x',
            'role' => 'candidat',
            'actif' => true,
            'cree_le' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // État réel constaté : nom en lettres mathématiques (U+1D400–U+1D7FF),
        // `recherche_normalisee` restée vide après le PREMIER backfill (lequel
        // utilisait l'ancienne fonction, sans NFKC).
        DB::table('candidat')->insert([
            'id' => $candidatId,
            'utilisateur_id' => $utilisateurId,
            'prenom' => '𝑇𝑒𝑠𝑡',
            'nom' => '𝐀𝐰𝐚',
            'recherche_normalisee' => '',
            'sexe' => 'F',
            'date_naissance' => '2000-01-01',
            'cni' => 'CI222222222',
            'telephone' => '0700000002',
            'ville_residence' => 'Abidjan - Cocody',
            'residence_ci' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Candidat::rafraichirRechercheNormalisee();

        $this->assertSame(
            'test awa',
            DB::table('candidat')->where('id', $candidatId)->value('recherche_normalisee'),
        );
    }
}
