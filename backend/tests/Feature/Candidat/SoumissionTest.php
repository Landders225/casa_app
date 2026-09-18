<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\Filiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SoumissionTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private User $user;

    private Candidature $candidature;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->user = $this->creerCandidat();
        $id = $this->actingAs($this->user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');
        $this->candidature = Candidature::findOrFail($id);
    }

    private function soumettre()
    {
        return $this->actingAs($this->user)->postJson("/api/candidatures/{$this->candidature->id}/soumettre");
    }

    public function test_soumission_d_un_dossier_complet_et_eligible(): void
    {
        $this->rendreCandidatureComplete($this->candidature);

        $this->soumettre()
            ->assertOk()
            ->assertExactJson(['data' => [
                'numero_dossier' => $this->candidature->numero_dossier,
                'statut_public' => 'en_cours_de_traitement',
            ]]);

        $fraiche = $this->candidature->fresh();
        $this->assertSame('soumis', $fraiche->statut_interne);
        $this->assertSame('eligible', $fraiche->statut_eligibilite_interne);
        $this->assertNotNull($fraiche->date_soumission);
        $this->assertSame(0, CritereEliminatoireDeclenche::where('candidature_id', $this->candidature->id)->count());

        // journal_audit (🔴) : une ligne "Soumission" avec le candidat comme auteur.
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->user->id,
            'action' => 'Soumission de candidature',
            'module' => 'Candidatures',
        ]);
    }

    public function test_dossier_incomplet_renvoie_422_avec_le_detail(): void
    {
        // rien rempli
        $this->soumettre()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cqp_confirme', 'reponses.sc01_scolarise_actuellement', 'pieces_dossier'])
            ->assertJsonPath('message', 'Le dossier de candidature est incomplet.');

        // toujours en brouillon.
        $this->assertSame('brouillon', $this->candidature->fresh()->statut_interne);
    }

    public function test_re_soumission_bloquee_409(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->soumettre()->assertOk();

        $this->soumettre()
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cette candidature a déjà été soumise.');
    }

    public function test_edition_3a_3b_fermee_apres_soumission_409(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->soumettre()->assertOk();

        $id = $this->candidature->id;
        $this->actingAs($this->user)->patchJson("/api/candidatures/{$id}/reponses", ['sc01_scolarise_actuellement' => 'oui'])
            ->assertStatus(409);
        $this->actingAs($this->user)->postJson("/api/candidatures/{$id}/confirmer-filiere")
            ->assertStatus(409);
        $this->actingAs($this->user)->postJson("/api/candidatures/{$id}/experiences", ['domaine' => 'commerce', 'duree_categorie' => 'moins_6'])
            ->assertStatus(409);
        $this->actingAs($this->user)->putJson("/api/candidatures/{$id}/classement", ['ordre' => Filiere::pluck('id')->all()])
            ->assertStatus(409);
        $this->actingAs($this->user)->post("/api/candidatures/{$id}/pieces/cni", ['fichier' => $this->fichierPdf()])
            ->assertStatus(409);
    }

    public function test_lecture_reste_ouverte_et_statut_public_bascule(): void
    {
        $this->rendreCandidatureComplete($this->candidature);

        $this->actingAs($this->user)->getJson('/api/candidature')->assertJsonPath('data.statut_public', 'brouillon');

        $this->soumettre()->assertOk();

        $apres = $this->actingAs($this->user)->getJson('/api/candidature');
        $apres->assertOk()
            ->assertJsonPath('data.statut_public', 'en_cours_de_traitement')
            ->assertJsonPath('data.date_soumission', fn ($v) => $v !== null);

        // Aucun champ interne / d'éligibilité dans la vraie réponse candidat.
        $body = $apres->getContent();
        foreach (['statut_interne', 'statut_eligibilite', 'non_eligible', 'critere', 'eliminatoire'] as $mot) {
            $this->assertStringNotContainsStringIgnoringCase($mot, $body);
        }
    }

    public function test_soumission_de_la_candidature_d_un_autre_404(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $autre = $this->creerCandidat('autre@casa-demo.ci');

        $this->actingAs($autre)->postJson("/api/candidatures/{$this->candidature->id}/soumettre")
            ->assertStatus(404);
    }

    // --- Lot 18 — CMU (numéro + justificatif), obligatoires à la soumission ---

    public function test_soumission_sans_numero_cmu_refusee_422(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->user->candidat->forceFill(['numero_cmu' => null])->save();

        $this->soumettre()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identite.numero_cmu']);

        $this->assertSame('brouillon', $this->candidature->fresh()->statut_interne);
    }

    public function test_soumission_sans_justificatif_cmu_refusee_422(): void
    {
        $c = $this->rendreCandidatureComplete($this->candidature);
        $c->piecesDossier()->where('type_document_code', 'cmu')->delete();

        $this->soumettre()
            ->assertStatus(422)
            ->assertJsonPath('errors.pieces_dossier.0', fn ($m) => str_contains($m, 'cmu'));
    }

    public function test_soumission_avec_numero_et_justificatif_cmu_reussit(): void
    {
        $this->user->candidat->forceFill(['numero_cmu' => 'CMU000111222'])->save();
        $this->rendreCandidatureComplete($this->candidature); // dépose bien la pièce 'cmu' (ContraintesFichier::TYPES_DOSSIER)

        $this->soumettre()->assertOk();
        $this->assertSame('soumis', $this->candidature->fresh()->statut_interne);
    }

    /**
     * Preuve STRUCTURELLE de non-rétroactivité (Étape 1, point a) : une
     * candidature déjà soumise AVANT ce lot (donc sans numéro ni justificatif
     * CMU) ne repasse jamais par `ValidateurCompletude` — `SoumissionController`
     * refuse (409 « déjà soumise ») avant tout calcul de complétude. Simule
     * l'« ancien » dossier en forçant `date_soumission` directement (bypass de
     * l'endpoint, comme le ferait une candidature réellement antérieure au Lot 18).
     */
    public function test_candidature_deja_soumise_sans_cmu_reste_valide_aucune_revalidation(): void
    {
        $this->rendreCandidatureComplete($this->candidature);
        $this->user->candidat->forceFill(['numero_cmu' => null])->save();
        $this->candidature->piecesDossier()->where('type_document_code', 'cmu')->delete();
        $this->candidature->forceFill([
            'statut_interne' => 'soumis',
            'statut_eligibilite_interne' => 'eligible',
            'date_soumission' => now()->subMonths(6), // « avant le Lot 18 »
        ])->saveQuietly();

        // Rejouer /soumettre sur ce dossier : 409 (déjà soumise), PAS 422 —
        // la preuve que la complétude n'est jamais recalculée après coup.
        $this->soumettre()
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cette candidature a déjà été soumise.');

        // Le dossier reste lisible normalement par son propriétaire, CMU
        // absente sans que rien ne casse.
        $this->actingAs($this->user)->getJson('/api/candidature')
            ->assertOk()
            ->assertJsonPath('data.statut_public', 'en_cours_de_traitement');
    }
}
