<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transition d'état d'une campagne (Lot 6a) — ouvrir ou clôturer.
 *
 *   PATCH /api/admin/campagnes/{campagne}  { statut: 'ouverte' | 'cloturee' }
 *
 * Transitions autorisées : brouillon → ouverte, ouverte → cloturee. Toute autre
 * → 422. `brouillon → ouverte` refusée (409) si une autre campagne est déjà
 * ouverte (règle « une seule campagne ouverte » du Lot 3a).
 */
class ChangerStatutCampagneRequest extends FormRequest
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
            'statut' => ['required', Rule::in(['ouverte', 'cloturee'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'statut.in' => 'Statut cible invalide : « ouverte » ou « cloturee ».',
        ];
    }
}
