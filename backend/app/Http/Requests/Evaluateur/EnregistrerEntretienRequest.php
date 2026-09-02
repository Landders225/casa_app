<?php

namespace App\Http\Requests\Evaluateur;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Brouillon d'entretien (Lot 4c) — `PUT /entretien`, champs partiels :
 *  - planification minimale : `date`, `heure`, `lieu` (requis à la création) ;
 *  - `presence` (present | absent), `observation` ;
 *  - `notes` : map `code sous-critère` → points (numeric(3,1), 0..max).
 *
 * Le contrôle « points ≤ max du sous-critère » se fait dans le contrôleur,
 * contre la grille ACTIVE (le maximum est une donnée de barème, pas une règle
 * fixe). Un sous-critère non transmis reste tel quel (et, à la validation,
 * compte comme 0 — jamais « non évalué »).
 */
class EnregistrerEntretienRequest extends FormRequest
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
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'heure' => ['sometimes', 'required', 'date_format:H:i,H:i:s'],
            'lieu' => ['sometimes', 'required', Rule::in(['Le Plateau', '2 Plateaux Vallons'])],
            'presence' => ['sometimes', 'nullable', Rule::in(['present', 'absent'])],
            'observation' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'array'],
            'notes.*' => ['numeric', 'min:0', 'decimal:0,1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lieu.in' => 'Lieu inconnu (« Le Plateau » ou « 2 Plateaux Vallons »).',
            'presence.in' => 'La présence doit être « present » ou « absent ».',
            'notes.*.min' => 'Une sous-note ne peut pas être négative.',
            'notes.*.decimal' => 'Une sous-note s’exprime au dixième de point près.',
        ];
    }
}
