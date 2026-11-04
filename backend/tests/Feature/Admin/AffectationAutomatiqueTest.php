<?php

namespace Tests\Feature\Admin;

use App\Domain\Affectation\ServiceAffectationAutomatique;
use App\Exceptions\AffectationAutomatiqueRefusee;
use App\Models\Campagne;
use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Affectation automatique par critères (ADR-45) — un cas d'échec par
 * garde-fou, répartition déterministe et équilibrée, idempotence, journal,
 * volume. Données FICTIVES uniquement.
 */
class AffectationAutomatiqueTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    private Campagne $campagne;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->seedBareme();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->campagne = Campagne::ouverte()->firstOrFail();
    }

    /**
     * Dossier fictif rapide (sans passer par le wizard) — l'état interne est
     * posé directement ; un test dédié couvre le VRAI chemin de soumission.
     *
     * @param  array<string, mixed>  $etat
     */
    private function dossier(array $etat = [], ?Campagne $campagne = null, string $filiere = 'cuisine', string $nom = 'Candidat'): Candidature
    {
        $candidat = $this->creerCandidat(profil: ['nom' => $nom]);

        $candidature = Candidature::create([
            'candidat_id' => $candidat->candidat->id,
            'campagne_id' => ($campagne ?? $this->campagne)->id,
            'filiere_id' => $this->idFiliere($filiere),
            'numero_dossier' => sprintf('CASA-TEST-%06d', ++$this->sequence),
            'cqp_confirme' => false,
        ]);
        $candidature->forceFill(array_merge([
            'statut_interne' => 'soumis',
            'statut_eligibilite_interne' => 'eligible',
            'date_soumission' => now(),
        ], $etat))->saveQuietly();

        return $candidature->fresh();
    }

    /**
     * @param  array<string, mixed>  $corps
     */
    private function apercu(array $corps): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/affectations/automatique/apercu', $corps);
    }

    /**
     * @param  array<string, mixed>  $corps
     */
    private function executer(array $corps): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/affectations/automatique', $corps);
    }

    /**
     * Aperçu puis exécution avec les nombres de l'aperçu (parcours nominal).
     *
     * @param  array<string, mixed>  $criteres
     * @param  list<string>|null  $evaluateurIds
     */
    private function apercuPuisExecution(array $criteres, int $n, ?array $evaluateurIds = null): TestResponse
    {
        $corps = ['criteres' => $criteres, 'nombre_correspondants' => $n];
        if ($evaluateurIds !== null) {
            $corps['evaluateur_ids'] = $evaluateurIds;
        }
        $m = $this->apercu($corps)->assertOk()->json('data.nombre_affectables');

        return $this->executer([...$corps, 'nombre_affectables' => $m]);
    }

    private function nombreAffectations(): int
    {
        return JournalAudit::where('action', 'Affectation évaluateur')->count();
    }

    // --- Parcours nominal -------------------------------------------------

    public function test_l_apercu_annonce_la_repartition_sans_rien_ecrire(): void
    {
        $e1 = $this->creerEvaluateur('e1@casa-demo.ci');
        $e2 = $this->creerEvaluateur('e2@casa-demo.ci');
        foreach (range(1, 5) as $_) {
            $this->dossier();
        }

        $data = $this->apercu(['criteres' => [], 'nombre_correspondants' => 5])->assertOk()->json('data');

        $this->assertSame(5, $data['nombre_correspondants']);
        $this->assertSame(5, $data['nombre_affectables']);
        $this->assertSame(['deja_affectes' => 0, 'non_eligibles' => 0, 'statut_non_affectable' => 0], $data['ignores']);
        $this->assertEqualsCanonicalizing([3, 2], array_column($data['evaluateurs'], 'a_recevoir'));
        $this->assertEqualsCanonicalizing(
            [$e1->membreEquipe->id, $e2->membreEquipe->id],
            array_column($data['evaluateurs'], 'id'),
        );

        // Rien d'écrit.
        $this->assertSame(5, Candidature::whereNull('evaluateur_id')->where('statut_interne', 'soumis')->count());
        $this->assertSame(0, JournalAudit::where('action', 'like', 'Affectation%')->count());
    }

    public function test_execution_repartit_equitablement_et_bascule_en_instruction(): void
    {
        $evaluateurs = collect(range(1, 3))->map(fn ($i) => $this->creerEvaluateur("e{$i}@casa-demo.ci"));
        foreach (range(1, 10) as $_) {
            $this->dossier();
        }

        $this->apercuPuisExecution([], 10)
            ->assertOk()
            ->assertJsonPath('data.affectees', 10);

        $parEvaluateur = Candidature::query()->pluck('evaluateur_id')->countBy();
        $this->assertSame(3, $parEvaluateur->count());
        $this->assertEqualsCanonicalizing([4, 3, 3], $parEvaluateur->values()->all());
        $this->assertSame(10, Candidature::where('statut_interne', 'en_instruction')->count());
        foreach ($evaluateurs as $e) {
            $this->assertTrue($parEvaluateur->has($e->membreEquipe->id));
        }
    }

    public function test_repartition_deterministe_par_numero_de_dossier_et_ordre_nom_prenom(): void
    {
        // Même nom « Test » (fabrique) : départage par prénom → « Eval » < …
        // On renomme pour fixer l'ordre : Alpha avant Beta.
        $beta = $this->creerEvaluateur('beta@casa-demo.ci');
        $beta->membreEquipe->forceFill(['nom' => 'Beta'])->save();
        $alpha = $this->creerEvaluateur('alpha@casa-demo.ci');
        $alpha->membreEquipe->forceFill(['nom' => 'Alpha'])->save();
        $d = collect(range(1, 3))->map(fn () => $this->dossier());

        $this->apercuPuisExecution([], 3)->assertOk();

        // Tourniquet sur le moins chargé, numéro croissant, égalité → Alpha.
        $this->assertSame($alpha->membreEquipe->id, $d[0]->fresh()->evaluateur_id);
        $this->assertSame($beta->membreEquipe->id, $d[1]->fresh()->evaluateur_id);
        $this->assertSame($alpha->membreEquipe->id, $d[2]->fresh()->evaluateur_id);
    }

    public function test_le_vrai_chemin_soumission_puis_affectation_automatique_ouvre_le_dossier_a_l_evaluateur(): void
    {
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $candidat = $this->creerCandidat();
        $id = $this->actingAs($candidat)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $this->rendreCandidatureComplete(Candidature::findOrFail($id));
        $this->actingAs($candidat)->postJson("/api/candidatures/{$id}/soumettre")->assertOk();

        $this->apercuPuisExecution(['evaluateur' => 'non_affecte'], 1)->assertOk();

        $this->actingAs($evaluateur)
            ->getJson("/api/evaluateur/candidatures/{$id}")
            ->assertOk()
            ->assertJsonPath('data.statut_interne', 'en_instruction');
    }

    // --- Ensemble concerné ------------------------------------------------

    public function test_deja_affectes_non_eligibles_et_brouillons_sont_ignores(): void
    {
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $autre = $this->creerEvaluateur('autre@casa-demo.ci');
        $libre = $this->dossier();
        $dejaAffecte = $this->dossier(['statut_interne' => 'en_instruction', 'evaluateur_id' => $autre->membreEquipe->id]);
        $soumisNonEligible = $this->dossier(['statut_interne' => 'non_eligible', 'statut_eligibilite_interne' => 'non_eligible']);
        $elimine = $this->dossier();
        $this->actingAs($this->admin)
            ->postJson("/api/admin/candidatures/{$elimine->id}/elimination", ['motif' => 'Motif de test'])
            ->assertOk();
        $this->dossier(['statut_interne' => 'brouillon', 'date_soumission' => null]);

        // 4 correspondants (brouillon exclu, comme dans la liste).
        $data = $this->apercu(['criteres' => [], 'nombre_correspondants' => 4, 'evaluateur_ids' => [$evaluateur->membreEquipe->id]])
            ->assertOk()->json('data');
        $this->assertSame(1, $data['nombre_affectables']);
        $this->assertSame(['deja_affectes' => 1, 'non_eligibles' => 2, 'statut_non_affectable' => 0], $data['ignores']);

        $this->apercuPuisExecution([], 4, [$evaluateur->membreEquipe->id])->assertOk();

        $this->assertSame($evaluateur->membreEquipe->id, $libre->fresh()->evaluateur_id);
        // Déjà affecté : JAMAIS réaffecté.
        $this->assertSame($autre->membreEquipe->id, $dejaAffecte->fresh()->evaluateur_id);
        $this->assertNull($soumisNonEligible->fresh()->evaluateur_id);
        $this->assertNull($elimine->fresh()->evaluateur_id);
        $this->assertSame('soumis', $elimine->fresh()->statut_interne);
    }

    public function test_n_est_exactement_le_total_de_la_liste_pour_les_memes_criteres(): void
    {
        $this->creerEvaluateur('eval@casa-demo.ci');
        $this->dossier(nom: 'Kouassi');
        $this->dossier(nom: 'Kouassi', filiere: 'restaurant-bar');
        $this->dossier(nom: 'Yao');

        foreach ([['recherche' => 'kouassi'], ['filiere' => 'cuisine'], ['recherche' => 'kouassi', 'filiere' => 'cuisine'], []] as $criteres) {
            $total = $this->actingAs($this->admin)->postJson('/api/admin/candidatures', $criteres)->assertOk()->json('meta.total');

            $this->apercu(['criteres' => $criteres, 'nombre_correspondants' => $total])
                ->assertOk()
                ->assertJsonPath('data.nombre_correspondants', $total);
        }
    }

    public function test_la_charge_existante_est_celle_de_la_campagne_concernee_seulement(): void
    {
        $charge = $this->creerEvaluateur('charge@casa-demo.ci');
        $charge->membreEquipe->forceFill(['nom' => 'Alpha'])->save();
        $libre = $this->creerEvaluateur('libre@casa-demo.ci');
        $libre->membreEquipe->forceFill(['nom' => 'Beta'])->save();

        // Alpha a déjà 2 dossiers en instruction dans CETTE campagne…
        $this->dossier(['statut_interne' => 'en_instruction', 'evaluateur_id' => $charge->membreEquipe->id]);
        $this->dossier(['statut_interne' => 'en_instruction', 'evaluateur_id' => $charge->membreEquipe->id]);
        // … et Beta en a 5 dans une AUTRE campagne (cohorte précédente) + 1 évalué ici : ignorés.
        $ancienne = Campagne::create([
            'nom' => 'Cohorte test précédente', 'statut' => 'cloturee',
            'date_ouverture' => '2025-01-01', 'date_cloture' => '2025-02-01', 'places_totales' => 10,
        ]);
        foreach (range(1, 5) as $_) {
            $this->dossier(['statut_interne' => 'en_instruction', 'evaluateur_id' => $libre->membreEquipe->id], $ancienne);
        }
        $this->dossier(['statut_interne' => 'evalue', 'evaluateur_id' => $libre->membreEquipe->id]);
        $nouveaux = collect(range(1, 4))->map(fn () => $this->dossier());

        $data = $this->apercu(['criteres' => ['campagne' => $this->campagne->id, 'evaluateur' => 'non_affecte'], 'nombre_correspondants' => 4])
            ->assertOk()->json('data');
        $lignes = collect($data['evaluateurs'])->keyBy('nom');
        $this->assertSame(2, $lignes['Alpha']['charge_actuelle']);
        $this->assertSame(0, $lignes['Beta']['charge_actuelle']);
        // 4 dossiers : Beta comble l'écart (2), puis 1 chacun → Alpha 1, Beta 3.
        $this->assertSame(1, $lignes['Alpha']['a_recevoir']);
        $this->assertSame(3, $lignes['Beta']['a_recevoir']);
        $this->assertSame(3, $lignes['Alpha']['charge_finale']);
        $this->assertSame(3, $lignes['Beta']['charge_finale']);

        $this->apercuPuisExecution(['campagne' => $this->campagne->id, 'evaluateur' => 'non_affecte'], 4)->assertOk();
        $recus = Candidature::whereIn('id', $nouveaux->pluck('id'))->pluck('evaluateur_id')->countBy();
        $this->assertSame(1, $recus[$charge->membreEquipe->id]);
        $this->assertSame(3, $recus[$libre->membreEquipe->id]);
    }

    // --- Évaluateurs ------------------------------------------------------

    public function test_par_defaut_seuls_les_evaluateurs_actifs_recoivent(): void
    {
        $actif = $this->creerEvaluateur('actif@casa-demo.ci');
        $inactif = $this->creerEvaluateur('inactif@casa-demo.ci');
        $inactif->forceFill(['actif' => false])->save();
        foreach (range(1, 4) as $_) {
            $this->dossier();
        }

        $this->apercuPuisExecution([], 4)->assertOk();

        $this->assertSame(4, Candidature::where('evaluateur_id', $actif->membreEquipe->id)->count());
        $this->assertSame(0, Candidature::where('evaluateur_id', $inactif->membreEquipe->id)->count());
    }

    public function test_restriction_a_une_partie_des_evaluateurs(): void
    {
        $choisi = $this->creerEvaluateur('choisi@casa-demo.ci');
        $exclu = $this->creerEvaluateur('exclu@casa-demo.ci');
        foreach (range(1, 3) as $_) {
            $this->dossier();
        }

        $this->apercuPuisExecution([], 3, [$choisi->membreEquipe->id])->assertOk();

        $this->assertSame(3, Candidature::where('evaluateur_id', $choisi->membreEquipe->id)->count());
        $this->assertSame(0, Candidature::where('evaluateur_id', $exclu->membreEquipe->id)->count());
    }

    public function test_evaluateur_restreint_inactif_admin_ou_inconnu_422(): void
    {
        $this->creerEvaluateur('ok@casa-demo.ci');
        $inactif = $this->creerEvaluateur('inactif@casa-demo.ci');
        $inactif->forceFill(['actif' => false])->save();
        $this->dossier();

        foreach ([$inactif->membreEquipe->id, $this->admin->membreEquipe->id, (string) Str::uuid()] as $intrus) {
            $this->apercu(['criteres' => [], 'nombre_correspondants' => 1, 'evaluateur_ids' => [$intrus]])
                ->assertStatus(422)
                ->assertJsonPath('code', 'evaluateur_invalide');
        }
        $this->assertSame(0, $this->nombreAffectations());
    }

    public function test_aucun_evaluateur_actif_422(): void
    {
        $inactif = $this->creerEvaluateur('inactif@casa-demo.ci');
        $inactif->forceFill(['actif' => false])->save();
        $this->dossier();

        $this->apercu(['criteres' => [], 'nombre_correspondants' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'aucun_evaluateur');
        $this->executer(['criteres' => [], 'nombre_correspondants' => 1, 'nombre_affectables' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'aucun_evaluateur');
        $this->assertNull(Candidature::first()->evaluateur_id);
    }

    // --- Garde-fous de cohérence -------------------------------------------

    public function test_nombre_correspondants_different_409_rien_ecrit(): void
    {
        $this->creerEvaluateur('eval@casa-demo.ci');
        $this->dossier();
        $this->dossier();

        $this->apercu(['criteres' => [], 'nombre_correspondants' => 3])
            ->assertStatus(409)->assertJsonPath('code', 'liste_modifiee');
        $this->executer(['criteres' => [], 'nombre_correspondants' => 3, 'nombre_affectables' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'liste_modifiee');

        $this->assertSame(0, Candidature::whereNotNull('evaluateur_id')->count());
        $this->assertSame(0, $this->nombreAffectations());
    }

    public function test_nombre_affectables_different_409_rien_ecrit(): void
    {
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->dossier();
        $entreTemps = $this->dossier();
        $m = $this->apercu(['criteres' => [], 'nombre_correspondants' => 2])->json('data.nombre_affectables');
        $this->assertSame(2, $m);

        // Entre l'aperçu et la confirmation, un dossier est affecté à la main :
        // N inchangé (2), M passe à 1.
        $this->actingAs($this->admin)->postJson('/api/admin/affectations', [
            'evaluateur_id' => $evaluateur->membreEquipe->id,
            'candidature_ids' => [$entreTemps->id],
        ])->assertOk();
        $avant = $this->nombreAffectations();

        $this->executer(['criteres' => [], 'nombre_correspondants' => 2, 'nombre_affectables' => $m])
            ->assertStatus(409)->assertJsonPath('code', 'liste_modifiee');

        $this->assertSame(1, Candidature::whereNull('evaluateur_id')->count());
        $this->assertSame($avant, $this->nombreAffectations());
        $this->assertSame(0, JournalAudit::where('action', 'Affectation automatique')->count());
    }

    public function test_relancer_ne_double_pas_les_affectations(): void
    {
        $this->creerEvaluateur('e1@casa-demo.ci');
        $this->creerEvaluateur('e2@casa-demo.ci');
        foreach (range(1, 4) as $_) {
            $this->dossier();
        }
        $corps = ['criteres' => [], 'nombre_correspondants' => 4, 'nombre_affectables' => 4];

        $this->executer($corps)->assertOk();
        $etat = Candidature::orderBy('id')->pluck('evaluateur_id', 'id')->all();

        // Même requête rejouée (double clic, rejeu réseau) : 409, rien ne bouge.
        $this->executer($corps)->assertStatus(409)->assertJsonPath('code', 'liste_modifiee');
        // Nouvel aperçu : plus rien d'affectable.
        $this->apercu(['criteres' => [], 'nombre_correspondants' => 4])
            ->assertStatus(422)->assertJsonPath('code', 'rien_a_affecter');

        $this->assertSame($etat, Candidature::orderBy('id')->pluck('evaluateur_id', 'id')->all());
        $this->assertSame(4, $this->nombreAffectations());
        $this->assertSame(1, JournalAudit::where('action', 'Affectation automatique')->count());
    }

    public function test_campagne_publiee_409_campagne_cloturee_autorisee(): void
    {
        $this->creerEvaluateur('eval@casa-demo.ci');
        $this->dossier();
        $this->campagne->forceFill(['statut' => 'cloturee'])->save();

        // Clôturée : cas normal, autorisé.
        $this->apercu(['criteres' => [], 'nombre_correspondants' => 1])->assertOk();

        $this->dossier();
        Publication::create([
            'campagne_id' => $this->campagne->id,
            'publiee_le' => now(),
            'publiee_par' => $this->admin->membreEquipe->id,
        ]);

        $this->apercu(['criteres' => [], 'nombre_correspondants' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'campagne_publiee');
        $this->executer(['criteres' => [], 'nombre_correspondants' => 2, 'nombre_affectables' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'campagne_publiee');
        $this->assertSame(0, Candidature::whereNotNull('evaluateur_id')->count());
    }

    public function test_au_dela_du_plafond_422_avec_message_d_affiner(): void
    {
        $this->creerEvaluateur('eval@casa-demo.ci');
        $this->insererDossiersEnMasse(ServiceAffectationAutomatique::PLAFOND_DOSSIERS + 1);
        $n = ServiceAffectationAutomatique::PLAFOND_DOSSIERS + 1;

        $reponse = $this->apercu(['criteres' => [], 'nombre_correspondants' => $n])
            ->assertStatus(422)->assertJsonPath('code', 'plafond_depasse');
        $this->assertStringContainsString('affinez vos filtres', $reponse->json('message'));
        $this->executer(['criteres' => [], 'nombre_correspondants' => $n, 'nombre_affectables' => $n])
            ->assertStatus(422)->assertJsonPath('code', 'plafond_depasse');

        $this->assertSame(0, Candidature::whereNotNull('evaluateur_id')->count());
    }

    public function test_critere_hors_liste_blanche_ou_liste_d_identifiants_422(): void
    {
        $this->creerEvaluateur('eval@casa-demo.ci');
        $this->dossier();

        foreach ([['tri_colonne' => 'candidat'], ['inclure_brouillons' => true], ['page' => 2], ['eligibilite' => 'invente']] as $criteres) {
            $this->apercu(['criteres' => $criteres, 'nombre_correspondants' => 1])->assertStatus(422);
        }
        $this->apercu(['nombre_correspondants' => 1])->assertStatus(422)->assertJsonValidationErrors('criteres');
        $this->executer(['criteres' => [], 'nombre_correspondants' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('nombre_affectables');
        $this->assertSame(0, $this->nombreAffectations());
    }

    // --- Journal, confidentialité, droits ------------------------------------

    public function test_journal_une_ligne_globale_chiffree_et_une_ligne_par_dossier(): void
    {
        $this->creerEvaluateur('e1@casa-demo.ci');
        $this->creerEvaluateur('e2@casa-demo.ci');
        $dossiers = collect(range(1, 3))->map(fn () => $this->dossier(nom: 'Fictif'));

        $this->apercuPuisExecution([], 3)->assertOk();

        $globale = JournalAudit::where('action', 'Affectation automatique')->sole();
        $this->assertSame('Candidatures', $globale->module);
        $this->assertSame('3 dossiers', $globale->objet);
        $this->assertSame('3 dossiers répartis entre 2 évaluateurs', $globale->nouvelle_valeur);
        // Nombres uniquement : ni nom d'évaluateur, ni numéro, ni candidat.
        foreach (['Eval', 'Test', 'Fictif', 'CASA-'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $globale->objet.$globale->nouvelle_valeur.$globale->ancienne_valeur);
        }

        foreach ($dossiers as $d) {
            $this->assertDatabaseHas('journal_audit', [
                'action' => 'Affectation évaluateur',
                'objet' => $d->numero_dossier,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => 'Eval Test',
                'motif' => 'Répartition automatique',
            ]);
        }
    }

    public function test_la_reponse_ne_contient_aucune_donnee_de_candidat(): void
    {
        $this->creerEvaluateur('eval@casa-demo.ci');
        $d = $this->dossier(nom: 'Nomfictifunique');

        foreach ([$this->apercu(['criteres' => ['recherche' => 'nomfictifunique'], 'nombre_correspondants' => 1]),
            $this->apercuPuisExecution(['recherche' => 'nomfictifunique'], 1)] as $reponse) {
            $reponse->assertOk();
            foreach (['Nomfictifunique', $d->numero_dossier, $d->id, 'candidat'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $reponse->getContent());
            }
        }
    }

    public function test_un_refus_n_est_jamais_journalise_dans_les_logs_applicatifs(): void
    {
        Exceptions::fake();
        $this->creerEvaluateur('eval@casa-demo.ci');
        $this->dossier();

        $this->apercu(['criteres' => [], 'nombre_correspondants' => 2])->assertStatus(409);

        Exceptions::assertNotReported(AffectationAutomatiqueRefusee::class);
    }

    public function test_affectation_automatique_admin_only(): void
    {
        $evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->dossier();
        $corps = ['criteres' => [], 'nombre_correspondants' => 1, 'nombre_affectables' => 1];

        foreach ([$evaluateur, $this->creerCandidat('c@casa-demo.ci')] as $intrus) {
            $this->actingAs($intrus)->postJson('/api/admin/affectations/automatique/apercu', $corps)->assertStatus(403);
            $this->actingAs($intrus)->postJson('/api/admin/affectations/automatique', $corps)->assertStatus(403);
        }
        $this->assertNull(Candidature::first()->evaluateur_id);
    }

    public function test_volume_quelques_centaines_de_dossiers_en_une_transaction(): void
    {
        foreach (range(1, 4) as $i) {
            $this->creerEvaluateur("e{$i}@casa-demo.ci");
        }
        $this->insererDossiersEnMasse(300);

        $debut = microtime(true);
        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });
        $this->executer(['criteres' => [], 'nombre_correspondants' => 300, 'nombre_affectables' => 300])
            ->assertOk()->assertJsonPath('data.affectees', 300);
        $duree = microtime(true) - $debut;

        $this->assertEqualsCanonicalizing([75, 75, 75, 75], Candidature::pluck('evaluateur_id')->countBy()->values()->all());
        $this->assertSame(300, $this->nombreAffectations());
        // Écritures candidature : 1 UPDATE par évaluateur (pas 1 par dossier) ;
        // le reste = lectures fixes + 301 lignes de journal.
        $this->assertLessThan(301 + 30, $requetes);
        $this->assertLessThan(30.0, $duree, "Durée : {$duree} s");
        fwrite(STDERR, sprintf("\n[volume] 300 dossiers / 4 évaluateurs : %.2f s, %d requêtes SQL\n", $duree, $requetes));
    }

    /**
     * Insertion SQL directe (sans modèle) de dossiers `soumis` non affectés —
     * uniquement pour les tests de volume. Données fictives.
     */
    private function insererDossiersEnMasse(int $nombre): void
    {
        $filiere = $this->idFiliere('cuisine');
        $now = now();

        foreach (array_chunk(range(1, $nombre), 250) as $tranche) {
            $utilisateurs = $candidats = $candidatures = [];
            foreach ($tranche as $i) {
                $u = (string) Str::uuid();
                $c = (string) Str::uuid();
                $utilisateurs[] = ['id' => $u, 'email' => "masse{$i}@casa-demo.ci", 'mot_de_passe_hash' => 'x', 'role' => 'candidat', 'actif' => true, 'created_at' => $now, 'updated_at' => $now];
                $candidats[] = [
                    'id' => $c, 'utilisateur_id' => $u, 'prenom' => 'Test', 'nom' => 'Masse', 'recherche_normalisee' => 'test masse',
                    'sexe' => 'F', 'date_naissance' => '2004-01-01', 'cni' => sprintf('CI%09d', $i), 'telephone' => '0700000000',
                    'ville_residence' => 'Abidjan', 'residence_ci' => true, 'created_at' => $now, 'updated_at' => $now,
                ];
                $candidatures[] = [
                    'id' => (string) Str::uuid(), 'candidat_id' => $c, 'campagne_id' => $this->campagne->id, 'filiere_id' => $filiere,
                    'numero_dossier' => sprintf('CASA-MASSE-%06d', $i), 'statut_interne' => 'soumis',
                    'statut_eligibilite_interne' => 'eligible', 'date_soumission' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('utilisateur')->insert($utilisateurs);
            DB::table('candidat')->insert($candidats);
            DB::table('candidature')->insert($candidatures);
        }
    }
}
