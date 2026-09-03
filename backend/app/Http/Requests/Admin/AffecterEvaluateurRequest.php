<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Affectation en masse d'un évaluateur à des candidatures (Lot 6a).
 *
 *   POST /api/admin/affectations  { evaluateur_id, candidature_ids: [1..n] }
 *
 * Atomique : le contrôleur refuse tout le lot (422) si une seule candidature
 * n'est pas dans un état affectable. `evaluateur_id` doit désigner un
 * `membre_equipe` de rôle `evaluateur` (contrôle dans le contrôleur — jointure).
 */
class AffecterEvaluateurRequest extends FormRequest
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
            'evaluateur_id' => ['required', 'uuid', Rule::exists('membre_equipe', 'id')],
            'candidature_ids' => ['required', 'array', 'min:1'],
            'candidature_ids.*' => ['uuid', 'distinct', Rule::exists('candidature', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evaluateur_id.exists' => 'Évaluateur inconnu.',
            'candidature_ids.*.exists' => 'Une des candidatures est inconnue.',
        ];
    }
}
