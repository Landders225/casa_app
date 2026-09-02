<?php

namespace App\Services;

/**
 * Ce qu'un candidat a le droit de voir de l'état de SA candidature — résultat de
 * `StatutPublicResolver`. Objet immuable.
 *
 *  - `statutPublic`     : brouillon | en_cours_de_traitement | decision_publiee
 *  - `decision`         : null tant qu'aucune publication ; sinon la valeur brute
 *    `retenu` / `liste_attente` / `non_retenu` / `indisponible` (le libellé et le
 *    message générique sont rendus côté client — ADR-03).
 *  - `motifCommunicable`: null (→ message générique fixe côté client) ou le texte
 *    saisi par l'admin. JAMAIS `motif_interne`.
 *
 * Ce VO ne contient NI score, NI rang, NI motif interne — par construction.
 */
final class StatutPublicCandidat
{
    public function __construct(
        public readonly string $statutPublic,
        public readonly ?string $decision = null,
        public readonly ?string $motifCommunicable = null,
    ) {
    }
}
