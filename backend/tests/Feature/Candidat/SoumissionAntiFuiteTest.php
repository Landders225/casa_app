<?php

namespace Tests\Feature\Candidat;

use App\Models\Candidature;
use App\Models\CritereEliminatoireDeclenche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * PREUVE MAÎTRESSE (séquence a, règle reine du Lot 3c) : la réponse HTTP de
 * soumission est STRICTEMENT IDENTIQUE que le candidat soit éligible ou non.
 * L'éligibilité est calculée et persistée (pour l'évaluateur) mais jamais
 * exposée — par aucun canal.
 *
 * Ces tests exercent le VRAI chemin (SoumissionController -> StatutPublicResolver
 * -> réponse réelle), pas une simulation (rappel D-3b-7).
 */
class SoumissionAntiFuiteTest extends TestCase
{
    // Lot 4 (ADR-49) : `CreeContexteEvaluation` (inclut `CreeContexteCandidature`)
    // apporte `creerAdmin`, nécessaire au nouveau test de rétablissement ci-dessous.
    use CreeContexteEvaluation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferentiels();
    }

    /**
     * @param  array<string,mixed>  $reponses
     */
    private function candidatureComplete(User $user, array $reponses = []): Candidature
    {
        $id = $this->actingAs($user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        return $this->rendreCandidatureComplete(Candidature::findOrFail($id), $reponses);
    }

    public function test_reponses_identiques_eligible_vs_non_eligible(): void
    {
        $aliceEligible = $this->creerCandidat('alice@casa-demo.ci');
        $bobNonEligible = $this->creerCandidat('bob@casa-demo.ci');

        $ca = $this->candidatureComplete($aliceEligible);
        $cb = $this->candidatureComplete($bobNonEligible, ['di01_disponible_lun_ven' => 'non']); // 1 critère

        $ra = $this->actingAs($aliceEligible)->postJson("/api/candidatures/{$ca->id}/soumettre");
        $rb = $this->actingAs($bobNonEligible)->postJson("/api/candidatures/{$cb->id}/soumettre");

        // 1) Même code HTTP.
        $this->assertSame(200, $ra->status());
        $this->assertSame($ra->status(), $rb->status());

        // 2) Corps identique après normalisation du seul identifiant propre.
        $normaliser = fn (array $json) => tap($json, function (&$j) {
            $j['data']['numero_dossier'] = '<NUMERO>';
        });
        $this->assertSame($normaliser($ra->json()), $normaliser($rb->json()));

        // 3) Mêmes clés, même statut_public.
        $this->assertSame('en_cours_de_traitement', $ra->json('data.statut_public'));
        $this->assertSame('en_cours_de_traitement', $rb->json('data.statut_public'));
        $this->assertSame(['numero_dossier', 'statut_public'], array_keys($ra->json('data')));

        // 4) Aucun indice d'éligibilité dans l'un ou l'autre corps.
        foreach ([$ra, $rb] as $r) {
            $body = $r->getContent();
            foreach (['eligib', 'non_eligible', 'statut_interne', 'critere', 'eliminatoire', 'DI.01', 'soumis'] as $mot) {
                $this->assertStringNotContainsStringIgnoringCase($mot, $body, "« {$mot} » ne doit pas apparaître");
            }
        }

        // 5) MAIS en base, l'éligibilité EST calculée et diffère.
        $this->assertSame('soumis', $ca->fresh()->statut_interne);
        $this->assertSame('eligible', $ca->fresh()->statut_eligibilite_interne);
        $this->assertSame('non_eligible', $cb->fresh()->statut_interne);
        $this->assertSame('non_eligible', $cb->fresh()->statut_eligibilite_interne);

        $this->assertSame(0, CritereEliminatoireDeclenche::where('candidature_id', $ca->id)->count());
        $this->assertSame(1, CritereEliminatoireDeclenche::where('candidature_id', $cb->id)->count());
        $this->assertSame('DI.01', CritereEliminatoireDeclenche::where('candidature_id', $cb->id)->value('code_critere'));
        $this->assertSame('soumission_candidat', CritereEliminatoireDeclenche::where('candidature_id', $cb->id)->value('origine'));
    }

    public function test_GET_candidature_apres_soumission_identique_pour_les_deux(): void
    {
        $alice = $this->creerCandidat('a2@casa-demo.ci');
        $bob = $this->creerCandidat('b2@casa-demo.ci');

        $ca = $this->candidatureComplete($alice);
        $cb = $this->candidatureComplete($bob, ['acces_plateau' => 'non', 'acces_deux_plateaux_vallons' => 'non']);

        $this->actingAs($alice)->postJson("/api/candidatures/{$ca->id}/soumettre")->assertOk();
        $this->actingAs($bob)->postJson("/api/candidatures/{$cb->id}/soumettre")->assertOk();

        $ga = $this->actingAs($alice)->getJson('/api/candidature')->json('data');
        $gb = $this->actingAs($bob)->getJson('/api/candidature')->json('data');

        // statut_public identique ; on retire les données propres et on compare la forme.
        $this->assertSame('en_cours_de_traitement', $ga['statut_public']);
        $this->assertSame($ga['statut_public'], $gb['statut_public']);
        $this->assertSame(array_keys($ga), array_keys($gb));
        $this->assertArrayNotHasKey('statut_interne', $ga);
        $this->assertArrayNotHasKey('statut_eligibilite_interne', $ga);
    }

    /**
     * Lot 4 (ADR-49) — un rétablissement d'éligibilité est un acte 100 %
     * interne (🔴) : `StatutPublicResolver` renvoie toujours
     * `'en_cours_de_traitement'` avant publication, quel que soit
     * `statut_eligibilite_interne`. Cette épingle transforme cette garantie
     * architecturale en non-régression exécutable.
     */
    public function test_retablissement_eligibilite_ne_change_rien_pour_le_candidat(): void
    {
        $bob = $this->creerCandidat('bob-retabli@casa-demo.ci');
        $cb = $this->candidatureComplete($bob, ['di01_disponible_lun_ven' => 'non']);
        $this->actingAs($bob)->postJson("/api/candidatures/{$cb->id}/soumettre")->assertOk();

        $avant = $this->actingAs($bob)->getJson('/api/candidature');

        $admin = $this->creerAdmin('admin-lot4@casa-demo.ci');
        $this->actingAs($admin)->postJson('/api/admin/candidatures/eligibilite/retablir', [
            'candidature_ids' => [$cb->id],
            'motif' => 'Attestation complémentaire reçue.',
        ])->assertOk();
        $this->assertSame('eligible', $cb->fresh()->statut_eligibilite_interne);

        $apres = $this->actingAs($bob)->getJson('/api/candidature');

        $this->assertSame($avant->status(), $apres->status());
        $this->assertSame($avant->json('data'), $apres->json('data'));
        $this->assertSame('en_cours_de_traitement', $apres->json('data.statut_public'));
    }

    public function test_edition_fermee_de_facon_identique_dans_les_deux_branches(): void
    {
        $alice = $this->creerCandidat('a3@casa-demo.ci');
        $bob = $this->creerCandidat('b3@casa-demo.ci');
        $ca = $this->candidatureComplete($alice);
        $cb = $this->candidatureComplete($bob, ['di03_engagement_complet' => 'non']);

        $this->actingAs($alice)->postJson("/api/candidatures/{$ca->id}/soumettre")->assertOk();
        $this->actingAs($bob)->postJson("/api/candidatures/{$cb->id}/soumettre")->assertOk();

        $ea = $this->actingAs($alice)->patchJson("/api/candidatures/{$ca->id}/reponses", ['di02_contraintes' => 'gerable']);
        $eb = $this->actingAs($bob)->patchJson("/api/candidatures/{$cb->id}/reponses", ['di02_contraintes' => 'gerable']);

        $this->assertSame(409, $ea->status());
        $this->assertSame($ea->status(), $eb->status());
        $this->assertSame($ea->json('message'), $eb->json('message'));
    }
}
