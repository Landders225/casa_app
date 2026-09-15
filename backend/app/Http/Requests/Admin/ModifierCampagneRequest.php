<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Édition des informations générales d'une campagne (Lot 17, D-6a-2) —
 * administrateur strict.
 *
 *   PUT /api/admin/campagnes/{campagne}  { nom, date_ouverture, date_cloture }
 *
 * `statut` n'est PAS ici : les transitions d'état restent `PATCH
 * /admin/campagnes/{id}` (Lot 6a), pas dupliquées avec un garde-fou différent.
 *
 * Le verrou « campagne clôturée » (dates verrouillées, nom éditable — Étape 1
 * Q3) est une règle MÉTIER, pas une règle de FORME : elle est appliquée dans
 * le contrôleur (409), pas ici (une requête mal formée reste un 422 de forme,
 * une requête bien formée mais interdite par l'état de la campagne est un 409).
 */
class ModifierCampagneRequest extends FormRequest
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
            'nom' => ['required', 'string', 'max:150'],
            'date_ouverture' => ['required', 'date'],
            'date_cloture' => ['required', 'date', 'after:date_ouverture'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_cloture.after' => 'La date de clôture doit être postérieure à la date d’ouverture.',
        ];
    }
}
