<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Api\Admin\ReattributionController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Réattribution d'un ou plusieurs dossiers DÉJÀ AFFECTÉS, vers un autre
 * évaluateur OU vers le lot non affecté (Lot 20b).
 *
 *   POST /api/admin/reattributions  { evaluateur_id: uuid|null, candidature_ids: [1..n] }
 *
 * `evaluateur_id` NULLABLE (contrairement à `AffecterEvaluateurRequest`) :
 * `null` = remettre dans le lot non affecté — refusé par le contrôleur si un
 * seul dossier du lot a son volet /65 déjà verrouillé (seule une
 * réattribution VERS UN AUTRE évaluateur reste possible dans ce cas).
 */
class ReattribuerEvaluateurRequest extends FormRequest
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
            'evaluateur_id' => ['nullable', 'uuid', Rule::exists('membre_equipe', 'id')],
            'candidature_ids' => ['required', 'array', 'min:1', 'max:'.ReattributionController::PLAFOND_DOSSIERS],
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
            'candidature_ids.max' => 'Au-delà de '.ReattributionController::PLAFOND_DOSSIERS.' dossiers par opération, affinez votre sélection.',
        ];
    }
}
