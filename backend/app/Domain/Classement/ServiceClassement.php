<?php

namespace App\Domain\Classement;

use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\ReponseFormulaire;

/**
 * Calcul du CLASSEMENT par filière et attribution des DÉCISIONS internes —
 * codé et versionné par le déploiement (comme ServiceScoring / ServiceEligibilite),
 * PAS un moteur de règles générique.
 *
 * Portage FIDÈLE de App_maquette/assets/js/scoring.js
 * (`computeScoreFinal` + `rankCandidatsParFiliere`) et de `classement.html`
 * (`computeRanking` : quota → retenu / liste d'attente / non retenu) :
 *  - les QUOTAS sont lus EN BASE (`campagne_filiere.quota`) ;
 *  - le SCORE FINAL est la somme des SNAPSHOTS FIGÉS (`evaluation_dossier.score_total`
 *    + `entretien.score_total`, ADR-04), plafonné à 100, arrondi 1 décimale —
 *    PAS un recalcul des volets ;
 *  - le tri applique les critères de départage dans l'ordre EXACT de scoring.js,
 *    complété d'un départage final déterministe (D-5a-1).
 *
 * Constantes algorithmiques (dans le code, comme `moisApprox` / `pointsParEtoile`) :
 *  - `TAILLE_LISTE_ATTENTE = 8` (`classement.html` : `i < quota + 8`) — point
 *    ouvert : pourrait devenir un attribut de `campagne_filiere` si un ajustement
 *    par campagne se confirme ;
 *  - `DOMAINES_SECTEUR` (hôtellerie-restauration) pour le 4e critère de départage.
 */
final class ServiceClassement
{
    /** Version de l'algorithme (change avec le code, jamais en base). */
    public const VERSION = 1;

    /** Taille de la liste d'attente par filière (classement.html). */
    public const TAILLE_LISTE_ATTENTE = 8;

    /** Secteur pris en compte pour le 4e critère de départage (scoring.js). */
    private const DOMAINES_SECTEUR = ['hotellerie', 'restauration'];

    public function calculer(Campagne $campagne): ResultatClassement
    {
        $campagne->loadMissing('filieres');
        $quotaParFiliere = $campagne->filieres->mapWithKeys(
            fn ($f) => [$f->id => (int) $f->pivot->quota],
        );
        $filieresParId = $campagne->filieres->keyBy('id');

        // Candidatures ÉVALUÉES de la campagne (dossier verrouillé + entretien validé),
        // quelle que soit l'éligibilité (les non-éligibles reçoivent une décision
        // explicite, D-5a-4).
        $evaluees = Candidature::query()
            ->where('campagne_id', $campagne->id)
            ->where('dossier_verrouille', true)
            ->whereHas('entretien', fn ($q) => $q->where('statut', 'valide'))
            ->with(['candidat', 'reponseFormulaire', 'experiences', 'evaluationDossier', 'entretien', 'filiere'])
            ->get();

        $lignes = [];

        foreach ($evaluees->groupBy('filiere_id') as $filiereId => $groupe) {
            $filiere = $filieresParId[$filiereId] ?? $groupe->first()->filiere;
            $quota = (int) ($quotaParFiliere[$filiereId] ?? 0);

            [$classables, $nonEligibles] = $groupe->partition(
                fn (Candidature $c) => $c->statut_eligibilite_interne !== 'non_eligible',
            );

            $tries = $classables
                ->map(fn (Candidature $c) => [
                    'candidature' => $c,
                    'score_final' => $this->scoreFinal($c),
                    'departage' => $this->departage($c),
                ])
                ->sort(fn ($a, $b) => $this->comparer($a, $b))
                ->values();

            $rang = 0;
            foreach ($tries as $item) {
                $rang++;
                $lignes[] = new LigneClassement(
                    candidatureId: $item['candidature']->id,
                    filiereCode: $filiere->code,
                    rang: $rang,
                    decision: $this->decision($rang, $quota),
                    scoreFinal: $item['score_final'],
                    scoreDossier: (float) $item['candidature']->evaluationDossier->score_total,
                    scoreEntretien: (float) $item['candidature']->entretien->score_total,
                    departage: $item['departage'],
                    nonEligible: false,
                );
            }

            foreach ($nonEligibles as $candidature) {
                $lignes[] = new LigneClassement(
                    candidatureId: $candidature->id,
                    filiereCode: $filiere->code,
                    rang: null,
                    decision: 'non_retenu',
                    scoreFinal: $this->scoreFinal($candidature),
                    scoreDossier: (float) $candidature->evaluationDossier->score_total,
                    scoreEntretien: (float) $candidature->entretien->score_total,
                    departage: $this->departage($candidature),
                    nonEligible: true,
                );
            }
        }

        $filieres = $campagne->filieres->map(fn ($f) => [
            'code' => $f->code,
            'nom' => $f->nom,
            'quota' => (int) ($quotaParFiliere[$f->id] ?? 0),
        ])->values()->all();

        return new ResultatClassement($lignes, $filieres);
    }

    /**
     * Score final /100 = somme des snapshots figés, plafond 100, arrondi 1 décimale
     * (`computeScoreFinal.total` de scoring.js — mais sur le figé, pas un recalcul).
     */
    public function scoreFinal(Candidature $candidature): float
    {
        $somme = (float) $candidature->evaluationDossier->score_total
            + (float) $candidature->entretien->score_total;

        return round(min($somme, 100.0) * 10) / 10;
    }

    /**
     * Attributs de départage à égalité de score final (scoring.js).
     *
     * @return array{mixite_f: bool, vulnerabilite: int, experience_secteur: bool, mo04: int}
     */
    public function departage(Candidature $candidature): array
    {
        $reponse = $candidature->reponseFormulaire;

        return [
            'mixite_f' => $candidature->candidat?->sexe === 'F',
            'vulnerabilite' => $this->vulnerabilite($reponse),
            'experience_secteur' => $candidature->experiences->contains(
                fn ($e) => in_array($e->domaine, self::DOMAINES_SECTEUR, true),
            ),
            'mo04' => (int) ($reponse?->mo04_note_etoiles ?? 0),
        ];
    }

    /**
     * Comparateur de tri (priorité décroissante), ordre EXACT de scoring.js :
     * score final > mixité (F) > vulnérabilité > expérience secteur > motivation,
     * puis départage final déterministe (D-5a-1) : date_soumission croissant, puis id.
     *
     * @param  array{candidature: Candidature, score_final: float, departage: array<string, mixed>}  $a
     * @param  array{candidature: Candidature, score_final: float, departage: array<string, mixed>}  $b
     */
    private function comparer(array $a, array $b): int
    {
        return ($b['score_final'] <=> $a['score_final'])
            ?: (($b['departage']['mixite_f'] ? 1 : 0) <=> ($a['departage']['mixite_f'] ? 1 : 0))
            ?: ($b['departage']['vulnerabilite'] <=> $a['departage']['vulnerabilite'])
            ?: (($b['departage']['experience_secteur'] ? 1 : 0) <=> ($a['departage']['experience_secteur'] ? 1 : 0))
            ?: ($b['departage']['mo04'] <=> $a['departage']['mo04'])
            ?: (($a['candidature']->date_soumission?->getTimestamp() ?? 0) <=> ($b['candidature']->date_soumission?->getTimestamp() ?? 0))
            ?: strcmp($a['candidature']->id, $b['candidature']->id);
    }

    private function decision(int $rang, int $quota): string
    {
        return match (true) {
            $rang <= $quota => 'retenu',
            $rang <= $quota + self::TAILLE_LISTE_ATTENTE => 'liste_attente',
            default => 'non_retenu',
        };
    }

    /**
     * `vulnerabiliteScore` de scoring.js (§4.10) — départage uniquement.
     */
    private function vulnerabilite(?ReponseFormulaire $reponse): int
    {
        if ($reponse === null) {
            return 0;
        }

        $v = 0;
        if ($reponse->se02_orphelin === 'oui') {
            $v += 2;
        }
        if ($reponse->se06_soutien_menage === 'oui') {
            $v += 1;
        }
        if ($reponse->se05_personnes_a_charge === '3+') {
            $v += 2;
        } elseif ($reponse->se05_personnes_a_charge === '1-2') {
            $v += 1;
        }

        return $v;
    }
}
