<?php

namespace App\Services;

use App\Models\Candidature;
use App\Models\DecisionCandidature;
use App\Models\Publication;

/**
 * Dérivation serveur de ce qu'un candidat voit de SA candidature (ADR-03,
 * séquence (c) de docs/uml-sequences.md).
 *
 * SEUL composant autorisé à lire `candidature.statut_interne` ET
 * `decision_candidature` pour construire une réponse destinée à un rôle candidat.
 * Aucun contrôleur candidat ne doit interroger ces colonnes directement.
 *
 * Table de vérité :
 *
 * | statut_interne | publication ? | decision_candidature ? | statut_public          | decision   | motif_communicable |
 * |----------------|---------------|------------------------|------------------------|------------|--------------------|
 * | brouillon      | —             | —                      | brouillon              | null       | null               |
 * | autre          | non           | (ignorée)              | en_cours_de_traitement | null       | null               |
 * | autre          | oui           | oui                    | decision_publiee       | .decision  | .motif_communicable|
 * | autre          | oui           | non (jamais classé)    | decision_publiee       | non_retenu | null               |
 *
 * ⚠️ La branche « publication existe » ne SELECT que `decision` et
 * `motif_communicable`. `rang` et `motif_interne` (🔴) ne sont jamais chargés.
 * Un candidat `non_eligible` en interne voit EXACTEMENT un `non_retenu`
 * générique — il n'apprend jamais que la cause était l'inéligibilité
 * (D-5b-1 : divergence assumée avec `ma-candidature.html`, la règle reine prime).
 */
class StatutPublicResolver
{
    public function resoudre(Candidature $candidature): StatutPublicCandidat
    {
        if ($candidature->statut_interne === 'brouillon') {
            return new StatutPublicCandidat('brouillon');
        }

        $publie = Publication::query()
            ->where('campagne_id', $candidature->campagne_id)
            ->exists();

        if (! $publie) {
            // soumis / en_instruction / non_eligible / evalue, sans publication :
            // statut neutre, quelle que soit la valeur interne réelle (ADR-03).
            return new StatutPublicCandidat('en_cours_de_traitement');
        }

        // Publication existe : on révèle la décision — et RIEN d'autre.
        // SELECT explicitement limité : `rang` et `motif_interne` (🔴) exclus.
        $decision = DecisionCandidature::query()
            ->where('candidature_id', $candidature->id)
            ->first(['decision', 'motif_communicable']);

        return new StatutPublicCandidat(
            statutPublic: 'decision_publiee',
            // Aucune ligne = candidature jamais classée (soumise sans entretien,
            // ou éliminée à la soumission) → non retenue, message générique.
            decision: $decision?->decision ?? 'non_retenu',
            motifCommunicable: $decision?->motif_communicable,
        );
    }
}
