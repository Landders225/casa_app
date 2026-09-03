<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction exceptionnelle du volet ENTRETIEN (Lot 6b) — administrateur strict.
 *
 *   POST /api/admin/candidatures/{candidature}/correction/entretien
 *   { motif: "...", notes: { "PRES.01": 3, ... }, observation: "..." }
 *
 * `motif` OBLIGATOIRE. Les points sont bornés au max du sous-critère (contrôle
 * dans le contrôleur, contre la grille active).
 */
class CorrigerEntretienRequest extends FormRequest
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
            'notes' => ['sometimes', 'array'],
            'notes.*' => ['numeric', 'min:0', 'decimal:0,1'],
            'observation' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif.required' => 'Un motif est obligatoire pour une correction exceptionnelle.',
            'motif.min' => 'Le motif doit être explicite (au moins 3 caractères).',
        ];
    }
}
