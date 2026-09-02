<?php

namespace Tests\Feature\Candidat;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\EvaluationDossier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Admin\CreeContexteClassement;
use Tests\TestCase;

/**
 * LA PREUVE MAÎTRESSE DU PROJET (Lot 5b) — le basculement de la règle reine.
 *
 * Avant publication : le candidat ne voit rien de sa décision. Après publication :
 * il voit sa décision (retenu / liste_attente / non_retenu) + le motif_communicable
 * s'il existe — et RIEN d'autre. Un non-éligible voit EXACTEMENT un non_retenu
 * générique : il n'apprend jamais que la cause était l'inéligibilité (D-5b-1).
 *
 * Tout passe par `StatutPublicResolver`, sur le vrai chemin `GET /api/candidature`
 * (D-3b-7).
 */
class ResultatApresPublicationTest extends TestCase
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
        $this->seedContexteClassement();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin@casa-demo.ci');
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
    }

    private function calculer(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/classement")->assertOk();
    }

    private function publier(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/campagnes/{$this->campagne->id}/publier")->assertOk();
    }

    private function utilisateur(Candidature $candidature): User
    {
        return User::findOrFail($candidature->candidat->utilisateur_id);
    }

    private function resultat(Candidature $candidature): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->utilisateur($candidature))->getJson('/api/candidature')->assertOk();
    }

    // -----------------------------------------------------------------------

    public function test_avant_publication_le_candidat_ne_voit_rien_de_sa_decision(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer(); // décision persistée EN INTERNE (retenu), mais pas de publication

        $this->assertDatabaseHas('decision_candidature', ['candidature_id' => $c->id, 'decision' => 'retenu']);

        $this->resultat($c)
            ->assertJsonPath('data.statut_public', 'en_cours_de_traitement')
            ->assertJsonPath('data.decision', null)
            ->assertJsonPath('data.motif_communicable', null);
    }

    public function test_apres_publication_un_retenu_voit_sa_decision(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $this->calculer();
        $this->publier();

        $this->resultat($c)
            ->assertJsonPath('data.statut_public', 'decision_publiee')
            ->assertJsonPath('data.decision', 'retenu')
            ->assertJsonPath('data.motif_communicable', null);
    }

    public function test_apres_publication_un_liste_attente_voit_sa_decision(): void
    {
        DB::table('campagne_filiere')->where('campagne_id', $this->campagne->id)
            ->where('filiere_id', $this->idFiliere('cuisine'))->update(['quota' => 1]);

        $premier = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $second = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0);

        $this->calculer();
        $this->publier();

        $this->resultat($premier)->assertJsonPath('data.decision', 'retenu');
        $this->resultat($second)->assertJsonPath('data.decision', 'liste_attente');
    }

    public function test_apres_publication_le_motif_communicable_est_expose_seulement_s_il_existe(): void
    {
        $avecMotif = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $sansMotif = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 55.0, 25.0);
        $this->calculer();

        // On force les deux en non_retenu et on donne un motif communicable au 1er.
        DB::table('decision_candidature')->whereIn('candidature_id', [$avecMotif->id, $sansMotif->id])
            ->update(['decision' => 'non_retenu']);
        $this->actingAs($this->admin)->putJson("/api/admin/candidatures/{$avecMotif->id}/decision/motifs", [
            'motif_communicable' => 'Places limitées cette année ; recandidatez à la prochaine cohorte.',
        ])->assertOk();

        $this->publier();

        $this->resultat($avecMotif)
            ->assertJsonPath('data.decision', 'non_retenu')
            ->assertJsonPath('data.motif_communicable', 'Places limitées cette année ; recandidatez à la prochaine cohorte.');
        $this->resultat($sansMotif)
            ->assertJsonPath('data.decision', 'non_retenu')
            ->assertJsonPath('data.motif_communicable', null);
    }

    public function test_INDISCERNABILITE_non_retenu_ordinaire_vs_non_eligible(): void
    {
        // Un non_retenu ORDINAIRE (éligible, mais hors quota/liste) et un non_retenu
        // issu d'un NON-ÉLIGIBLE (motif_interne = 'non éligible' écrit par le 5a).
        $ordinaire = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 40.0, 20.0);
        $nonEligible = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 58.0, 30.0, [
            'eligibilite' => 'non_eligible',
        ]);
        $this->calculer();

        // Le non-éligible a déjà decision=non_retenu + motif_interne='non éligible'.
        // On force l'ordinaire en non_retenu, sans motif communicable (comme le non-éligible).
        DB::table('decision_candidature')->where('candidature_id', $ordinaire->id)
            ->update(['decision' => 'non_retenu']);

        $this->assertSame('non éligible', DecisionCandidature::findOrFail($nonEligible->id)->motif_interne);
        $this->assertNull(DecisionCandidature::findOrFail($ordinaire->id)->motif_interne);

        $this->publier();

        // Les deux réponses candidat sont IDENTIQUES, octet pour octet :
        // le non-éligible ne révèle jamais sa cause.
        $reponseOrdinaire = $this->resultat($ordinaire)->getContent();
        $reponseNonEligible = $this->resultat($nonEligible)->getContent();

        // On neutralise ce qui diffère légitimement (id, numéro, date) pour comparer la
        // partie "décision".
        $extraire = fn (string $json) => collect(json_decode($json, true)['data'])
            ->only(['statut_public', 'decision', 'motif_communicable'])->all();

        $this->assertSame($extraire($reponseOrdinaire), $extraire($reponseNonEligible));
        $this->assertSame(
            ['statut_public' => 'decision_publiee', 'decision' => 'non_retenu', 'motif_communicable' => null],
            $extraire($reponseOrdinaire),
        );

        // Et surtout : aucune trace de l'inéligibilité côté candidat.
        foreach ([$reponseOrdinaire, $reponseNonEligible] as $body) {
            foreach (['non_eligible', 'non éligible', 'eligibilite', 'critere', 'eliminatoire'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $body);
            }
        }
    }

    public function test_zero_fuite_des_colonnes_rouges_apres_publication(): void
    {
        $c = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 61.3, 29.7); // score final 91.0
        $this->calculer();

        // On SALIT les colonnes 🔴 en base.
        DB::table('decision_candidature')->where('candidature_id', $c->id)->update([
            'rang' => 7,
            'motif_interne' => 'RANG 7 — NE DOIT JAMAIS FUITER',
        ]);
        EvaluationDossier::where('candidature_id', $c->id)->update(['score_total' => '61.3']);
        $c->entretien->forceFill(['score_total' => '29.7', 'observation' => 'OBS INTERNE SECRETE'])->save();
        $c->forceFill(['commentaire_evaluateur' => 'COMMENTAIRE INTERNE'])->saveQuietly();

        $this->publier();

        $body = $this->resultat($c)->assertJsonPath('data.decision', 'retenu')->getContent();

        foreach ([
            '"rang":7', 'RANG 7', 'motif_interne', 'NE DOIT JAMAIS FUITER',
            '61.3', '29.7', '91.0', 'score', 'observation', 'OBS INTERNE',
            'commentaire_evaluateur', 'COMMENTAIRE INTERNE', 'evaluation_dossier',
        ] as $interdit) {
            $this->assertStringNotContainsString($interdit, $body, "« {$interdit} » ne doit jamais fuir vers le candidat, même après publication");
        }
    }

    public function test_cas_limite_candidature_soumise_sans_decision_apres_publication(): void
    {
        // Un candidat classable (pour passer le garde-fou "au moins une décision")...
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);

        // ...et une candidature SOUMISE mais jamais évaluée (pas d'entretien, pas de décision).
        $soumise = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'restaurant-bar', 0.0, 0.0, [
            'entretien_statut' => 'realise',
        ]);
        $soumise->forceFill(['statut_interne' => 'en_instruction', 'dossier_verrouille' => false])->saveQuietly();

        $this->calculer();
        $this->assertDatabaseMissing('decision_candidature', ['candidature_id' => $soumise->id]);

        $this->publier();

        // Le résolveur dérive un non_retenu générique — pas d'erreur, pas d'incohérence.
        $this->resultat($soumise->fresh())
            ->assertJsonPath('data.statut_public', 'decision_publiee')
            ->assertJsonPath('data.decision', 'non_retenu')
            ->assertJsonPath('data.motif_communicable', null);
    }

    public function test_un_brouillon_reste_brouillon_meme_apres_publication(): void
    {
        $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'cuisine', 60.0, 30.0);
        $brouillon = $this->candidatureEvaluee($this->campagne, $this->evaluateur, 'buanderie', 0.0, 0.0, [
            'entretien_statut' => 'realise',
        ]);
        $brouillon->forceFill(['statut_interne' => 'brouillon', 'dossier_verrouille' => false])->saveQuietly();

        $this->calculer();
        $this->publier();

        $this->resultat($brouillon->fresh())
            ->assertJsonPath('data.statut_public', 'brouillon')
            ->assertJsonPath('data.decision', null);
    }
}
