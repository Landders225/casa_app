<?php

namespace App\Http\Requests\Admin;

/**
 * Affectation automatique par critères (ADR-45, ADR-48) — EXÉCUTION.
 *
 *   POST /api/admin/affectations/automatique
 *     { criteres: {…}, evaluateur_ids?: [uuid…], nombre_correspondants, nombre_affectables,
 *       cibles?: […], repartition_attendue?: [{evaluateur_id, a_recevoir}…], non_affectes_attendu?: int }
 *
 * `nombre_affectables` = le M montré par l'aperçu : recompté SOUS VERROU par
 * le serveur, tout écart → 409 `liste_modifiee` (garde de cohérence).
 *
 * `repartition_attendue` / `non_affectes_attendu` (Lot 3, ADR-48) : UNIQUEMENT
 * si `cibles` est fourni — l'administrateur renvoie exactement ce que
 * l'aperçu lui a montré (`evaluateurs[].a_recevoir` et `non_affectes`). La
 * répartition étant fonction de la charge ACTUELLE des évaluateurs (laquelle
 * peut changer entre l'aperçu et la confirmation, p. ex. une autre
 * affectation concurrente), le serveur recalcule SOUS VERROU et refuse
 * (409 `liste_modifiee`, même code que l'écart de nombre) si le résultat
 * diffère — jamais une confirmation sur une répartition périmée.
 */
class AffectationAutomatiqueRequest extends ApercuAffectationAutomatiqueRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'nombre_affectables' => ['required', 'integer', 'min:1'],
            'repartition_attendue' => ['required_with:cibles', 'array'],
            'repartition_attendue.*.evaluateur_id' => ['required_with:repartition_attendue', 'uuid'],
            'repartition_attendue.*.a_recevoir' => ['required_with:repartition_attendue', 'integer', 'min:0'],
            'non_affectes_attendu' => ['required_with:cibles', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, int>|null id évaluateur => a_recevoir attendu, ou null si `cibles` absent
     */
    public function repartitionAttendue(): ?array
    {
        $repartition = $this->validated('repartition_attendue');
        if ($repartition === null) {
            return null;
        }

        return collect($repartition)->mapWithKeys(fn (array $r) => [$r['evaluateur_id'] => (int) $r['a_recevoir']])->all();
    }

    public function nonAffectesAttendu(): ?int
    {
        $valeur = $this->validated('non_affectes_attendu');

        return $valeur === null ? null : (int) $valeur;
    }
}
