<?php

namespace App\Http\Requests\Admin;

use App\Services\GestionCompteEquipe;
use Illuminate\Foundation\Http\FormRequest;

/**
 * (Dés)activation d'un compte d'équipe (Lot 11b).
 *
 *   PATCH /api/admin/membres/{utilisateur}  { actif: bool }
 *
 * SEUL `actif` est modifiable par cette route. Le rôle, l'e-mail, un mot de
 * passe ne passent JAMAIS par ici — `prohibited` (422 si présents). Les
 * garde-fous métier (dernier admin actif, auto-désactivation) sont appliqués
 * dans {@see GestionCompteEquipe}.
 */
class ActiverMembreRequest extends FormRequest
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
            'actif' => ['required', 'boolean'],

            'role' => ['prohibited'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'mot_de_passe_hash' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'actif.required' => 'Le statut cible est obligatoire.',
            'role.prohibited' => 'Le rôle ne se modifie pas : il est fixé à la création du compte.',
        ];
    }
}
