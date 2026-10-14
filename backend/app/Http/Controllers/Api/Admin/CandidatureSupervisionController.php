<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RechercherCandidaturesRequest;
use App\Http\Resources\Admin\CandidatureAdminResource;
use App\Models\Candidat;
use App\Models\Candidature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * SUPERVISION des candidatures (Lot 6a, recherche Lot recherche-admin) —
 * `POST /api/admin/candidatures`, administrateur strict. Vue transverse
 * (toutes filières) pour choisir qui affecter et suivre l'avancement.
 *
 *   { recherche?, statut_interne?, filiere=<code>, evaluateur=<id|non_affecte>,
 *     campagne=<id>, eligibilite?, decision?, inclure_brouillons?,
 *     tri_colonne?, tri_direction?, page? }
 *
 * POST (pas GET, ADR-39) : `recherche` contient potentiellement un nom de
 * candidat — ne doit jamais transiter par une query string archivée en clair
 * par les access log nginx/Apache (même principe que
 * `/mot-de-passe/nouveau { access_log off; }`).
 *
 * `CandidatureAdminResource` est 🔴 admin-only : jamais réutilisée côté candidat
 * ou évaluateur.
 */
class CandidatureSupervisionController extends Controller
{
    public function rechercher(RechercherCandidaturesRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $query = Candidature::query()
            ->select('candidature.*')
            // INNER JOIN : `candidature.candidat_id` est NOT NULL, relation 1-1 —
            // aucune ligne dupliquée, nécessaire pour chercher/trier par nom.
            ->join('candidat', 'candidat.id', '=', 'candidature.candidat_id')
            // LEFT JOIN : `evaluation_dossier`/`entretien` ont au plus 1 ligne par
            // candidature (clé primaire = candidature_id, cf. audit) — aucun risque
            // de doublon, nécessaire uniquement pour pouvoir trier par score.
            ->leftJoin('evaluation_dossier', 'evaluation_dossier.candidature_id', '=', 'candidature.id')
            ->leftJoin('entretien', 'entretien.candidature_id', '=', 'candidature.id')
            ->with(['candidat', 'filiere', 'evaluateur', 'evaluationDossier', 'entretien', 'decisionCandidature']);

        if (! $request->boolean('inclure_brouillons')) {
            $query->where('candidature.statut_interne', '!=', 'brouillon');
        }
        if ($statut = $validated['statut_interne'] ?? null) {
            $query->where('candidature.statut_interne', $statut);
        }
        if ($filiere = $validated['filiere'] ?? null) {
            $query->whereHas('filiere', fn ($q) => $q->where('code', $filiere));
        }
        if ($evaluateur = $validated['evaluateur'] ?? null) {
            $evaluateur === 'non_affecte'
                ? $query->whereNull('candidature.evaluateur_id')
                : $query->where('candidature.evaluateur_id', $evaluateur);
        }
        if ($campagne = $validated['campagne'] ?? null) {
            $query->where('candidature.campagne_id', $campagne);
        }

        // Éligibilité : colonne DIRECTE de `candidature` (pas une relation) —
        // un simple `where`, `whereHas` ne s'applique pas ici.
        if ($eligibilite = $validated['eligibilite'] ?? null) {
            $query->where('candidature.statut_eligibilite_interne', $eligibilite);
        }

        // Décision : vit dans `decision_candidature` (relation 1-1 optionnelle) —
        // EXISTS/whereHas, jamais un LEFT JOIN + WHERE (qui transformerait
        // silencieusement la jointure en INNER et casserait `sans_decision`).
        if ($decision = $validated['decision'] ?? null) {
            if ($decision === 'sans_decision') {
                $query->whereDoesntHave('decisionCandidature');
            } else {
                $query->whereHas('decisionCandidature', fn ($q) => $q->where('decision', $decision));
            }
        }

        $this->appliquerRecherche($query, $validated['recherche'] ?? null);
        $this->appliquerTri($query, $validated['tri_colonne'] ?? null, $validated['tri_direction'] ?? null);

        return CandidatureAdminResource::collection($query->paginate(20)->withQueryString());
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
    private function appliquerRecherche(Builder $query, ?string $recherche): void
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

    /**
     * Toujours un second critère `candidature.id` (tri stable, aucun ex
     * æquo). Colonne/direction déjà validées en liste blanche par
     * `RechercherCandidaturesRequest` (`Rule::in`).
     */
    private function appliquerTri(Builder $query, ?string $colonne, ?string $direction): void
    {
        $dir = $direction === 'asc' ? 'asc' : 'desc';

        switch ($colonne) {
            case 'numero_dossier':
                $this->trierAvecNullsLast($query, 'candidature.numero_dossier', $dir);
                break;
            case 'candidat':
                $this->trierAvecNullsLast($query, 'candidat.nom', $dir);
                $this->trierAvecNullsLast($query, 'candidat.prenom', $dir);
                break;
            case 'score_dossier':
                $this->trierAvecNullsLast($query, 'evaluation_dossier.score_total', $dir);
                break;
            case 'score_entretien':
                $this->trierAvecNullsLast($query, 'entretien.score_total', $dir);
                break;
            default:
                $this->trierAvecNullsLast($query, 'candidature.date_soumission', $dir);
        }

        $query->orderBy('candidature.id');
    }

    /**
     * SEUL point de ce fichier qui appelle `orderByRaw` — whitelisté
     * explicitement dans `PasDeSqlBrutTest` (Lot 10), preuve de sûreté dans
     * son commentaire. `$colonneQualifiee` n'est JAMAIS une valeur de requête :
     * uniquement un des 5 littéraux choisis par le `switch` d'`appliquerTri()`,
     * jamais une chaîne venant de `$request`. `$dir` est verrouillé à
     * 'asc'/'desc' juste au-dessus. `NULLS LAST` n'a pas d'équivalent portable
     * dans le Query Builder Eloquent (MySQL ne le supporte pas, Laravel ne l'a
     * donc jamais abstrait) — sans lui, PostgreSQL met les `NULL` en tête par
     * défaut en tri descendant.
     */
    private function trierAvecNullsLast(Builder $query, string $colonneQualifiee, string $dir): void
    {
        $query->orderByRaw("$colonneQualifiee $dir NULLS LAST");
    }

    private static function echapperLike(string $mot): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $mot);
    }
}
