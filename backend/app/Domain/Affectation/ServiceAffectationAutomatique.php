<?php

namespace App\Domain\Affectation;

use App\Domain\Supervision\FiltreCandidatures;
use App\Exceptions\AffectationAutomatiqueRefusee;
use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\MembreEquipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AFFECTATION AUTOMATIQUE par critères (ADR-45) — « les N dossiers
 * correspondant à votre recherche et vos filtres », répartis entre évaluateurs.
 *
 * Sélection PAR CRITÈRES (jamais une liste d'identifiants envoyée par le
 * client) : les mêmes filtres que la liste (`FiltreCandidatures`, source
 * unique), brouillons toujours exclus — exactement l'ensemble que
 * `POST /admin/candidatures` compte dans `meta.total`.
 *
 * Dossiers AFFECTÉS parmi ces N : `soumis` ET non affectés ET non
 * `non_eligible`. Les dossiers déjà affectés sont TOUJOURS ignorés (la
 * réaffectation reste un acte manuel, `AffectationController`).
 *
 * Garde-fous (un code applicatif chacun, `AffectationAutomatiqueRefusee`) :
 *  - N recalculé > PLAFOND_DOSSIERS → 422 `plafond_depasse` ;
 *  - N recalculé ≠ N attendu par le client → 409 `liste_modifiee` ;
 *  - (exécution) M recalculé SOUS VERROU ≠ M de l'aperçu → 409 `liste_modifiee` ;
 *  - une campagne concernée déjà publiée → 409 `campagne_publiee` (une
 *    campagne CLÔTURÉE reste le cas normal, autorisée) ;
 *  - évaluateur restreint inconnu/inactif/non évaluateur → 422 ; aucun → 422 ;
 *  - aucun dossier affectable → 422 `rien_a_affecter`.
 *
 * Charge existante = dossiers `en_instruction` de l'évaluateur dans la ou les
 * campagnes des dossiers concernés (pas les cohortes précédentes).
 *
 * Idempotent : une seconde exécution ne trouve plus aucun dossier libre (les
 * conditions d'affectabilité sont REVÉRIFIÉES dans chaque `UPDATE`) — aucun
 * dossier ne peut recevoir deux affectations.
 *
 * Journal : 1 ligne globale (nombres uniquement) + 1 ligne PAR dossier
 * (numéro + évaluateur, même forme que l'affectation manuelle, D-6a-1 —
 * traçabilité en cas de litige). Rien dans les logs applicatifs.
 *
 * Cibles par évaluateur (Lot 3, ADR-48) : `$cibles` facultatif, résolu par
 * `ResolutionCibles` puis réparti par `RepartitionCiblee` — remplace
 * `RepartitionEquilibree` UNIQUEMENT quand fourni (sinon comportement
 * ADR-45 inchangé). Peut laisser un reliquat de dossiers NON affectés
 * (`PlanAffectation::$nonAffectes`), ce n'est pas une erreur.
 */
final class ServiceAffectationAutomatique
{
    /** Plafond de sécurité par opération (sur N, les dossiers correspondants). */
    public const PLAFOND_DOSSIERS = 1000;

    /**
     * @param  array<string, mixed>  $criteres
     * @param  list<string>|null  $evaluateurIds
     * @param  array<string, int>|null  $cibles  id évaluateur => nombre à recevoir (Lot 3, ADR-48) ; null = répartition égale classique
     */
    public function apercu(array $criteres, ?array $evaluateurIds, int $nombreCorrespondants, ?array $cibles = null): PlanAffectation
    {
        $n = $this->verifierCorrespondants($criteres, $nombreCorrespondants);
        $evaluateurs = $this->evaluateurs($evaluateurIds);
        $dossiers = $this->requeteAffectables($criteres)
            ->orderBy('candidature.numero_dossier')
            ->orderBy('candidature.id')
            ->get(['candidature.id', 'candidature.campagne_id']);

        $this->verifierCampagnesNonPubliees($dossiers);

        return $this->planifier($criteres, $n, $evaluateurs, $dossiers, $cibles);
    }

    /**
     * @param  array<string, mixed>  $criteres
     * @param  list<string>|null  $evaluateurIds
     * @param  array<string, int>|null  $cibles  id évaluateur => nombre à recevoir (Lot 3, ADR-48) ; null = répartition égale classique
     * @param  array<string, int>|null  $repartitionAttendue  id évaluateur => a_recevoir vu à l'aperçu (recontrôlé si `cibles` fourni)
     */
    public function executer(
        array $criteres,
        ?array $evaluateurIds,
        int $nombreCorrespondants,
        int $nombreAffectables,
        User $auteur,
        ?array $cibles = null,
        ?array $repartitionAttendue = null,
        ?int $nonAffectesAttendu = null,
    ): PlanAffectation {
        return DB::transaction(function () use (
            $criteres, $evaluateurIds, $nombreCorrespondants, $nombreAffectables, $auteur,
            $cibles, $repartitionAttendue, $nonAffectesAttendu,
        ) {
            $n = $this->verifierCorrespondants($criteres, $nombreCorrespondants);
            $evaluateurs = $this->evaluateurs($evaluateurIds);

            // Verrou sur les lignes candidates : une affectation concurrente
            // (manuelle ou automatique) attend, puis ces lignes sont
            // réévaluées par PostgreSQL — celles qui ne sont plus libres
            // sortent du résultat et le recompte ci-dessous échoue proprement.
            $ids = $this->requeteAffectables($criteres)->pluck('candidature.id');
            $dossiers = $this->filtreAffectable(Candidature::query()->whereIn('candidature.id', $ids))
                ->orderBy('candidature.numero_dossier')
                ->orderBy('candidature.id')
                ->lockForUpdate()
                ->get(['candidature.id', 'candidature.numero_dossier', 'candidature.campagne_id']);

            if ($dossiers->count() !== $nombreAffectables) {
                throw AffectationAutomatiqueRefusee::listeModifiee(
                    "Les dossiers affectables ont changé ({$dossiers->count()} au lieu de {$nombreAffectables}) : recalculez l'aperçu et reconfirmez.",
                );
            }
            $this->verifierCampagnesNonPubliees($dossiers);

            $plan = $this->planifier($criteres, $n, $evaluateurs, $dossiers, $cibles);
            $charges = $this->charges($evaluateurs, $dossiers);

            if ($cibles !== null) {
                // Lot 3 (ADR-48) : la charge de chaque évaluateur a pu changer
                // depuis l'aperçu (une autre affectation concurrente dans la
                // même campagne) — ce qui déplace l'arrondi du reliquat entre
                // évaluateurs sans cible. Recalculée sous le même verrou que
                // les dossiers ci-dessus, comparée à ce que l'aperçu a montré :
                // tout écart → même refus que `nombre_affectables` (recalculer, reconfirmer).
                $ciblesFinales = ResolutionCibles::resoudre($charges, $cibles, $dossiers->count());
                $nonAffectes = $dossiers->count() - array_sum($ciblesFinales);

                if ($repartitionAttendue !== null) {
                    $attendu = $repartitionAttendue;
                    ksort($attendu);
                    $recalcule = $ciblesFinales;
                    ksort($recalcule);
                    if ($attendu !== $recalcule || $nonAffectesAttendu !== $nonAffectes) {
                        throw AffectationAutomatiqueRefusee::listeModifiee(
                            "La répartition prévue a changé (charge des évaluateurs modifiée entre-temps) : recalculez l'aperçu et reconfirmez.",
                        );
                    }
                }

                $attribution = RepartitionCiblee::repartir($ciblesFinales, $dossiers->pluck('id')->all());
            } else {
                $attribution = RepartitionEquilibree::repartir($charges, $dossiers->pluck('id')->all());
            }

            $parEvaluateur = [];
            foreach ($attribution as $dossierId => $evaluateurId) {
                $parEvaluateur[$evaluateurId][] = $dossierId;
            }

            // 1 UPDATE par évaluateur, conditions d'affectabilité REVÉRIFIÉES :
            // si une seule ligne manque, tout est annulé (jamais un état partiel).
            foreach ($parEvaluateur as $evaluateurId => $dossierIds) {
                $modifiees = $this->filtreAffectable(Candidature::query()->whereIn('candidature.id', $dossierIds))
                    ->update(['evaluateur_id' => $evaluateurId, 'statut_interne' => 'en_instruction']);

                if ($modifiees !== count($dossierIds)) {
                    throw AffectationAutomatiqueRefusee::listeModifiee(
                        "Des dossiers ont été modifiés pendant l'opération : recalculez l'aperçu et reconfirmez.",
                    );
                }
            }

            $this->journaliser($auteur, $evaluateurs, $dossiers, $attribution, $cibles, $plan->nonAffectes);

            return $plan;
        });
    }

    /**
     * N : les dossiers correspondant aux critères (brouillons exclus — même
     * ensemble que `meta.total` de la liste).
     *
     * @param  array<string, mixed>  $criteres
     */
    private function verifierCorrespondants(array $criteres, int $attendu): int
    {
        $n = $this->requeteCorrespondants($criteres)->count();

        if ($n > self::PLAFOND_DOSSIERS) {
            throw AffectationAutomatiqueRefusee::plafondDepasse($n, self::PLAFOND_DOSSIERS);
        }
        if ($n !== $attendu) {
            throw AffectationAutomatiqueRefusee::listeModifiee(
                "La liste a changé ({$n} dossiers correspondent au lieu de {$attendu}) : rechargez la liste et reconfirmez votre sélection.",
            );
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $criteres
     * @return Builder<Candidature>
     */
    private function requeteCorrespondants(array $criteres): Builder
    {
        $query = Candidature::query()
            // INNER JOIN 1-1 (`candidat_id` NOT NULL) : aucune ligne dupliquée,
            // nécessaire à la recherche par nom (`FiltreCandidatures`).
            ->join('candidat', 'candidat.id', '=', 'candidature.candidat_id')
            ->where('candidature.statut_interne', '!=', 'brouillon');

        FiltreCandidatures::appliquer($query, $criteres);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $criteres
     * @return Builder<Candidature>
     */
    private function requeteAffectables(array $criteres): Builder
    {
        return $this->filtreAffectable($this->requeteCorrespondants($criteres));
    }

    /**
     * @param  Builder<Candidature>  $query
     * @return Builder<Candidature>
     */
    private function filtreAffectable(Builder $query): Builder
    {
        return $query
            ->where('candidature.statut_interne', 'soumis')
            ->whereNull('candidature.evaluateur_id')
            ->where('candidature.statut_eligibilite_interne', '!=', 'non_eligible');
    }

    /**
     * Évaluateurs ACTIFS de rôle `evaluateur` — tous par défaut, ou la
     * restriction demandée (chacun doit en faire partie). Ordre = ordre de
     * départage de la répartition (nom, prénom, id).
     *
     * @param  list<string>|null  $restriction
     * @return Collection<int, MembreEquipe>
     */
    private function evaluateurs(?array $restriction): Collection
    {
        $query = MembreEquipe::query()
            ->whereHas('utilisateur', fn ($q) => $q->where('role', 'evaluateur')->where('actif', true))
            ->orderBy('nom')
            ->orderBy('prenom')
            ->orderBy('id');

        if ($restriction !== null) {
            $query->whereIn('id', $restriction);
        }

        $evaluateurs = $query->get();

        if ($restriction !== null && $evaluateurs->count() !== count($restriction)) {
            throw AffectationAutomatiqueRefusee::evaluateurInvalide();
        }
        if ($evaluateurs->isEmpty()) {
            throw AffectationAutomatiqueRefusee::aucunEvaluateur();
        }

        return $evaluateurs;
    }

    /**
     * @param  Collection<int, Candidature>  $dossiers
     */
    private function verifierCampagnesNonPubliees(Collection $dossiers): void
    {
        $campagnes = $dossiers->pluck('campagne_id')->unique()->values();

        if (GardeCampagnePubliee::estPubliee($campagnes)) {
            throw AffectationAutomatiqueRefusee::campagnePubliee();
        }
    }

    /**
     * Charge existante de chaque évaluateur : dossiers `en_instruction` dans
     * la ou les campagnes des dossiers concernés. Clés dans l'ordre de départage.
     *
     * @param  Collection<int, MembreEquipe>  $evaluateurs
     * @param  Collection<int, Candidature>  $dossiers
     * @return array<string, int>
     */
    private function charges(Collection $evaluateurs, Collection $dossiers): array
    {
        $parEvaluateur = Candidature::query()
            ->whereIn('campagne_id', $dossiers->pluck('campagne_id')->unique()->values())
            ->where('statut_interne', 'en_instruction')
            ->whereIn('evaluateur_id', $evaluateurs->pluck('id'))
            ->pluck('evaluateur_id')
            ->countBy();

        $charges = [];
        foreach ($evaluateurs as $evaluateur) {
            $charges[$evaluateur->id] = (int) ($parEvaluateur[$evaluateur->id] ?? 0);
        }

        return $charges;
    }

    /**
     * @param  array<string, mixed>  $criteres
     * @param  Collection<int, MembreEquipe>  $evaluateurs
     * @param  Collection<int, Candidature>  $dossiers
     * @param  array<string, int>|null  $cibles
     */
    private function planifier(array $criteres, int $n, Collection $evaluateurs, Collection $dossiers, ?array $cibles = null): PlanAffectation
    {
        if ($dossiers->isEmpty()) {
            throw AffectationAutomatiqueRefusee::rienAAffecter();
        }

        $charges = $this->charges($evaluateurs, $dossiers);

        if ($cibles !== null) {
            $recus = ResolutionCibles::resoudre($charges, $cibles, $dossiers->count());
            $nonAffectes = $dossiers->count() - array_sum($recus);
        } else {
            $attribution = RepartitionEquilibree::repartir($charges, $dossiers->pluck('id')->all());
            $recus = array_count_values($attribution);
            $nonAffectes = 0;
        }

        $dejaAffectes = $this->requeteCorrespondants($criteres)->whereNotNull('candidature.evaluateur_id')->count();
        $nonEligibles = $this->requeteCorrespondants($criteres)
            ->whereNull('candidature.evaluateur_id')
            ->where('candidature.statut_eligibilite_interne', 'non_eligible')
            ->count();

        return new PlanAffectation(
            nombreCorrespondants: $n,
            nombreAffectables: $dossiers->count(),
            ignores: [
                'deja_affectes' => $dejaAffectes,
                'non_eligibles' => $nonEligibles,
                'statut_non_affectable' => $n - $dossiers->count() - $dejaAffectes - $nonEligibles,
            ],
            evaluateurs: $evaluateurs->map(fn (MembreEquipe $e) => [
                'id' => $e->id,
                'prenom' => $e->prenom,
                'nom' => $e->nom,
                'charge_actuelle' => $charges[$e->id],
                'a_recevoir' => $recus[$e->id] ?? 0,
            ])->values()->all(),
            nonAffectes: $nonAffectes,
        );
    }

    /**
     * @param  Collection<int, MembreEquipe>  $evaluateurs
     * @param  Collection<int, Candidature>  $dossiers
     * @param  array<string, string>  $attribution  id dossier => id évaluateur ; un dossier absent = resté non affecté (Lot 3, ADR-48)
     * @param  array<string, int>|null  $cibles  id évaluateur => cible demandée, uniquement pour enrichir la ligne globale (Lot 3)
     */
    private function journaliser(User $auteur, Collection $evaluateurs, Collection $dossiers, array $attribution, ?array $cibles, int $nonAffectes): void
    {
        $noms = $evaluateurs->mapWithKeys(fn (MembreEquipe $e) => [$e->id => $e->prenom.' '.$e->nom]);
        $nbEvaluateurs = count(array_unique($attribution));

        $nouvelleValeur = $dossiers->count().' dossiers répartis entre '.$nbEvaluateurs.' évaluateurs';

        if ($cibles !== null) {
            // Lot 3 (ADR-48) : la ligne globale garde une trace des cibles
            // demandées par évaluateur et du nombre de dossiers restés non
            // affectés (reliquat volontairement non réparti) — en plus des
            // lignes par dossier ci-dessous, inchangées.
            $recus = array_count_values($attribution);
            $detail = $evaluateurs->map(fn (MembreEquipe $e) => $noms[$e->id].'='.($recus[$e->id] ?? 0))->implode(', ');
            $nouvelleValeur .= ' ; cibles : '.$detail.' ; non affectés : '.$nonAffectes;
        }

        JournalAudit::create([
            'auteur_id' => $auteur->id,
            'role' => $auteur->role,
            'action' => 'Affectation automatique',
            'module' => 'Candidatures',
            'objet' => $dossiers->count().' dossiers',
            'ancienne_valeur' => null,
            'nouvelle_valeur' => $nouvelleValeur,
            'resultat' => 'Succès',
        ]);

        foreach ($dossiers as $dossier) {
            // Lot 3 (ADR-48) : un dossier resté non affecté (reliquat sous
            // cibles) n'a pas de ligne par-dossier — rien à auditer sur un
            // dossier qu'aucune affectation n'a touché.
            if (! array_key_exists($dossier->id, $attribution)) {
                continue;
            }

            JournalAudit::create([
                'auteur_id' => $auteur->id,
                'role' => $auteur->role,
                'action' => 'Affectation évaluateur',
                'module' => 'Candidatures',
                'objet' => $dossier->numero_dossier,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => $noms[$attribution[$dossier->id]],
                'motif' => 'Répartition automatique',
                'resultat' => 'Succès',
            ]);
        }
    }
}
