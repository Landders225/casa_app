<?php

namespace App\Http\Requests\Candidat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajout / modification d'une expérience professionnelle (métadonnées seules —
 * le justificatif est un upload, Lot 3b). En POST les deux champs sont requis ;
 * en PATCH ils sont optionnels.
 */
class ExperienceRequest extends FormRequest
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
        $requis = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'domaine' => [$requis, 'string', Rule::in(['hotellerie', 'restauration', 'commerce'])],
            'duree_categorie' => [$requis, 'string', Rule::in(['moins_6', '6_12', 'plus_12'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domaine.in' => 'Domaine non autorisé (hôtellerie, restauration ou commerce).',
            'duree_categorie.in' => 'Durée non autorisée (moins_6, 6_12 ou plus_12).',
        ];
    }
}
