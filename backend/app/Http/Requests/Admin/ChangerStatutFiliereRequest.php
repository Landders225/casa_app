<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activation / désactivation d'une filière (Lot 6a).
 *
 *   PATCH /api/admin/filieres/{filiere}  { actif: bool }
 *
 * Une filière `actif = false` n'accepte plus de nouvelle candidature (impact
 * `POST /api/candidatures` du Lot 3a → 422) et apparaît `actif: false` sur la
 * route publique `GET /api/filieres`.
 */
class ChangerStatutFiliereRequest extends FormRequest
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
        ];
    }
}
