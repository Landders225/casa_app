<?php

namespace App\Domain\Scoring;

use App\Models\Candidature;
use App\Models\Grille;
use App\Models\Item;
use App\Models\Rubrique;
use Illuminate\Support\Collection;

/**
 * Calcul du score du VOLET DOSSIER (/65) — codé et versionné par le déploiement
 * (ADR-06, comme ServiceEligibilite), PAS un moteur de règles générique.
 *
 * Portage FIDÈLE de App_maquette/assets/js/scoring.js `computeScores()` :
 *  - le BARÈME (poids de rubrique, points d'option, max d'item) est lu EN BASE,
 *    sur la grille passée en argument — aucune valeur de barème en dur ici ;
 *  - seules les CONSTANTES ALGORITHMIQUES de scoring.js vivent dans le code
 *    (domaines d'expérience, moisApprox, seuils de durée, échelle des niveaux,
 *    échelle des étoiles, table item→colonne). Cf. D6 du Lot 1.
 *
 * Ce que l'évaluateur saisit et qui entre ici : `reponse_formulaire.mo04_note_etoiles`
 * (Lot 4b) et `verification_dossier.diplome_verifie` (Lot 4a, pour SC.04). Tout
 * le reste dérive des déclarations candidat (`reponse_formulaire`,
 * `experience_professionnelle`).
 */
final class ServiceScoring
{
    /** Version de l'algorithme (change avec le code, jamais en base). */
    public const VERSION = 1;

    /** Domaines d'expérience reconnus (scoring.js `rubrique.domaines`). */
    private const DOMAINES_VALIDES = ['hotellerie', 'restauration', 'commerce'];

    /** Mois approximatifs par catégorie de durée (scoring.js `dureeOptions.moisApprox`). */
    private const MOIS_APPROX = ['moins_6' => 3, '6_12' => 9, 'plus_12' => 15];

    /** Échelle des niveaux langue / informatique : 0..3 (scoring.js `echelle`). */
    private const NIVEAU_MAX = 3;

    /** Échelle de la note de motivation : 0..5 étoiles. */
    private const ETOILES_MAX = 5;

    /**
     * Colonne de `reponse_formulaire` porteuse de la valeur de chaque item
     * `choix` noté (scoring.js : `r[code.toLowerCase().replace('.','')]`).
     */
    private const COLONNE = [
        'SC.01' => 'sc01_scolarise_actuellement',
        'SC.02' => 'sc02_derniere_classe',
        'SC.03' => 'sc03_document_justifiant_niveau',
        'SC.05' => 'sc05_beneficiaire_formation_actuelle',
        'SE.02' => 'se02_orphelin',
        'SE.03' => 'se03_situation_emploi',
        'SE.04' => 'se04_source_revenu',
        'SE.06' => 'se06_soutien_menage',
        'DI.02' => 'di02_contraintes',
    ];

    /**
     * @param  Candidature  $candidature  avec `reponseFormulaire`, `verification`,
     *                                    `experiences` chargés (sinon rechargés).
     */
    public function calculer(Candidature $candidature, Grille $grille): ScoreDossier
    {
        $candidature->loadMissing(['reponseFormulaire', 'verification', 'experiences']);
        $grille->loadMissing(['volets.rubriques.items.options']);

        $volet = $grille->volets->firstWhere('code', 'dossier');
        abort_if($volet === null, 500, 'Grille sans volet « dossier ».');

        /** @var Collection<string, Rubrique> $rub */
        $rub = $volet->rubriques->keyBy('code');

        $r = $candidature->reponseFormulaire;
        $diplome = $candidature->verification?->diplome_verifie;

        // --- Profil scolaire /12 : Σ points d'option, plafonné ---
        $scolaire = $rub['scolaire'];
        $sItems = $scolaire->items->keyBy('code');
        $rawScolaire =
            $this->pointsOption($sItems['SC.01'], $r?->sc01_scolarise_actuellement)
            + $this->pointsOption($sItems['SC.02'], $r?->sc02_derniere_classe)
            + $this->pointsOption($sItems['SC.03'], $r?->sc03_document_justifiant_niveau)
            + $this->pointsOption($sItems['SC.04'], $diplome)          // saisie évaluateur (Lot 4a)
            + $this->pointsOption($sItems['SC.05'], $r?->sc05_beneficiaire_formation_actuelle);
        $scoreScolaire = min($rawScolaire, (float) $scolaire->max_points);

        // --- Situation socio-économique /13 ---
        $socioEco = $rub['socioEco'];
        $seItems = $socioEco->items->keyBy('code');
        $rawSocioEco =
            $this->pointsOption($seItems['SE.02'], $r?->se02_orphelin)
            + $this->pointsOption($seItems['SE.03'], $r?->se03_situation_emploi)
            + $this->pointsOption($seItems['SE.04'], $r?->se04_source_revenu)
            + $this->pointsOption($seItems['SE.06'], $r?->se06_soutien_menage);
        $scoreSocioEco = min($rawSocioEco, (float) $socioEco->max_points);

        // --- Expérience professionnelle /5 (items dérivés) ---
        $experience = $rub['experience'];
        $eItems = $experience->items->keyBy('code');
        $maxDomaines = (float) $eItems['EXP.DOMAINES']->max_points; // 2,5
        $maxDuree = (float) $eItems['EXP.DUREE']->max_points;       // 2,5
        $exps = $candidature->experiences;

        $domainesDistincts = $exps
            ->pluck('domaine')
            ->filter(fn ($d) => in_array($d, self::DOMAINES_VALIDES, true))
            ->unique()
            ->count();
        $nbDomaines = count(self::DOMAINES_VALIDES);
        $domainesScore = min($domainesDistincts, $nbDomaines) / $nbDomaines * $maxDomaines;

        $moisCumules = $exps->reduce(
            fn (int $somme, $e) => $somme + (self::MOIS_APPROX[$e->duree_categorie] ?? 0),
            0,
        );
        $ratioDuree = match (true) {
            $moisCumules >= 12 => 1.0,
            $moisCumules >= 6 => 2 / 3,
            $moisCumules > 0 => 1 / 3,
            default => 0.0,
        };
        $dureeScore = $ratioDuree * $maxDuree;
        $scoreExperience = min($domainesScore + $dureeScore, (float) $experience->max_points);

        // --- Langues & informatique /10 : (fr/6 + info/3) ramenés /9 → ×10 ---
        $langues = $rub['langues'];
        $lItems = $langues->items->keyBy('code');
        $maxFr = (float) $lItems['LANG.FR']->max_points;   // 6
        $maxInfo = (float) $lItems['LANG.INFO']->max_points; // 3
        $frScore = $this->moyenneNiveaux([$r?->langue_ecrit, $r?->langue_parle, $r?->langue_comprehension])
            / self::NIVEAU_MAX * $maxFr;
        $infoScore = $this->moyenneNiveaux([$r?->info_word, $r?->info_excel, $r?->info_internet])
            / self::NIVEAU_MAX * $maxInfo;
        $raw9 = $frScore + $infoScore;
        $scoreLangues = $raw9 / ($maxFr + $maxInfo) * (float) $langues->max_points;

        // --- Motivation (dossier) /15 : MO.04 note en étoiles × (max_points / 5) ---
        $motivation = $rub['motivation'];
        $mo04 = $motivation->items->firstWhere('code', 'MO.04');
        $etoiles = max(0, min((int) ($r?->mo04_note_etoiles ?? 0), self::ETOILES_MAX));
        $scoreMotivation = $etoiles / self::ETOILES_MAX * (float) $mo04->max_points;

        // --- Disponibilité /10 : DI.02 exprimé directement sur 10 ---
        $disponibilite = $rub['disponibilite'];
        $di02 = $disponibilite->items->firstWhere('code', 'DI.02');
        $scoreDisponibilite = $this->pointsOption($di02, $r?->di02_contraintes);

        $rubriques = [
            $this->rubriqueScore($scolaire, $scoreScolaire),
            $this->rubriqueScore($socioEco, $scoreSocioEco),
            $this->rubriqueScore($experience, $scoreExperience),
            $this->rubriqueScore($langues, $scoreLangues),
            $this->rubriqueScore($motivation, $scoreMotivation),
            $this->rubriqueScore($disponibilite, $scoreDisponibilite),
        ];

        $somme = array_sum(array_map(fn (RubriqueScore $x) => $x->score, $rubriques));
        $voletMax = (float) $volet->max_points;
        $total = round(min($somme, $voletMax) * 10) / 10;

        $indexees = [];
        foreach ($rubriques as $rs) {
            $indexees[$rs->code] = $rs;
        }

        return new ScoreDossier($total, $voletMax, $indexees);
    }

    private function rubriqueScore(Rubrique $rubrique, float $score): RubriqueScore
    {
        return new RubriqueScore(
            code: $rubrique->code,
            rubriqueId: $rubrique->id,
            label: $rubrique->label,
            max: (float) $rubrique->max_points,
            score: $score,
        );
    }

    /**
     * Points de l'option choisie (scoring.js `findPoints`) : 0 si l'item n'a pas
     * d'option pour cette valeur (valeur absente / non reconnue).
     */
    private function pointsOption(Item $item, ?string $valeur): float
    {
        if ($valeur === null) {
            return 0.0;
        }

        return (float) ($item->options->firstWhere('valeur', $valeur)?->points ?? 0);
    }

    /**
     * Moyenne façon scoring.js `niveauMoyenne` : un niveau absent compte 0.
     *
     * @param  array<int, int|null>  $niveaux
     */
    private function moyenneNiveaux(array $niveaux): float
    {
        $somme = array_sum(array_map(static fn ($n) => $n ?? 0, $niveaux));

        return $somme / count($niveaux);
    }
}
