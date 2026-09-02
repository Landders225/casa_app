<?php

namespace App\Http\Resources\Evaluateur;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * État de l'entretien vu par l'ÉVALUATEUR (Lot 4c).
 *
 * ⚠️ 🔴 STRICT — score entretien, sous-notes, présence, observation, état de
 * verrouillage : JAMAIS renvoyés au rôle candidat. Resource DISTINCTE, jamais
 * réutilisée côté candidat (`NonFuiteVersCandidatTest` le prouve sur le vrai
 * chemin, D-3b-7).
 *
 * Enveloppe un tableau assemblé par `EntretienController` :
 *  - `entretien = null`        → pas encore planifié ;
 *  - `source = "apercu"`       → recalcul à la volée depuis `note_sous_critere_entretien`
 *    sur la grille active (statut planifie/realise), rien de figé ;
 *  - `source = "snapshot"`     → `entretien.score_total` + 12 sous-notes + `grille_id`
 *    figés à la validation, JAMAIS recalculés même si la grille change (ADR-04).
 *
 * ⚠️ Contrat : dans `sous_notes`, un sous-critère non renseigné apparaît à
 * `points = 0` (et non « non évalué ») — fidèle à `scoring.js`.
 *
 * @property array<string, mixed> $resource
 */
class EntretienResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
