<?php

namespace App\Domain\Affectation;

use App\Exceptions\AffectationAutomatiqueRefusee;

/**
 * Résolution des CIBLES par évaluateur (Lot 3, ADR-48) — fonction PURE
 * (aucune base, aucune horloge), testée seule.
 *
 * « Cible » = nombre de dossiers à recevoir POUR CETTE OPÉRATION (jamais un
 * plafond de charge totale) — facultatif, saisi par évaluateur. Les
 * évaluateurs sans cible explicite se partagent le reste des dossiers
 * affectables, à parts égales, les ÉVALUATEURS ACTUELLEMENT LES MOINS
 * CHARGÉS recevant le(s) dossier(s) supplémentaire(s) de l'arrondi.
 *
 * Règles :
 *  - une cible visant un évaluateur hors de l'ensemble résolu (restriction
 *    `evaluateur_ids`, désactivé…) → refus immédiat, rien n'est affecté ;
 *  - somme des cibles explicites > dossiers affectables → refus immédiat ;
 *  - somme < affectables, des évaluateurs sans cible existent → ils
 *    absorbent le reste (moins chargés d'abord) ;
 *  - somme < affectables, TOUS les évaluateurs ont une cible explicite (y
 *    compris 0) → le reste reste NON AFFECTÉ (pas une erreur).
 *
 * Mêmes entrées → même résultat, toujours (le départage d'égalité suit
 * l'ordre reçu dans `$chargesExistantes` — nom, prénom, id — même convention
 * que `RepartitionEquilibree`).
 */
final class ResolutionCibles
{
    /**
     * @param  array<string, int>  $chargesExistantes  id évaluateur => charge actuelle, DANS L'ORDRE de départage
     * @param  array<string, int>  $cibles  id évaluateur => nombre demandé explicitement (0..n)
     * @return array<string, int> id évaluateur => cible finale, pour TOUS les évaluateurs de $chargesExistantes, DANS LE MÊME ORDRE — somme <= $nombreAffectables
     *
     * @throws AffectationAutomatiqueRefusee cible_evaluateur_invalide | cibles_excessives
     */
    public static function resoudre(array $chargesExistantes, array $cibles, int $nombreAffectables): array
    {
        foreach (array_keys($cibles) as $id) {
            if (! array_key_exists($id, $chargesExistantes)) {
                throw AffectationAutomatiqueRefusee::cibleEvaluateurInvalide();
            }
        }

        $sommeCibles = array_sum($cibles);
        if ($sommeCibles > $nombreAffectables) {
            throw AffectationAutomatiqueRefusee::ciblesExcessives($sommeCibles, $nombreAffectables);
        }

        $sansCible = array_diff_key($chargesExistantes, $cibles);
        $reste = $nombreAffectables - $sommeCibles;

        $finales = $cibles;
        foreach ($sansCible as $id => $charge) {
            $finales[$id] = 0;
        }

        if ($sansCible !== [] && $reste > 0) {
            // Tri stable (PHP >= 8.0) : à charge égale, l'ordre de départage
            // d'origine (déjà celui de $sansCible, issu de $chargesExistantes) est conservé.
            $ordre = array_keys($sansCible);
            usort($ordre, fn ($a, $b) => $sansCible[$a] <=> $sansCible[$b]);

            $n = count($ordre);
            $base = intdiv($reste, $n);
            $extra = $reste % $n;

            foreach ($ordre as $i => $id) {
                $finales[$id] = $base + ($i < $extra ? 1 : 0);
            }
        }

        $resultat = [];
        foreach ($chargesExistantes as $id => $_) {
            $resultat[$id] = $finales[$id];
        }

        return $resultat;
    }
}
