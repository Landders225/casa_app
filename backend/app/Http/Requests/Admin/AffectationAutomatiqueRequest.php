<?php

namespace App\Http\Requests\Admin;

/**
 * Affectation automatique par critères (ADR-45) — EXÉCUTION.
 *
 *   POST /api/admin/affectations/automatique
 *     { criteres: {…}, evaluateur_ids?: [uuid…], nombre_correspondants, nombre_affectables }
 *
 * `nombre_affectables` = le M montré par l'aperçu : recompté SOUS VERROU par
 * le serveur, tout écart → 409 `liste_modifiee` (garde de cohérence).
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
        ];
    }
}
