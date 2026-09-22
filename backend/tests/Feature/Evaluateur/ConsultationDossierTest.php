<?php

namespace Tests\Feature\Evaluateur;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConsultationDossierTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private User $evaluateur;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->evaluateur = $this->creerEvaluateur('eval@casa-demo.ci');
        $this->candidat = $this->creerCandidat('cand@casa-demo.ci');
    }

    public function test_liste_de_mes_dossiers_affectes(): void
    {
        $c = $this->candidatureAffectee($this->candidat, $this->evaluateur);

        $this->actingAs($this->evaluateur)->getJson('/api/evaluateur/candidatures')
            ->assertOk()
            ->assertJsonPath('data.0.numero_dossier', $c->numero_dossier)
            ->assertJsonPath('data.0.statut_interne', 'en_instruction')
            ->assertJsonPath('data.0.candidat.prenom', 'Test')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_la_liste_n_inclut_pas_les_dossiers_d_un_autre_evaluateur(): void
    {
        $autreEval = $this->creerEvaluateur('autre@casa-demo.ci');
        $this->candidatureAffectee($this->candidat, $autreEval);

        $this->actingAs($this->evaluateur)->getJson('/api/evaluateur/candidatures')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_fiche_complete_expose_la_zone_interne_du_dossier(): void
    {
        $c = $this->candidatureAffectee($this->candidat, $this->evaluateur);

        $this->actingAs($this->evaluateur)->getJson("/api/evaluateur/candidatures/{$c->id}")
            ->assertOk()
            ->assertJsonPath('data.statut_interne', 'en_instruction')
            ->assertJsonPath('data.statut_eligibilite_interne', 'eligible')
            ->assertJsonPath('data.candidat.cni', fn ($v) => $v !== null)
            ->assertJsonPath('data.reponses.di01_disponible_lun_ven', 'oui')
            ->assertJsonStructure(['data' => [
                'reponses', 'experiences', 'pieces_dossier', 'verification', 'criteres_eliminatoires', 'evaluateur',
            ]])
            ->assertJsonPath('data.verification', null)
            ->assertJsonPath('data.criteres_eliminatoires', []);
    }

    /** Lot 18 — le numéro CMU se lit comme les autres champs `candidat` déjà exposés. */
    public function test_fiche_expose_le_numero_cmu(): void
    {
        $c = $this->candidatureAffectee($this->candidat, $this->evaluateur);

        $this->actingAs($this->evaluateur)->getJson("/api/evaluateur/candidatures/{$c->id}")
            ->assertOk()
            ->assertJsonPath('data.candidat.numero_cmu', fn ($v) => $v !== null);
    }

    /**
     * Lot 18, Étape 1 (point d) — un dossier ANTÉRIEUR à ce lot n'a pas de CMU :
     * la fiche reste un 200 normal, `numero_cmu` vaut simplement `null`
     * (colonne nullable), aucune erreur. Le frontend l'affiche « — ».
     */
    public function test_fiche_dossier_ancien_sans_cmu_ne_declenche_aucune_erreur(): void
    {
        $ancien = $this->creerCandidat('ancien@casa-demo.ci', ['numero_cmu' => null]);
        $c = $this->candidatureAffectee($ancien, $this->evaluateur);

        $this->actingAs($this->evaluateur)->getJson("/api/evaluateur/candidatures/{$c->id}")
            ->assertOk()
            ->assertJsonPath('data.candidat.numero_cmu', null);
    }

    /**
     * Lot D — une pièce RETIRÉE de l'obligation (`residence`) mais déjà
     * déposée sur un dossier reste exposée telle quelle dans `pieces_dossier`
     * : la Resource évaluateur est générique (aucun filtre par type), donc
     * rien à modifier côté backend pour que la fiche l'affiche — c'est le
     * frontend (section « Pièces conservées ») qui décide de la mettre à part.
     */
    public function test_fiche_expose_une_piece_retiree_deja_deposee(): void
    {
        $c = $this->candidatureAffectee($this->candidat, $this->evaluateur);
        $c->piecesDossier()->create([
            'type_document_code' => 'residence',
            'rattachement' => 'dossier',
            'nom_original' => 'residence.pdf',
            'chemin_stockage' => $c->id.'/residence-legacy.pdf',
            'taille_octets' => 500,
            'type_mime' => 'application/pdf',
            'depose_le' => now(),
        ]);

        $reponse = $this->actingAs($this->evaluateur)->getJson("/api/evaluateur/candidatures/{$c->id}")
            ->assertOk();
        $codes = collect($reponse->json('data.pieces_dossier'))->pluck('type_document_code');
        $this->assertContains('residence', $codes);
    }

    public function test_filtre_par_statut_interne(): void
    {
        $this->candidatureAffectee($this->candidat, $this->evaluateur);

        $this->actingAs($this->evaluateur)->getJson('/api/evaluateur/candidatures?statut_interne=en_instruction')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->evaluateur)->getJson('/api/evaluateur/candidatures?statut_interne=evalue')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_un_candidat_n_atteint_pas_l_espace_evaluateur(): void
    {
        $c = $this->candidatureAffectee($this->candidat, $this->evaluateur);

        $this->actingAs($this->candidat)->getJson('/api/evaluateur/candidatures')->assertStatus(403);
        $this->actingAs($this->candidat)->getJson("/api/evaluateur/candidatures/{$c->id}")->assertStatus(403);
    }
}
