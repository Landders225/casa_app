<?php

namespace App\Domain\Export;

use App\Domain\Rapports\ServiceRapports;
use App\Models\Candidature;
use App\Models\TypeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Construit le contenu (déjà masqué) de l'export d'analyse administratif
 * (`casa:export-analyse`) — UNE ligne par candidature, TOUT état confondu
 * (brouillon compris, correction #6 : filtrable via la colonne `etat_dossier`
 * une fois dans le tableur, pas par une option de la commande).
 *
 * Lecture 100% en transaction PostgreSQL READ ONLY (garantie moteur, pas
 * seulement conventionnelle) — {@see lireEnLectureSeule()}. Ne fait AUCUNE
 * écriture ; l'appelant (la commande) décide ensuite s'il écrit un fichier et
 * une entrée `journal_audit`.
 *
 * Masquage : réutilise {@see ServiceRapports::SEUIL_MASQUAGE} (k=5), déjà la
 * convention k-anonymat du projet — pas un second seuil inventé ici.
 */
final class ServiceExportAnalyse
{
    /**
     * Ordre des colonnes FIXES (hors `piece_<code>_presente`, générées à
     * l'exécution depuis `type_document` — jamais codées en dur, correction #3).
     *
     * @var list<string>
     */
    private const COLONNES_FIXES = [
        'id_pseudonyme', 'campagne', 'filiere',
        'preference_rang_1', 'preference_rang_2', 'preference_rang_3', 'preference_rang_4', 'preference_rang_5',
        'sexe', 'tranche_age', 'residence_ci', 'zone', 'commune_abidjan', 'ville_normalisee',
        'date_inscription', 'date_soumission', 'delai_inscription_soumission_jours',
        'etat_dossier', 'etat_eligibilite', 'dossier_verrouille', 'cqp_confirme',
        'criteres_eliminatoires',
        'sc01_scolarise_actuellement', 'sc02_derniere_classe', 'sc03_document_justifiant_niveau',
        'sc05_beneficiaire_formation_actuelle', 'sc06_deja_beneficie_formation', 'sc08_mene_a_terme',
        'se01_vit_avec', 'se02_orphelin', 'se03_situation_emploi', 'se04_source_revenu',
        'se05_personnes_a_charge', 'se06_soutien_menage',
        'langue_ecrit', 'langue_parle', 'langue_comprehension',
        'info_word', 'info_excel', 'info_internet',
        'acces_plateau', 'acces_deux_plateaux_vallons',
        'di01_disponible_lun_ven', 'di02_contraintes', 'di03_engagement_complet',
        'exp_hotellerie_nb', 'exp_hotellerie_duree_max',
        'exp_restauration_nb', 'exp_restauration_duree_max',
        'exp_commerce_nb', 'exp_commerce_duree_max',
        'nb_pieces_dossier', 'nb_justificatifs_experience',
    ];

    /** Ordinal de `experience_professionnelle.duree_categorie`, pour le "max" par domaine. */
    private const ORDRE_DUREE = ['moins_6' => 1, '6_12' => 2, 'plus_12' => 3];

    private const DOMAINES_EXPERIENCE = ['hotellerie', 'restauration', 'commerce'];

    /** Libellés affichés du périmètre — réutilisés par la commande/console/journal_audit/Dictionnaire. */
    private const PERIMETRE_SOUMISES = 'Candidatures soumises uniquement (hors brouillons — même périmètre que le tableau de bord admin, Candidature::scopeSoumises())';

    private const PERIMETRE_TOUTES = 'Toutes les candidatures, brouillons compris (--inclure-brouillons)';

    /**
     * @return array<string, mixed> — `bloque=true` si des villes restent
     *                              "À classer" (aucune ligne/feuille alors produite, correction #1).
     */
    public function construire(string $operateurEmail, bool $inclureBrouillons = false): array
    {
        $operateur = $this->resoudreOperateur($operateurEmail);
        [$lignesBrutes, $codesTypeDocument, $nbBrouillonsExclus] = $this->lireEnLectureSeule($inclureBrouillons);
        $perimetre = $inclureBrouillons ? self::PERIMETRE_TOUTES : self::PERIMETRE_SOUMISES;

        $villesAClasser = [];
        foreach ($lignesBrutes as $l) {
            if ($l['_classement']['zone'] === NormalisationVille::ZONE_A_CLASSER) {
                $villesAClasser[$l['_ville_brute']] = ($villesAClasser[$l['_ville_brute']] ?? 0) + 1;
            }
        }

        $colonnes = $this->colonnes($codesTypeDocument);

        if ($villesAClasser !== []) {
            return [
                'bloque' => true,
                'operateur' => $operateur,
                'colonnes' => $colonnes,
                'villes_a_classer' => $villesAClasser,
                'perimetre' => $perimetre,
            ];
        }

        $coherence = $this->controleCoherence($lignesBrutes);

        $comptesCommunes = [];
        $comptesVilles = [];
        $comptesZone = [];
        foreach ($lignesBrutes as $l) {
            $c = $l['_classement'];
            if ($c['zone'] === NormalisationVille::ZONE_ABIDJAN && $c['commune_abidjan'] !== null) {
                $comptesCommunes[$c['commune_abidjan']] = ($comptesCommunes[$c['commune_abidjan']] ?? 0) + 1;
            }
            if ($c['zone'] === NormalisationVille::ZONE_INTERIEUR) {
                $comptesVilles[$c['ville_interieur']] = ($comptesVilles[$c['ville_interieur']] ?? 0) + 1;
            }
            $comptesZone[$c['zone']] = ($comptesZone[$c['zone']] ?? 0) + 1;
        }

        $decisionFusionZone = $this->decisionFusionZone($comptesZone);

        $lignes = array_map(
            fn (array $l) => $this->finaliserLigne($l, $comptesCommunes, $comptesVilles, $decisionFusionZone),
            $lignesBrutes
        );

        $brouillonsExclusAffiche = $inclureBrouillons
            ? '0 (option --inclure-brouillons active)'
            : ($nbBrouillonsExclus < ServiceRapports::SEUIL_MASQUAGE ? '<'.ServiceRapports::SEUIL_MASQUAGE : (string) $nbBrouillonsExclus);

        return [
            'bloque' => false,
            'operateur' => $operateur,
            'colonnes' => $colonnes,
            'lignes' => $lignes,
            'villes' => $this->feuilleVilles($comptesCommunes, $comptesVilles),
            'synthese' => $this->feuilleSynthese($lignes),
            'coherence' => $coherence,
            'genere_le' => CarbonImmutable::now(),
            'valeurs_interdites' => $this->valeursInterdites($lignesBrutes),
            'perimetre' => $perimetre,
            'brouillons_exclus_affiche' => $brouillonsExclusAffiche,
        ];
    }

    /**
     * Valeur => catégorie, pour TOUTES les candidatures de CET export —
     * consommé par {@see GardeFuite} après écriture du .xlsx (garde-fou de
     * fuite, exécuté uniquement hors `--dry-run`, cf. ExportAnalyse::handle()).
     * Volontairement SANS prenom/nom (faux positifs avec des noms de villes).
     *
     * @param  list<array<string, mixed>>  $lignesBrutes
     * @return array<string, string>
     */
    private function valeursInterdites(array $lignesBrutes): array
    {
        $valeurs = [];
        foreach ($lignesBrutes as $l) {
            foreach ($l['_valeurs_interdites'] as $valeur => $categorie) {
                $valeurs[(string) $valeur] = $categorie;
            }
        }

        return $valeurs;
    }

    /**
     * @param  list<string>  $codesTypeDocument
     * @return list<string>
     */
    public function colonnes(array $codesTypeDocument): array
    {
        return [...self::COLONNES_FIXES, ...array_map(fn (string $c) => "piece_{$c}_presente", $codesTypeDocument)];
    }

    private function resoudreOperateur(string $email): User
    {
        $utilisateur = User::query()->where('email', $email)->first();

        if ($utilisateur === null || ! $utilisateur->isAdministrateur()) {
            throw new RuntimeException("Aucun compte administrateur trouvé pour l'e-mail « {$email} ».");
        }

        return $utilisateur;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: int}
     */
    private function lireEnLectureSeule(bool $inclureBrouillons): array
    {
        DB::beginTransaction();
        DB::statement('SET TRANSACTION READ ONLY');

        try {
            $codesTypeDocument = TypeDocument::query()->orderBy('code')->pluck('code')->all();

            // Effectif des brouillons EXCLUS (0 si --inclure-brouillons :
            // rien n'est exclu) — pour la ligne "Brouillons exclus" de la
            // feuille Synthèse, jamais pour filtrer une seconde fois.
            $nbBrouillonsExclus = $inclureBrouillons
                ? 0
                : Candidature::query()->where('statut_interne', 'brouillon')->count();

            $lignesBrutes = Candidature::query()
                ->when(! $inclureBrouillons, fn ($q) => $q->soumises())
                ->with([
                    'candidat.utilisateur',
                    'campagne',
                    'filiere',
                    'reponseFormulaire',
                    'classement.filiere',
                    'experiences.pieceJustificative',
                    'piecesDossier',
                    'criteresEliminatoires',
                ])
                ->orderBy('created_at')
                ->get()
                ->map(fn (Candidature $c) => $this->ligneBrute($c, $codesTypeDocument))
                ->all();
        } finally {
            // Lecture seule : AUCUNE écriture n'a eu lieu dans cette
            // transaction — on l'annule systématiquement, succès ou échec,
            // plutôt que de la valider (rien à valider).
            DB::rollBack();
        }

        return [$lignesBrutes, $codesTypeDocument, $nbBrouillonsExclus];
    }

    /**
     * @param  list<string>  $codesTypeDocument
     * @return array<string, mixed>
     */
    private function ligneBrute(Candidature $candidature, array $codesTypeDocument): array
    {
        $candidat = $candidature->candidat;
        $villeBrute = $candidat->ville_residence;
        $classement = NormalisationVille::classer($villeBrute);

        $age = $this->age($candidat->date_naissance, $candidature->campagne->date_ouverture);

        $preferences = array_fill(1, 5, null);
        foreach ($candidature->classement as $pref) {
            $preferences[$pref->rang] = $pref->filiere->nom;
        }

        $inscriptionLe = $candidat->utilisateur->created_at;
        $soumissionLe = $candidature->date_soumission;

        $r = $candidature->reponseFormulaire;

        $parDomaine = [];
        foreach (self::DOMAINES_EXPERIENCE as $domaine) {
            $deCeDomaine = $candidature->experiences->where('domaine', $domaine);
            $parDomaine[$domaine] = [
                'nb' => $deCeDomaine->count(),
                'duree_max' => $this->dureeMax($deCeDomaine),
            ];
        }

        $criteres = $candidature->criteresEliminatoires
            ->map(fn ($crit) => "{$crit->code_critere}:{$crit->origine}")
            ->implode('; ');

        $piecesPresentes = [];
        foreach ($codesTypeDocument as $code) {
            $present = $candidature->piecesDossier->contains('type_document_code', $code);
            $piecesPresentes["piece_{$code}_presente"] = $present ? 'oui' : 'non';
        }

        // Garde-fou de fuite (GardeFuite, après écriture du .xlsx) : valeur
        // brute => catégorie, JAMAIS prenom/nom (faux positifs avec des
        // noms de villes, demande explicite) — uniquement des identifiants
        // non ambigus.
        $valeursInterdites = array_filter([
            (string) $candidat->cni => 'cni',
            (string) $candidat->telephone => 'telephone',
            (string) $candidat->numero_cmu => 'numero_cmu',
            (string) $candidat->utilisateur->email => 'email',
            (string) $candidature->numero_dossier => 'numero_dossier',
            (string) $candidature->id => 'uuid_candidature',
            (string) $candidat->id => 'uuid_candidat',
            (string) $candidat->utilisateur_id => 'uuid_utilisateur',
        ], fn (string $v) => $v !== '' && $v !== '0', mode: ARRAY_FILTER_USE_KEY);

        return [
            '_ville_brute' => $villeBrute,
            '_classement' => $classement,
            '_residence_ci' => (bool) $candidat->residence_ci,
            '_valeurs_interdites' => $valeursInterdites,
            'id_pseudonyme' => Pseudonymisation::idPseudonyme($candidature->id),
            'campagne' => $candidature->campagne->nom,
            'filiere' => $candidature->filiere->nom,
            'preference_rang_1' => $preferences[1],
            'preference_rang_2' => $preferences[2],
            'preference_rang_3' => $preferences[3],
            'preference_rang_4' => $preferences[4],
            'preference_rang_5' => $preferences[5],
            'sexe' => $candidat->sexe,
            'tranche_age' => $this->trancheAge($age),
            'residence_ci' => $candidat->residence_ci ? 'oui' : 'non',
            'date_inscription' => $inscriptionLe?->toDateString(),
            'date_soumission' => $soumissionLe?->toDateString(),
            'delai_inscription_soumission_jours' => ($inscriptionLe !== null && $soumissionLe !== null)
                ? $inscriptionLe->diffInDays($soumissionLe) : null,
            'etat_dossier' => $candidature->statut_interne,
            'etat_eligibilite' => $candidature->statut_eligibilite_interne,
            'dossier_verrouille' => $candidature->dossier_verrouille ? 'oui' : 'non',
            'cqp_confirme' => $candidature->cqp_confirme ? 'oui' : 'non',
            'criteres_eliminatoires' => $criteres,
            'sc01_scolarise_actuellement' => $r?->sc01_scolarise_actuellement,
            'sc02_derniere_classe' => $r?->sc02_derniere_classe,
            'sc03_document_justifiant_niveau' => $r?->sc03_document_justifiant_niveau,
            'sc05_beneficiaire_formation_actuelle' => $r?->sc05_beneficiaire_formation_actuelle,
            'sc06_deja_beneficie_formation' => $r?->sc06_deja_beneficie_formation,
            'sc08_mene_a_terme' => $r?->sc08_mene_a_terme,
            'se01_vit_avec' => $r?->se01_vit_avec,
            'se02_orphelin' => $r?->se02_orphelin,
            'se03_situation_emploi' => $r?->se03_situation_emploi,
            'se04_source_revenu' => $r?->se04_source_revenu,
            'se05_personnes_a_charge' => $r?->se05_personnes_a_charge,
            'se06_soutien_menage' => $r?->se06_soutien_menage,
            'langue_ecrit' => $r?->langue_ecrit,
            'langue_parle' => $r?->langue_parle,
            'langue_comprehension' => $r?->langue_comprehension,
            'info_word' => $r?->info_word,
            'info_excel' => $r?->info_excel,
            'info_internet' => $r?->info_internet,
            'acces_plateau' => $r?->acces_plateau,
            'acces_deux_plateaux_vallons' => $r?->acces_deux_plateaux_vallons,
            'di01_disponible_lun_ven' => $r?->di01_disponible_lun_ven,
            'di02_contraintes' => $r?->di02_contraintes,
            'di03_engagement_complet' => $r?->di03_engagement_complet,
            'exp_hotellerie_nb' => $parDomaine['hotellerie']['nb'],
            'exp_hotellerie_duree_max' => $parDomaine['hotellerie']['duree_max'],
            'exp_restauration_nb' => $parDomaine['restauration']['nb'],
            'exp_restauration_duree_max' => $parDomaine['restauration']['duree_max'],
            'exp_commerce_nb' => $parDomaine['commerce']['nb'],
            'exp_commerce_duree_max' => $parDomaine['commerce']['duree_max'],
            'nb_pieces_dossier' => $candidature->piecesDossier->count(),
            'nb_justificatifs_experience' => $candidature->experiences
                ->filter(fn ($e) => $e->piece_justificative_id !== null)->count(),
            ...$piecesPresentes,
        ];
    }

    /**
     * Décide, UNE FOIS pour tout l'export, si les zones rares (hors
     * Abidjan/Intérieur, effectif global < k) doivent être fusionnées dans
     * Intérieur (« Hors Abidjan »), et — cas limite où ce groupe fusionné
     * resterait lui-même sous k — fusionnées en plus avec Abidjan
     * (« Non précisée / Autre », pour TOUTES les lignes de l'export).
     *
     * Renommer une zone rare seule (ex. « Hors Abidjan » pour 1 ligne)
     * ne suffirait pas : elle resterait isolée sous son nouveau nom. La
     * fusion DOIT grossir un groupe déjà ≥ k (ADR-38).
     *
     * @param  array<string, int>  $comptesZone  zone brute => effectif
     * @return array{zonesRares: list<string>, fusionnerAvecInterieur: bool, fusionnerAvecAbidjan: bool}
     */
    private function decisionFusionZone(array $comptesZone): array
    {
        $zonesRares = [];
        foreach ($comptesZone as $zone => $n) {
            $estAbidjanOuInterieur = in_array($zone, [NormalisationVille::ZONE_ABIDJAN, NormalisationVille::ZONE_INTERIEUR], true);
            if (! $estAbidjanOuInterieur && $n < ServiceRapports::SEUIL_MASQUAGE) {
                $zonesRares[] = $zone;
            }
        }

        if ($zonesRares === []) {
            return ['zonesRares' => [], 'fusionnerAvecInterieur' => false, 'fusionnerAvecAbidjan' => false];
        }

        $effectifFusionne = $comptesZone[NormalisationVille::ZONE_INTERIEUR] ?? 0;
        foreach ($zonesRares as $zone) {
            $effectifFusionne += $comptesZone[$zone];
        }

        return [
            'zonesRares' => $zonesRares,
            'fusionnerAvecInterieur' => true,
            'fusionnerAvecAbidjan' => $effectifFusionne < ServiceRapports::SEUIL_MASQUAGE,
        ];
    }

    /**
     * @param  array<string, mixed>  $brute
     * @param  array<string, int>  $comptesCommunes
     * @param  array<string, int>  $comptesVilles
     * @param  array{zonesRares: list<string>, fusionnerAvecInterieur: bool, fusionnerAvecAbidjan: bool}  $decisionFusionZone
     * @return array<string, mixed>
     */
    private function finaliserLigne(array $brute, array $comptesCommunes, array $comptesVilles, array $decisionFusionZone): array
    {
        $classement = $brute['_classement'];
        $zone = $classement['zone'];
        $communeAffichee = '';
        $zoneAffichee = $zone;
        $villeNormalisee = $zone;

        if ($decisionFusionZone['fusionnerAvecAbidjan']) {
            // Cas limite (ADR-38) : même Intérieur + zones rares combinés
            // restent sous k — fusion totale avec Abidjan, plus aucune ligne
            // de cet export ne peut porter une zone individualisée.
            unset($brute['_ville_brute'], $brute['_classement'], $brute['_residence_ci'], $brute['_valeurs_interdites']);

            return [...$brute, 'zone' => 'Non précisée / Autre', 'commune_abidjan' => '', 'ville_normalisee' => 'Non précisée / Autre'];
        }

        if ($zone === NormalisationVille::ZONE_ABIDJAN) {
            $villeNormalisee = NormalisationVille::ZONE_ABIDJAN;
            if ($classement['commune_abidjan'] !== null) {
                $effectif = $comptesCommunes[$classement['commune_abidjan']] ?? 0;
                $communeAffichee = $effectif < ServiceRapports::SEUIL_MASQUAGE
                    ? 'Autre commune'
                    : $classement['commune_abidjan'];
            }
        } elseif ($zone === NormalisationVille::ZONE_INTERIEUR) {
            $zoneAffichee = $decisionFusionZone['fusionnerAvecInterieur'] ? 'Hors Abidjan' : NormalisationVille::ZONE_INTERIEUR;
            $effectif = $comptesVilles[$classement['ville_interieur']] ?? 0;
            $villeNormalisee = $effectif < ServiceRapports::SEUIL_MASQUAGE ? 'Autre ville' : $classement['ville_interieur'];
        } elseif (in_array($zone, $decisionFusionZone['zonesRares'], true)) {
            // Zone rare (Étranger, Non précisée, future zone) fusionnée DANS
            // le groupe Intérieur — jamais affichée seule (ADR-38).
            $zoneAffichee = 'Hors Abidjan';
            $villeNormalisee = 'Autre ville';
        }

        unset($brute['_ville_brute'], $brute['_classement'], $brute['_residence_ci'], $brute['_valeurs_interdites']);

        return [...$brute, 'zone' => $zoneAffichee, 'commune_abidjan' => $communeAffichee, 'ville_normalisee' => $villeNormalisee];
    }

    /**
     * @param  list<array<string, mixed>>  $lignesBrutes
     * @return array{zone_etrangere_residence_ci_vrai: int, zone_ci_residence_ci_faux: int}
     */
    private function controleCoherence(array $lignesBrutes): array
    {
        $etrangerResidentCi = 0;
        $ciNonResident = 0;

        foreach ($lignesBrutes as $l) {
            $zone = $l['_classement']['zone'];
            if ($zone === NormalisationVille::ZONE_ETRANGER && $l['_residence_ci'] === true) {
                $etrangerResidentCi++;
            }
            if (in_array($zone, [NormalisationVille::ZONE_ABIDJAN, NormalisationVille::ZONE_INTERIEUR], true)
                && $l['_residence_ci'] === false) {
                $ciNonResident++;
            }
        }

        return ['zone_etrangere_residence_ci_vrai' => $etrangerResidentCi, 'zone_ci_residence_ci_faux' => $ciNonResident];
    }

    /**
     * @param  array<string, int>  $comptesCommunes
     * @param  array<string, int>  $comptesVilles
     * @return array{effectifs: array<string, int|string>, total: ?int}
     */
    private function feuilleVilles(array $comptesCommunes, array $comptesVilles): array
    {
        $tout = [];
        foreach ($comptesCommunes as $commune => $n) {
            $tout["Abidjan — {$commune}"] = $n;
        }
        foreach ($comptesVilles as $ville => $n) {
            $tout["Intérieur — {$ville}"] = $n;
        }

        return SuppressionSecondaire::appliquer($tout, ServiceRapports::SEUIL_MASQUAGE);
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array<string, array{effectifs: array<string, int|string>, total: ?int}>
     */
    private function feuilleSynthese(array $lignes): array
    {
        $sexe = [];
        $zone = [];
        $filiere = [];
        foreach ($lignes as $l) {
            $sexe[$l['sexe']] = ($sexe[$l['sexe']] ?? 0) + 1;
            $zone[$l['zone']] = ($zone[$l['zone']] ?? 0) + 1;
            $filiere[$l['filiere']] = ($filiere[$l['filiere']] ?? 0) + 1;
        }

        return [
            'sexe' => SuppressionSecondaire::appliquer($sexe, ServiceRapports::SEUIL_MASQUAGE),
            'zone' => SuppressionSecondaire::appliquer($zone, ServiceRapports::SEUIL_MASQUAGE),
            'filiere' => SuppressionSecondaire::appliquer($filiere, ServiceRapports::SEUIL_MASQUAGE),
        ];
    }

    private function age(CarbonInterface $naissance, CarbonInterface $reference): int
    {
        // Même formule que App\Domain\Eligibilite\ServiceEligibilite::age() —
        // référence = campagne.date_ouverture (correction #4) : cohérente avec
        // la règle d'éligibilité 18-30 appliquée à LA SOUMISSION (pas `now()`,
        // qui est la référence utilisée par ServiceEligibiliteInitiale à
        // l'inscription — cf. Dictionnaire de l'export).
        return (int) $naissance->copy()->startOfDay()->diffInYears($reference->copy()->startOfDay());
    }

    private function trancheAge(int $age): string
    {
        $debut = intdiv($age, 3) * 3;

        return "{$debut}-".($debut + 2);
    }

    private function dureeMax(Collection $experiences): ?string
    {
        if ($experiences->isEmpty()) {
            return null;
        }

        return $experiences
            ->sortByDesc(fn ($e) => self::ORDRE_DUREE[$e->duree_categorie] ?? 0)
            ->first()
            ->duree_categorie;
    }
}
