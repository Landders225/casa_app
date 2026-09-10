<?php

namespace App\Domain\Rapports;

use App\Models\Campagne;
use App\Models\Candidature;
use Illuminate\Support\Collection;

/**
 * RAPPORTS & STATISTIQUES de pilotage (Lot 11c, ADR-30).
 *
 * SOURCE UNIQUE d'agrégation — l'écran ET l'export CSV passent par ici, donc le
 * garde-fou anti-ré-identification s'applique à l'identique aux deux.
 *
 * Ne renvoie QUE des agrégats (comptes, distributions, taux). JAMAIS une ligne
 * individuelle (pas de « candidat X = score Y »). Les scores/décisions sont 🔴
 * (ADR-02) : l'agrégat est légitime pour le pilotage, la ré-identification ne
 * l'est pas.
 *
 * Garde-fou — SUPPRESSION DES PETITES CELLULES (k-anonymat, k = SEUIL_MASQUAGE) :
 *  - toute distribution dont l'effectif total est < k est renvoyée `null`
 *    (l'écran affiche « effectif insuffisant ») ;
 *  - les villes dont l'effectif est < k sont fondues dans « Autres villes »
 *    (jamais nommées) ;
 *  - un taux (%) n'est calculé que si son dénominateur est ≥ k ;
 *  - AUCUNE cross-tabulation (sexe × filière, score × filière, …) — seules des
 *    distributions marginales à une dimension.
 *
 * Périmètre : candidatures SOUMISES (`statut_interne != 'brouillon'`) — un
 * brouillon n'est pas une candidature de pilotage.
 */
class ServiceRapports
{
    /** k-anonymat : effectif minimal sous lequel une cellule est masquée. */
    public const SEUIL_MASQUAGE = 5;

    /** Bornes fixes des tranches de la distribution des scores /100 (largeur 20). */
    private const BORNES_SCORE = [0, 20, 40, 60, 80, 100];

    /** Nombre maximal de villes nommées (le reste → « Autres villes »). */
    private const MAX_VILLES = 8;

    /**
     * @return array<string, mixed>
     */
    public function agreger(?Campagne $campagne): array
    {
        $rows = Candidature::query()
            ->where('statut_interne', '!=', 'brouillon')
            ->when($campagne !== null, fn ($q) => $q->where('campagne_id', $campagne->id))
            ->with(['candidat', 'filiere', 'evaluationDossier', 'entretien', 'decisionCandidature'])
            ->get();

        $soumises = $rows->count();
        $evaluees = $rows->where('statut_interne', 'evalue');
        $verifiees = $rows->whereIn('statut_eligibilite_interne', ['eligible', 'non_eligible']);
        $eligibles = $rows->where('statut_eligibilite_interne', 'eligible');
        $retenus = $rows->filter(fn (Candidature $c) => $c->decisionCandidature?->decision === 'retenu');
        $avecDecision = $rows->filter(fn (Candidature $c) => $c->decisionCandidature !== null);
        $entretiensTenus = $rows->filter(fn (Candidature $c) => in_array($c->entretien?->presence, ['present', 'absent'], true));

        return [
            'perimetre' => [
                'campagne' => $campagne !== null
                    ? ['id' => $campagne->id, 'nom' => $campagne->nom, 'statut' => $campagne->statut]
                    : null,
                'toutes_campagnes' => $campagne === null,
                'candidatures' => $soumises,
                'seuil_masquage' => self::SEUIL_MASQUAGE,
            ],

            'kpis' => [
                'candidatures' => $soumises,
                'eligibles' => $eligibles->count(),
                'evaluees' => $evaluees->count(),
                'retenus' => $retenus->count(),
                // Taux masqués sous le seuil (dénominateur trop petit = ré-identification).
                'taux_eligibilite' => $this->taux($eligibles->count(), $verifiees->count()),
                'taux_selection' => $this->taux($retenus->count(), $evaluees->count()),
            ],

            // Comptages de candidatures par filière — PAS de taux ni de croisement
            // décision/sexe/score (cross-tab interdite). Toujours affiché (un
            // comptage n'est pas une valeur 🔴).
            'par_filiere' => $rows
                ->groupBy(fn (Candidature $c) => $c->filiere->code)
                ->map(fn (Collection $grp) => [
                    'filiere' => ['code' => $grp->first()->filiere->code, 'nom' => $grp->first()->filiere->nom],
                    'candidatures' => $grp->count(),
                ])
                ->sortByDesc('candidatures')
                ->values()
                ->all(),

            'repartition_sexe' => $this->distributionMasquable($soumises, fn () => [
                'F' => $rows->filter(fn (Candidature $c) => $c->candidat->sexe === 'F')->count(),
                'H' => $rows->filter(fn (Candidature $c) => $c->candidat->sexe === 'H')->count(),
            ]),

            'distribution_scores' => $this->distributionMasquable($evaluees->count(), fn () => [
                'bornes' => self::BORNES_SCORE,
                'effectifs' => $this->binnerScores($evaluees),
            ]),

            'repartition_decisions' => $this->distributionMasquable($avecDecision->count(), fn () => [
                'retenu' => $this->compter($avecDecision, fn (Candidature $c) => $c->decisionCandidature->decision === 'retenu'),
                'liste_attente' => $this->compter($avecDecision, fn (Candidature $c) => $c->decisionCandidature->decision === 'liste_attente'),
                'non_retenu' => $this->compter($avecDecision, fn (Candidature $c) => $c->decisionCandidature->decision === 'non_retenu'),
                'indisponible' => $this->compter($avecDecision, fn (Candidature $c) => $c->decisionCandidature->decision === 'indisponible'),
            ]),

            'presence_entretien' => $this->distributionMasquable($entretiensTenus->count(), fn () => [
                'present' => $this->compter($entretiensTenus, fn (Candidature $c) => $c->entretien->presence === 'present'),
                'absent' => $this->compter($entretiensTenus, fn (Candidature $c) => $c->entretien->presence === 'absent'),
            ]),

            // Villes < k fondues dans « Autres villes ». Masqué si base < k.
            'top_villes' => $soumises < self::SEUIL_MASQUAGE ? null : $this->topVilles($rows),
        ];
    }

    /**
     * @param  Collection<int, Candidature>  $rows
     * @param  callable(Candidature):bool  $predicat
     */
    private function compter(Collection $rows, callable $predicat): int
    {
        return $rows->filter($predicat)->count();
    }

    /**
     * Taux en % (entier) — `null` si le dénominateur est sous le seuil.
     */
    private function taux(int $numerateur, int $denominateur): ?int
    {
        if ($denominateur < self::SEUIL_MASQUAGE) {
            return null;
        }

        return (int) round($numerateur / $denominateur * 100);
    }

    /**
     * Retourne la distribution produite par `$calcul`, ou `null` si l'effectif
     * total `$total` est sous le seuil k.
     *
     * @param  callable():array<string, mixed>  $calcul
     * @return array<string, mixed>|null
     */
    private function distributionMasquable(int $total, callable $calcul): ?array
    {
        return $total < self::SEUIL_MASQUAGE ? null : $calcul();
    }

    /**
     * 5 tranches [0-20[, [20-40[, [40-60[, [60-80[, [80-100]. Score final =
     * somme des snapshots figés, plafond 100 (cf. ServiceClassement::scoreFinal).
     *
     * @param  Collection<int, Candidature>  $evaluees
     * @return list<int>
     */
    private function binnerScores(Collection $evaluees): array
    {
        $bins = [0, 0, 0, 0, 0];

        foreach ($evaluees as $candidature) {
            $score = min(
                100.0,
                (float) ($candidature->evaluationDossier?->score_total ?? 0)
                + (float) ($candidature->entretien?->score_total ?? 0),
            );
            $index = min(4, (int) ($score / 20));
            $bins[$index]++;
        }

        return $bins;
    }

    /**
     * @param  Collection<int, Candidature>  $rows
     * @return list<array{ville:string, candidatures:int}>
     */
    private function topVilles(Collection $rows): array
    {
        $comptes = $rows
            ->countBy(fn (Candidature $c) => $c->candidat->ville_residence)
            ->sortDesc();

        // Villes nommées : effectif ≥ k, plafonné à MAX_VILLES entrées.
        $nommees = $comptes->filter(fn (int $n) => $n >= self::SEUIL_MASQUAGE)->take(self::MAX_VILLES);
        // « Autres » = tout le reste (villes sous le seuil + au-delà du plafond).
        $autres = $comptes->sum() - $nommees->sum();

        $liste = [];
        foreach ($nommees as $ville => $n) {
            $liste[] = ['ville' => $ville, 'candidatures' => $n];
        }
        if ($autres > 0) {
            $liste[] = ['ville' => 'Autres villes', 'candidatures' => $autres];
        }

        return $liste;
    }
}
