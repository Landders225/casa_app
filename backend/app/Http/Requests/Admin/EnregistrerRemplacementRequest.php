<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Remplacement d'un candidat retenu indisponible (Lot 6b) — administrateur strict.
 *
 *   POST /api/admin/remplacements  { candidature_id, motif }
 *
 * `motif` OBLIGATOIRE (désistement, empêchement…). Les préconditions métier
 * (publication existe, décision = retenu) sont vérifiées dans le contrôleur.
 */
class EnregistrerRemplacementRequest extends FormRequest
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
            'candidature_id' => ['required', 'uuid', Rule::exists('candidature', 'id')],
            'motif' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'candidature_id.exists' => 'Candidature inconnue.',
            'motif.required' => 'Un motif est obligatoire pour un remplacement.',
        ];
    }
}
