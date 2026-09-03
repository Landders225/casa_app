<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Élimination manuelle d'une candidature (Lot 6b) — administrateur strict.
 *
 *   POST /api/admin/candidatures/{candidature}/elimination  { motif }
 *
 * `motif` OBLIGATOIRE. Une par une (pas en masse — acte trop conséquent).
 */
class EliminerCandidatureRequest extends FormRequest
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
            'motif' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif.required' => 'Un motif est obligatoire pour une élimination manuelle.',
            'motif.min' => 'Le motif doit être explicite (au moins 3 caractères).',
        ];
    }
}
