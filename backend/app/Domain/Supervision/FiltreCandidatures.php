<?php

namespace App\Domain\Supervision;

use App\Models\Candidat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * FILTRES de la supervision des candidatures — SOURCE UNIQUE partagée par :
 *  - la recherche paginée `POST /api/admin/candidatures` (ADR-39) ;
 *  - l'affectation automatique par critères (ADR-45) : « les N dossiers
 *    correspondant à votre recherche et vos filtres » doit désigner EXACTEMENT
 *    l'ensemble que la liste affiche, jamais une seconde implémentation qui
 *    pourrait diverger.
 *
 * Ne porte QUE la sélection (pas le tri, pas la pagination, pas
 * `inclure_brouillons`, qui restent l'affaire de l'appelant).
 *
 * `eligibilite`/`decision` sont des LISTES BLANCHES strictes (`Rule::in`),
 * reprises des contraintes CHECK réelles de `candidature`/`decision_candidature`
 * (migrations `2026_09_02_100016` et `2026_09_02_100028`) — jamais une valeur
 * libre injectée dans un `where`. Les autres valeurs ne passent que par des
 * liaisons de paramètres (`where`, `ilike`), jamais par du SQL brut.
 */
final class FiltreCandidatures
{
    /** Clés de critères acceptées (liste blanche, aussi pour `array:` côté requête). */
    public const CLES = ['recherche', 'statut_interne', 'filiere', 'evaluateur', 'campagne', 'eligibilite', 'decision'];

    /**
     * Règles de validation des critères, préfixées si besoin
     * (`criteres.` pour un objet imbriqué).
     *
     * @return array<string, mixed>
     */
    public static function regles(string $prefixe = ''): array
    {
        return [
            $prefixe.'recherche' => ['sometimes', 'nullable', 'string', 'max:100'],
            $prefixe.'statut_interne' => ['sometimes', 'nullable', 'string'],
            $prefixe.'filiere' => ['sometimes', 'nullable', 'string'],
            $prefixe.'evaluateur' => ['sometimes', 'nullable', 'string'],
            $prefixe.'campagne' => ['sometimes', 'nullable', 'string'],
            $prefixe.'eligibilite' => ['sometimes', 'nullable', Rule::in(['non_verifie', 'eligible', 'non_eligible'])],
            $prefixe.'decision' => ['sometimes', 'nullable', Rule::in(['retenu', 'liste_attente', 'non_retenu', 'indisponible', 'sans_decision'])],
        ];
    }

    /**
     * Applique les critères à une requête sur `candidature` qui a DÉJÀ
     * l'INNER JOIN `candidat` (nécessaire à la recherche par nom).
     *
     * @param  array<string, mixed>  $criteres
     */
    public static function appliquer(Builder $query, array $criteres): void
    {
        if ($statut = $criteres['statut_interne'] ?? null) {
            $query->where('candidature.statut_interne', $statut);
        }
        if ($filiere = $criteres['filiere'] ?? null) {
            $query->whereHas('filiere', fn ($q) => $q->where('code', $filiere));
        }
        if ($evaluateur = $criteres['evaluateur'] ?? null) {
            $evaluateur === 'non_affecte'
                ? $query->whereNull('candidature.evaluateur_id')
                : $query->where('candidature.evaluateur_id', $evaluateur);
        }
        if ($campagne = $criteres['campagne'] ?? null) {
            $query->where('candidature.campagne_id', $campagne);
        }

        // Éligibilité : colonne DIRECTE de `candidature` (pas une relation) —
        // un simple `where`, `whereHas` ne s'applique pas ici.
        if ($eligibilite = $criteres['eligibilite'] ?? null) {
            $query->where('candidature.statut_eligibilite_interne', $eligibilite);
        }

        // Décision : vit dans `decision_candidature` (relation 1-1 optionnelle) —
        // EXISTS/whereHas, jamais un LEFT JOIN + WHERE (qui transformerait
        // silencieusement la jointure en INNER et casserait `sans_decision`).
        if ($decision = $criteres['decision'] ?? null) {
            if ($decision === 'sans_decision') {
                $query->whereDoesntHave('decisionCandidature');
            } else {
                $query->whereHas('decisionCandidature', fn ($q) => $q->where('decision', $decision));
            }
        }

        self::appliquerRecherche($query, $criteres['recherche'] ?? null);
    }

    /**
     * Chaque mot du terme doit être trouvé (ET) dans le numéro de dossier OU
     * dans `candidat.recherche_normalisee` (prénom+nom précalculés, insensibles
     * casse/accents/apostrophes/tirets — `Candidat::normaliserRecherche()`,
     * SOURCE UNIQUE réutilisée ici pour normaliser le terme lui-même) — en
     * « contient » (jamais en préfixe seul) : « 370 » trouve CASA-2026-000370,
     * l'ordre prénom/nom n'importe pas puisque chaque mot est cherché
     * indépendamment dans la concaténation complète. Bornée à
     * numero_dossier/prénom/nom — jamais aux scores/motifs (même principe que
     * `AuditController::index`). `ilike` (opérateur Eloquent ordinaire, pas du
     * SQL brut — même précédent qu'`AuditController`) est déjà insensible à la
     * casse.
     */
    private static function appliquerRecherche(Builder $query, ?string $recherche): void
    {
        if ($recherche === null || trim($recherche) === '') {
            return;
        }

        $mots = array_filter(preg_split('/\s+/', trim($recherche)) ?: [], fn (string $m) => $m !== '');

        foreach ($mots as $mot) {
            $motDossier = self::echapperLike($mot);
            $motNom = self::echapperLike(Candidat::normaliserRecherche($mot, ''));

            $query->where(function (Builder $q) use ($motDossier, $motNom) {
                $q->where('candidature.numero_dossier', 'ilike', '%'.$motDossier.'%')
                    ->orWhere('candidat.recherche_normalisee', 'ilike', '%'.$motNom.'%');
            });
        }
    }

    private static function echapperLike(string $mot): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $mot);
    }
}
