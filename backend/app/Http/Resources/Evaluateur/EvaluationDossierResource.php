<?php

namespace App\Http\Resources\Evaluateur;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Score du volet Dossier (/65) vu par l'ÉVALUATEUR (Lot 4b).
 *
 * ⚠️ 🔴 STRICT — aucun de ces champs (score total, détail par rubrique,
 * commentaire évaluateur, état de verrouillage) ne doit JAMAIS atteindre le
 * rôle candidat. Resource DISTINCTE, jamais réutilisée côté candidat. Le test
 * `NonFuiteVersCandidatTest` le prouve sur le vrai chemin.
 *
 * La ressource enveloppe un tableau assemblé par `EvaluationController` :
 *  - `source = "apercu"`   → recalcul à la volée sur la grille active (dossier
 *    non verrouillé), rien n'est persisté ;
 *  - `source = "snapshot"` → valeurs figées à la validation + `grille_id` de la
 *    grille alors active. JAMAIS recalculé, même si la grille change ensuite
 *    (ADR-04).
 *
 * @property array<string, mixed> $resource
 */
class EvaluationDossierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
