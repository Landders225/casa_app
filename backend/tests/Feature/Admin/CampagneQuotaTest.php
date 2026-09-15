<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\DecisionCandidature;
use App\Models\JournalAudit;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Édition des quotas par filière (Lot 17, D-6a-2, ADR-09) — le point le plus
 * délicat du lot (Étape 1, Q2).
 *
 * Le garde-fou central : un quota modifié APRÈS qu'un classement a été
 * calculé pour la campagne ne recalcule RIEN en silence (`decision_candidature`
 * reste tel quel) — il marque le classement existant `classement_perime`,
 * un drapeau STRUCTUREL (pas un avertissement ignorable) qui :
 *  - bloque `POST .../publier` (422) tant qu'il est vrai ;
 *  - est exposé par `GET /admin/campagnes` ET `GET .../classement` ;
 *  - ne se lève QUE par un recalcul explicite (`POST .../classement`).
 * Une fois PUBLIÉE, la campagne bloque toute édition de quota (409) — aucune
 * exception, parce qu'au-delà de ce point aucun recalcul n'est plus possible.
 */
class CampagneQuotaTest extends TestCase
{
    use CreeContexteClassement;
    use RefreshDatabase;

    private Campagne $campagne;

    private User $admin;

    private User $evaluateur;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedContexteClassement(); // Cohorte 1 — 2026, 5 filières @ quota 24
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
    }

    private function urlQuotas(): string
    {
        return "/api/admin/campagnes/{$this->campagne->id}/quotas";
    }

    private function urlClassement(): string
    {
        return "/api/admin/campagnes/{$this->campagne->id}/classement";
    }

    /** Lot complet couvrant EXACTEMENT les filières rattachées, une seule modifiée. */
    private function payloadUneModif(string $filiereId, int $nouveauQuota): array
    {
        $quotas = $this->campagne->filieres->map(fn ($f) => [
            'filiere_id' => $f->id,
            'quota' => $f->id === $filiereId ? $nouveauQuota : (int) $f->pivot->quota,
        ])->values()->all();

        return ['quotas' => $quotas];
    }

    // --- Sans classement préalable : édition libre ---------------------

    public function test_modifier_un_quota_sans_classement_reussit_sans_perimer(): void
    {
        $this->campagne->load('filieres');
        $cuisine = $this->campagne->filieres->firstWhere('code', 'cuisine');

        $reponse = $this->actingAs($this->admin)
            ->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, 30))
            ->assertOk();

        $this->assertFalse($reponse->json('data.classement_calcule'));
        $this->assertFalse($reponse->json('data.classement_perime'));
        $this->assertDatabaseHas('campagne_filiere', ['campagne_id' => $this->campagne->id, 'filiere_id' => $cuisine->id, 'quota' => 30]);
        // places_totales dérivé : 5×24 = 120, une filière passe à 30 -> 126.
        $this->assertSame(126, $reponse->json('data.places_totales'));

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'action' => 'Modification des quotas',
            'module' => 'Campagnes',
        ]);
        $this->assertSame(0, JournalAudit::where('action', 'Classement marqué périmé')->count());
    }

    public function test_idempotent_memes_valeurs_aucun_audit_aucune_peremption(): void
    {
        $this->campagne->load('filieres');
        $cuisine = $this->campagne->filieres->firstWhere('code', 'cuisine');

        $this->actingAs($this->admin)
            ->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, 24)) // valeur déjà en place
            ->assertOk()
            ->assertJsonPath('data.classement_perime', false);

        $this->assertSame(0, JournalAudit::where('module', 'Campagnes')->where('action', 'Modification des quotas')->count());
    }

    // --- Classement déjà calculé, pas publié : le point central --------

    public function test_modifier_un_quota_apres_classement_marque_perime_sans_recalculer(): void
    {
        $c1 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0); // 90.0
        $c2 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0); // 70.0

        $this->actingAs($this->admin)->postJson($this->urlClassement())
            ->assertOk()
            ->assertJsonPath('data.perime', false);

        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c1->id, 'rang' => 1, 'decision' => 'retenu']);
        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c2->id, 'rang' => 2, 'decision' => 'retenu']);

        $cuisine = $this->campagne->filieres()->where('code', 'cuisine')->firstOrFail();

        // Le quota baisse à 1 : SI recalcul il y avait, c2 basculerait en liste
        // d'attente. Ce n'est PAS ce qu'on veut ici — la preuve porte sur
        // l'ABSENCE de recalcul silencieux.
        $reponse = $this->actingAs($this->admin)
            ->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, 1))
            ->assertOk();

        $this->assertTrue($reponse->json('data.classement_calcule'));
        $this->assertTrue($reponse->json('data.classement_perime'), 'le classement existant devient périmé, structurellement');

        // Les décisions PERSISTÉES n'ont PAS bougé : aucun recalcul silencieux.
        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c1->id, 'rang' => 1, 'decision' => 'retenu']);
        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c2->id, 'rang' => 2, 'decision' => 'retenu']);

        // L'état est reflété aussi côté écran classement, pas seulement la liste.
        $this->actingAs($this->admin)->getJson($this->urlClassement())
            ->assertOk()
            ->assertJsonPath('data.perime', true)
            ->assertJsonPath('data.calcule', true);

        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'action' => 'Classement marqué périmé',
            'module' => 'Classement',
            'objet' => $this->campagne->nom,
        ]);
    }

    public function test_un_recalcul_explicite_leve_la_peremption(): void
    {
        $c1 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $c2 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 50.0, 20.0);
        $this->actingAs($this->admin)->postJson($this->urlClassement())->assertOk();

        $cuisine = $this->campagne->filieres()->where('code', 'cuisine')->firstOrFail();
        $this->actingAs($this->admin)->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, 1))
            ->assertOk()->assertJsonPath('data.classement_perime', true);

        // Recalcul EXPLICITE : lève le drapeau ET resynchronise réellement.
        $reponse = $this->actingAs($this->admin)->postJson($this->urlClassement())
            ->assertOk()
            ->assertJsonPath('data.perime', false);

        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c1->id, 'decision' => 'retenu']); // quota=1, rang 1
        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c2->id, 'decision' => 'liste_attente']); // rang 2, hors quota=1 mais dans la liste d'attente (+8)
    }

    public function test_publier_refuse_si_le_classement_est_perime_422(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->actingAs($this->admin)->postJson($this->urlClassement())->assertOk();

        $cuisine = $this->campagne->filieres()->where('code', 'cuisine')->firstOrFail();
        $this->actingAs($this->admin)->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, 5))->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'périmé'));

        $this->assertSame(0, Publication::count());
    }

    public function test_motifs_restent_editables_meme_si_le_classement_est_perime(): void
    {
        $c1 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->actingAs($this->admin)->postJson($this->urlClassement())->assertOk();

        $cuisine = $this->campagne->filieres()->where('code', 'cuisine')->firstOrFail();
        $this->actingAs($this->admin)->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, 3))->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/candidatures/{$c1->id}/decision/motifs", ['motif_interne' => 'Note interne quelconque'])
            ->assertOk();
    }

    // --- Publiée : verrou dur, aucune exception -------------------------

    public function test_publication_deja_faite_bloque_toute_modification_de_quota_409_et_reste_inchangee(): void
    {
        $c1 = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->actingAs($this->admin)->postJson($this->urlClassement())->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")->assertOk();

        $decisionAvant = DecisionCandidature::findOrFail($c1->id)->only(['rang', 'decision']);
        $cuisine = $this->campagne->filieres()->where('code', 'cuisine')->firstOrFail();
        $quotaAvant = (int) $cuisine->pivot->quota;

        $this->actingAs($this->admin)
            ->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, 999))
            ->assertStatus(409);

        // Rien n'a bougé : ni le quota, ni la décision déjà publiée, sans
        // qu'aucun recalcul + republication explicites n'aient eu lieu (la
        // publication elle-même est de toute façon irréversible, Lot 5b).
        $this->assertDatabaseHas('campagne_filiere', ['campagne_id' => $this->campagne->id, 'filiere_id' => $cuisine->id, 'quota' => $quotaAvant]);
        $decisionApres = DecisionCandidature::findOrFail($c1->id)->only(['rang', 'decision']);
        $this->assertSame($decisionAvant, $decisionApres);
        $this->assertSame(1, Publication::count());
    }

    // --- Forme du lot ----------------------------------------------------

    public function test_lot_incomplet_refuse_422(): void
    {
        $this->campagne->load('filieres');
        $quotas = $this->campagne->filieres->map(fn ($f) => ['filiere_id' => $f->id, 'quota' => (int) $f->pivot->quota])->values()->all();
        array_pop($quotas); // une filière rattachée manque à l'appel

        $this->actingAs($this->admin)->putJson($this->urlQuotas(), ['quotas' => $quotas])->assertStatus(422);
    }

    public function test_filiere_etrangere_a_la_campagne_refusee_422(): void
    {
        $autreCampagne = Campagne::create([
            'nom' => 'Cohorte 2 — 2027', 'statut' => 'brouillon',
            'date_ouverture' => '2027-01-01', 'date_cloture' => '2027-02-01', 'places_totales' => 0,
        ]);
        $filiereHorsCampagne = $this->idFiliere('cuisine');
        $autreCampagne->filieres()->attach($filiereHorsCampagne, ['quota' => 10]);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/campagnes/{$autreCampagne->id}/quotas", [
                'quotas' => [['filiere_id' => $this->idFiliere('buanderie'), 'quota' => 5]], // pas rattachée à $autreCampagne
            ])
            ->assertStatus(422);
    }

    public function test_quota_negatif_refuse_422(): void
    {
        $this->campagne->load('filieres');
        $cuisine = $this->campagne->filieres->firstWhere('code', 'cuisine');

        $this->actingAs($this->admin)
            ->putJson($this->urlQuotas(), $this->payloadUneModif($cuisine->id, -1))
            ->assertStatus(422);
    }

    // --- Audit détaillé (Étape 1, Q6) -----------------------------------

    public function test_audit_detaille_liste_chaque_filiere_modifiee_en_une_ligne(): void
    {
        $this->campagne->load('filieres');
        $filieres = $this->campagne->filieres;
        $cuisine = $filieres->firstWhere('code', 'cuisine');
        $buanderie = $filieres->firstWhere('code', 'buanderie');

        $quotas = $filieres->map(fn ($f) => [
            'filiere_id' => $f->id,
            'quota' => match ($f->id) {
                $cuisine->id => 40,
                $buanderie->id => 10,
                default => (int) $f->pivot->quota,
            },
        ])->values()->all();

        $this->actingAs($this->admin)->putJson($this->urlQuotas(), ['quotas' => $quotas])->assertOk();

        $ligne = JournalAudit::where('module', 'Campagnes')->where('action', 'Modification des quotas')->firstOrFail();
        $this->assertStringContainsString(sprintf('%s : 24 → 40', $cuisine->nom), $ligne->nouvelle_valeur);
        $this->assertStringContainsString(sprintf('%s : 24 → 10', $buanderie->nom), $ligne->nouvelle_valeur);
    }

    // --- Autorisation ------------------------------------------------------

    public function test_modification_quotas_admin_only_403(): void
    {
        $this->campagne->load('filieres');
        $cuisine = $this->campagne->filieres->firstWhere('code', 'cuisine');
        $body = $this->payloadUneModif($cuisine->id, 30);

        foreach ([$this->evaluateur, $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->putJson($this->urlQuotas(), $body)->assertStatus(403);
        }
    }
}
