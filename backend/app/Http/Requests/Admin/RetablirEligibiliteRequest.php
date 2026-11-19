<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Api\Admin\RetablissementEligibiliteController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rétablissement d'éligibilité (Lot 4, ADR-49) — administrateur strict.
 *
 *   POST /api/admin/candidatures/eligibilite/retablir  { candidature_ids: [1..n], motif }
 *
 * Même plafond/forme que `ReattribuerEvaluateurRequest` (`candidature_ids`
 * bornés, `uuid`+`distinct`+`exists`) ; `motif` obligatoire, même règle que
 * `EliminerCandidatureRequest` (min 3 caractères — un acte de cette nature ne
 * se justifie jamais en une poignée de mots creux).
 */
class RetablirEligibiliteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'candidature_ids' => ['required', 'array', 'min:1', 'max:'.RetablissementEligibiliteController::PLAFOND_DOSSIERS],
            'candidature_ids.*' => ['uuid', 'distinct', 'exists:candidature,id'],
            'motif' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'candidature_ids.*.exists' => 'Une des candidatures est inconnue.',
            'candidature_ids.max' => 'Au-delà de '.RetablissementEligibiliteController::PLAFOND_DOSSIERS.' dossiers par opération, affinez votre sélection.',
            'motif.required' => 'Un motif est obligatoire pour un rétablissement d\'éligibilité.',
            'motif.min' => 'Le motif doit être explicite (au moins 3 caractères).',
        ];
    }
}
