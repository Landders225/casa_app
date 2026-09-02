<?php

namespace Tests\Unit;

use App\Domain\Eligibilite\ServiceEligibilite;
use App\Models\Candidature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Candidat\CreeContexteCandidature;
use Tests\TestCase;

/**
 * Portage fidèle de scoring.js `checkCriteresEliminatoires` — chaque critère
 * vérifié isolément. La campagne « Cohorte 1 » ouvre le 2026-05-01 (référence
 * d'âge).
 */
class ServiceEligibiliteTest extends TestCase
{
    use CreeContexteCandidature;
    use RefreshDatabase;

    private ServiceEligibilite $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferentiels();
        $this->service = app(ServiceEligibilite::class);
    }

    private function candidatureAvec(array $reponses = [], ?string $dateNaissance = null): Candidature
    {
        $user = $this->creerCandidat();
        if ($dateNaissance !== null) {
            $user->candidat->forceFill(['date_naissance' => $dateNaissance])->save();
        }
        $id = $this->actingAs($user)
            ->postJson('/api/candidatures', ['filiere_id' => $this->idFiliere('cuisine')])
            ->json('data.id');

        $candidature = Candidature::findOrFail($id);
        $candidature->reponseFormulaire->fill(array_merge($this->reponsesEligibles(), $reponses))->save();

        return $candidature->fresh()->load('candidat', 'campagne', 'reponseFormulaire');
    }

    /** @return list<string> codes des critères déclenchés */
    private function codes(Candidature $c): array
    {
        return array_map(fn ($crit) => $crit->code, $this->service->evaluerSoumission($c));
    }

    public function test_dossier_eligible_ne_declenche_aucun_critere(): void
    {
        $this->assertSame([], $this->codes($this->candidatureAvec()));
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: string}>
     */
    public static function criteresProvider(): array
    {
        return [
            'SC.01 scolarisé'              => [['sc01_scolarise_actuellement' => 'oui'], 'SC.01'],
            'SC.02 avant la 3e'            => [['sc02_derniere_classe' => 'avant_3e'], 'SC.02'],
            'SC.05 formation en cours'     => [['sc05_beneficiaire_formation_actuelle' => 'oui'], 'SC.05'],
            'SE.03 temps partiel'          => [['se03_situation_emploi' => 'temps_partiel'], 'SE.03'],
            'SE.03 temps plein'            => [['se03_situation_emploi' => 'temps_plein'], 'SE.03'],
            'français moyenne < 2'         => [['langue_ecrit' => 1, 'langue_parle' => 1, 'langue_comprehension' => 2], 'francais'],
            'DI.01 non disponible'         => [['di01_disponible_lun_ven' => 'non'], 'DI.01'],
            'DI.03 pas d engagement'       => [['di03_engagement_complet' => 'non'], 'DI.03'],
        ];
    }

    #[DataProvider('criteresProvider')]
    public function test_chaque_critere_est_declenche_individuellement(array $reponses, string $codeAttendu): void
    {
        $this->assertSame([$codeAttendu], $this->codes($this->candidatureAvec($reponses)));
    }

    public function test_acces_sites_logique_OR_seul_le_double_non_bloque(): void
    {
        // un seul "non" -> OK
        $this->assertSame([], $this->codes($this->candidatureAvec([
            'acces_plateau' => 'non', 'acces_deux_plateaux_vallons' => 'oui',
        ])));
        $this->assertSame([], $this->codes($this->candidatureAvec([
            'acces_plateau' => 'oui', 'acces_deux_plateaux_vallons' => 'non',
        ])));
        // les deux "non" -> éliminatoire
        $this->assertSame(['acces_sites'], $this->codes($this->candidatureAvec([
            'acces_plateau' => 'non', 'acces_deux_plateaux_vallons' => 'non',
        ])));
    }

    public function test_bornes_d_age_a_la_date_d_ouverture_de_campagne(): void
    {
        // 2026-05-01 : né le 2008-06-01 -> 17 ans -> age_min
        $this->assertSame(['age_min'], $this->codes($this->candidatureAvec([], '2008-06-01')));
        // né le 2008-04-01 -> 18 ans pile -> OK
        $this->assertSame([], $this->codes($this->candidatureAvec([], '2008-04-01')));
        // né le 1995-04-01 -> 31 ans -> age_max
        $this->assertSame(['age_max'], $this->codes($this->candidatureAvec([], '1995-04-01')));
        // né le 1995-06-01 -> 30 ans -> OK
        $this->assertSame([], $this->codes($this->candidatureAvec([], '1995-06-01')));
    }

    public function test_plusieurs_criteres_tous_traces(): void
    {
        $codes = $this->codes($this->candidatureAvec([
            'di01_disponible_lun_ven' => 'non',
            'sc01_scolarise_actuellement' => 'oui',
            'acces_plateau' => 'non', 'acces_deux_plateaux_vallons' => 'non',
        ]));
        sort($codes);
        $this->assertSame(['DI.01', 'SC.01', 'acces_sites'], $codes);
    }

    public function test_criteres_evaluateur_separes_et_non_appeles_ici(): void
    {
        // scoring.js checkCriteresEliminatoiresEvaluateur — méthode distincte.
        $this->assertSame(
            ['SC.04'],
            array_map(fn ($c) => $c->code, $this->service->evaluerVerificationEvaluateur(['diplome_verifie' => 'cepe'])),
        );
        $this->assertSame(
            ['nationalite'],
            array_map(fn ($c) => $c->code, $this->service->evaluerVerificationEvaluateur(['nationalite_confirmee' => false])),
        );
        // origine correcte
        $this->assertSame(
            'verification_evaluateur',
            $this->service->evaluerVerificationEvaluateur(['diplome_verifie' => 'cepe'])[0]->origine,
        );
    }
}
