<?php

namespace App\Http\Requests\Evaluateur;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Brouillon d'évaluation du dossier (Lot 4b) : les DEUX seules saisies de
 * l'évaluateur qui alimentent le volet Dossier —
 *  - `mo04_note_etoiles` (0..5, note de la lettre de motivation, × 3 pts) ;
 *  - `commentaire_evaluateur` (qualitatif, non noté, 🔴).
 *
 * L'évaluateur ne réajuste PAS les réponses candidat (D-4b-2) : corriger une
 * déclaration passe par la correction exceptionnelle admin (tracée), pas par la
 * notation normale.
 */
class EnregistrerEvaluationRequest extends FormRequest
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
            'mo04_note_etoiles' => ['sometimes', 'nullable', 'integer', 'between:0,5'],
            'commentaire_evaluateur' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mo04_note_etoiles.between' => 'La note de motivation doit être comprise entre 0 et 5 étoiles.',
            'mo04_note_etoiles.integer' => 'La note de motivation doit être un nombre entier d’étoiles.',
        ];
    }
}
