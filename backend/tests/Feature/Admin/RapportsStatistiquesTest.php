<?php

namespace Tests\Feature\Admin;

use App\Models\Campagne;
use App\Models\Candidat;
use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\EvaluationDossier;
use App\Models\Grille;
use App\Models\User;
use Database\Seeders\GrilleBaremeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Lot 11c — RAPPORTS & STATISTIQUES (ADR-30). Ce qui est verrouillé ici :
 *
 *  - admin STRICT — évaluateur (même via recouvrement ADR-10) et candidat → 403,
 *    invité → 401, sur `/rapports` ET `/rapports/export.csv` ;
 *  - garde-fou k-anonymat (k=5) : distribution masquée sous 5, ville < 5 → «
 *    Autres villes », taux masqué si dénominateur < 5, AUCUNE cross-tabulation ;
 *  - le MÊME masquage à l'écran ET au CSV (un seul `ServiceRapports`) ;
 *  - AUCUNE donnée individuelle (nom / CNI / e-mail / n° dossier / score isolé) ;
 *  - brouillons hors périmètre.
 */
class RapportsStatistiquesTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $admin;

    private User $evaluateur;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();               // Cohorte 1 — 2026, ouverte
        $this->seed(GrilleBaremeSeeder::class);
        $this->admin = $this->creerAdmin('coordination@cci.ci');
        $this->evaluateur = $this->creerEvaluateur('jury@cci.ci');
    }

    private function campagneCourante(): Campagne
    {
        return Campagne::query()->where('statut', 'ouverte')->firstOrFail();
    }

    /**
     * Candidature SOUMISE (non évaluée par défaut) avec sexe / ville / filière /
     * éligibilité maîtrisés. `score` non nul ⇒ candidature évaluée (dossier +
     * entretien figés). `decision` ⇒ decision_candidature.
     *
     * @param  array{sexe?:string, ville?:string, filiere?:string, eligibilite?:string, score?:float, presence?:string, decision?:string, statut?:string}  $opts
     */
    private function candidature(?Campagne $campagne = null, array $opts = []): Candidature
    {
        $campagne ??= $this->campagneCourante();

        $user = User::factory()->create(['role' => 'candidat']);
        Candidat::create([
            'utilisateur_id' => $user->id,
            'prenom' => 'Prenom'.$this->seq, 'nom' => 'Nom'.$this->seq,
            'sexe' => $opts['sexe'] ?? 'F',
            'date_naissance' => '2003-01-01',
            'cni' => 'CI'.str_pad((string) (900000000 + $this->seq), 9, '0', STR_PAD_LEFT),
            'telephone' => '0700000000',
            'ville_residence' => $opts['ville'] ?? 'Abidjan',
            'residence_ci' => true,
        ]);

        $candidature = Candidature::create([
            'candidat_id' => $user->candidat->id,
            'campagne_id' => $campagne->id,
            'filiere_id' => $this->idFiliere($opts['filiere'] ?? 'cuisine'),
            'numero_dossier' => sprintf('CASA-2026-%06d', ++$this->seq),
        ]);

        $statut = $opts['statut'] ?? (isset($opts['score']) ? 'evalue' : 'soumis');
        $candidature->forceFill([
            'statut_interne' => $statut,
            'statut_eligibilite_interne' => $opts['eligibilite'] ?? 'eligible',
            'date_soumission' => now()->subDays(10),
        ])->saveQuietly();

        if (isset($opts['score'])) {
            $grilleId = Grille::active()->id;
            $membreId = $this->evaluateur->membreEquipe->id;
            EvaluationDossier::create([
                'candidature_id' => $candidature->id, 'grille_id' => $grilleId,
                'score_total' => number_format($opts['score'] * 0.6, 1, '.', ''),
                'valide' => true, 'valide_le' => now(), 'valide_par' => $membreId,
            ]);
            $candidature->entretien()->create([
                'statut' => 'valide', 'date' => '2026-07-06', 'heure' => '09:00', 'lieu' => 'Le Plateau',
                'evaluateur_id' => $membreId, 'presence' => $opts['presence'] ?? 'present',
                'grille_id' => $grilleId, 'score_total' => number_format($opts['score'] * 0.4, 1, '.', ''),
                'valide_le' => now(), 'valide_par' => $membreId,
            ]);
        }

        if (isset($opts['decision'])) {
            DecisionCandidature::create([
                'candidature_id' => $candidature->id,
                'rang' => $this->seq,
                'decision' => $opts['decision'],
            ]);
        }

        return $candidature->fresh();
    }

    // --- Matrice d'autorisation -----------------------------------------

    public function test_admin_seul_accede_evaluateur_et_candidat_403_invite_401(): void
    {
        $urls = ['/api/admin/rapports', '/api/admin/rapports/export.csv'];
        $candidat = $this->creerCandidat('c@cci.ci');

        // Invité D'ABORD — aucun `actingAs` avant (l'état d'auth persiste sur $this).
        foreach ($urls as $url) {
            $this->getJson($url)->assertStatus(401);
        }

        foreach ($urls as $url) {
            $this->actingAs($candidat)->getJson($url)->assertStatus(403);
            // Évaluateur : 403 MÊME s'il a le recouvrement sur l'espace évaluation.
            $this->actingAs($this->evaluateur)->getJson($url)->assertStatus(403);
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    // --- Garde-fou k=5 : distributions masquées ------------------------

    public function test_sous_le_seuil_les_repartitions_sont_masquees(): void
    {
        $this->candidature(opts: ['sexe' => 'F']);
        $this->candidature(opts: ['sexe' => 'H']);
        $this->candidature(opts: ['sexe' => 'F']);

        $r = $this->actingAs($this->admin)->getJson('/api/admin/rapports')->assertOk()->json('data');

        $this->assertNull($r['repartition_sexe'], 'F/H masqué sous 5');
        $this->assertNull($r['distribution_scores'], 'distribution masquée (0 évaluée)');
        $this->assertNull($r['top_villes'], 'villes masquées sous 5');
        $this->assertSame(3, $r['perimetre']['candidatures']);
        $this->assertSame(5, $r['perimetre']['seuil_masquage']);
    }

    public function test_au_dessus_du_seuil_les_repartitions_apparaissent(): void
    {
        foreach (['F', 'F', 'F', 'H', 'H'] as $sexe) {
            $this->candidature(opts: ['sexe' => $sexe]);
        }

        $r = $this->actingAs($this->admin)->getJson('/api/admin/rapports')->assertOk()->json('data');

        $this->assertSame(['F' => 3, 'H' => 2], $r['repartition_sexe']);
    }

    public function test_une_ville_sous_le_seuil_est_fondue_dans_autres_villes(): void
    {
        foreach (range(1, 5) as $i) {
            $this->candidature(opts: ['ville' => 'Abidjan']);
        }
        $this->candidature(opts: ['ville' => 'Bouaké']);
        $this->candidature(opts: ['ville' => 'Bouaké']);

        $villes = collect(
            $this->actingAs($this->admin)->getJson('/api/admin/rapports')->assertOk()->json('data.top_villes')
        )->keyBy('ville');

        $this->assertSame(5, $villes['Abidjan']['candidatures']);
        $this->assertArrayNotHasKey('Bouaké', $villes->all(), 'une ville < 5 ne doit jamais être nommée');
        $this->assertSame(2, $villes['Autres villes']['candidatures']);
    }

    public function test_un_taux_est_masque_si_son_denominateur_est_sous_le_seuil(): void
    {
        // 6 vérifiées (⇒ taux d'éligibilité calculable), mais 3 évaluées seulement.
        foreach (range(1, 3) as $i) {
            $this->candidature(opts: ['eligibilite' => 'eligible']);
        }
        foreach (range(1, 3) as $i) {
            $this->candidature(opts: ['eligibilite' => 'eligible', 'score' => 70.0, 'decision' => 'retenu']);
        }

        $kpis = $this->actingAs($this->admin)->getJson('/api/admin/rapports')->assertOk()->json('data.kpis');

        $this->assertNotNull($kpis['taux_eligibilite'], 'dénominateur 6 ≥ 5');
        $this->assertNull($kpis['taux_selection'], 'dénominateur (évaluées = 3) < 5');
    }

    // --- Aucune cross-tab, aucune donnée individuelle -----------------

    public function test_par_filiere_ne_porte_aucun_croisement(): void
    {
        $this->candidature(opts: ['filiere' => 'cuisine', 'score' => 80.0, 'decision' => 'retenu', 'sexe' => 'F']);
        $this->candidature(opts: ['filiere' => 'buanderie', 'sexe' => 'H']);

        $parFiliere = $this->actingAs($this->admin)->getJson('/api/admin/rapports')->assertOk()->json('data.par_filiere');

        foreach ($parFiliere as $ligne) {
            $this->assertSame(['filiere', 'candidatures'], array_keys($ligne));
            $this->assertSame(['code', 'nom'], array_keys($ligne['filiere']));
        }
    }

    public function test_aucune_donnee_individuelle_dans_la_reponse(): void
    {
        foreach (range(1, 6) as $i) {
            $this->candidature(opts: ['score' => 60.0 + $i, 'decision' => 'retenu']);
        }

        $body = $this->actingAs($this->admin)->getJson('/api/admin/rapports')->assertOk()->getContent();

        foreach (['Prenom', 'Nom0', 'Nom1', 'CI9000', 'CASA-2026-', 'candidat_id', 'numero_dossier', 'score_final', '"rang"', 'motif_interne', 'utilisateur_id', 'mot_de_passe'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $body, "fuite potentielle : {$interdit}");
        }
    }

    public function test_les_brouillons_sont_hors_perimetre(): void
    {
        $this->candidature(opts: ['statut' => 'brouillon']);
        $this->candidature(opts: ['statut' => 'soumis']);

        $r = $this->actingAs($this->admin)->getJson('/api/admin/rapports')->assertOk()->json('data');
        $this->assertSame(1, $r['perimetre']['candidatures']);
    }

    // --- Filtre campagne ---------------------------------------------

    public function test_le_filtre_campagne_restreint_le_perimetre(): void
    {
        $autre = Campagne::create([
            'nom' => 'Cohorte 2 — 2027', 'statut' => 'brouillon',
            'date_ouverture' => '2027-01-01', 'date_cloture' => '2027-03-01', 'places_totales' => 50,
        ]);
        $autre->filieres()->attach($this->idFiliere('cuisine'), ['quota' => 10]);

        $this->candidature($this->campagneCourante());
        $this->candidature($this->campagneCourante());
        $this->candidature($autre);

        $courante = $this->actingAs($this->admin)->getJson('/api/admin/rapports')->json('data.perimetre.candidatures');
        $this->assertSame(2, $courante, 'défaut = campagne courante (ouverte)');

        $filtree = $this->actingAs($this->admin)->getJson("/api/admin/rapports?campagne={$autre->id}")->json('data.perimetre.candidatures');
        $this->assertSame(1, $filtree);

        $toutes = $this->actingAs($this->admin)->getJson('/api/admin/rapports?campagne=toutes')->json('data');
        $this->assertSame(3, $toutes['perimetre']['candidatures']);
        $this->assertTrue($toutes['perimetre']['toutes_campagnes']);

        $this->actingAs($this->admin)->getJson('/api/admin/rapports?campagne=00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    // --- CSV : même garde-fou -------------------------------------

    public function test_le_csv_applique_le_meme_masquage_que_l_ecran(): void
    {
        // Base de 3 → tout est masqué.
        $this->candidature(opts: ['sexe' => 'F']);
        $this->candidature(opts: ['sexe' => 'H']);
        $this->candidature(opts: ['sexe' => 'F']);

        $reponse = $this->actingAs($this->admin)->get('/api/admin/rapports/export.csv')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $reponse->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename=', (string) $reponse->headers->get('content-disposition'));

        $csv = $reponse->getContent();
        $this->assertStringContainsString('Femmes;n/d', $csv);
        $this->assertStringContainsString('Hommes;n/d', $csv);
        $this->assertStringContainsString('n/d', $csv);
        // Jamais un nom de candidat dans le CSV.
        foreach (['Prenom0', 'Nom0', 'CASA-2026-'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $csv);
        }
    }

    public function test_le_csv_contient_les_valeurs_reelles_au_dessus_du_seuil(): void
    {
        foreach (['F', 'F', 'F', 'H', 'H'] as $sexe) {
            $this->candidature(opts: ['sexe' => $sexe]);
        }

        $csv = $this->actingAs($this->admin)->get('/api/admin/rapports/export.csv')->assertOk()->getContent();

        $this->assertStringContainsString('Femmes;3', $csv);
        $this->assertStringContainsString('Hommes;2', $csv);
        $this->assertStringNotContainsString('Femmes;n/d', $csv);
    }
}
