<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une campagne (Lot 17, D-6a-2) — administrateur strict.
 *
 *   POST /api/admin/campagnes
 *   { nom, date_ouverture, date_cloture, filieres: [{ filiere_id, quota }] }
 *
 * `statut` n'est PAS dans les règles : une campagne créée par l'écran est
 * TOUJOURS `brouillon` (forcé dans le contrôleur, jamais lu depuis le body) —
 * le passage à `ouverte` reste exclusivement `PATCH /admin/campagnes/{id}`,
 * qui porte déjà le garde-fou « une seule campagne ouverte » (Lot 6a). Créer
 * une campagne ne peut donc jamais violer ce garde-fou.
 *
 * `filieres` : au moins une, seulement des filières ACTIVES (une filière
 * inactive n'accepte déjà plus de candidature — l'associer à une nouvelle
 * cohorte n'aurait pas de sens), pas de doublon.
 */
class CreerCampagneRequest extends FormRequest
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
            'filieres' => ['required', 'array', 'min:1'],
            'filieres.*.filiere_id' => [
                'required',
                'distinct',
                Rule::exists('filiere', 'id')->where('actif', true),
            ],
            'filieres.*.quota' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_cloture.after' => 'La date de clôture doit être postérieure à la date d’ouverture.',
            'filieres.required' => 'Choisissez au moins une filière pour cette campagne.',
            'filieres.min' => 'Choisissez au moins une filière pour cette campagne.',
            'filieres.*.filiere_id.distinct' => 'Une même filière ne peut apparaître qu’une seule fois.',
            'filieres.*.filiere_id.exists' => 'Filière introuvable ou inactive : seules les filières actives peuvent être rattachées à une nouvelle campagne.',
            'filieres.*.quota.min' => 'Le quota ne peut pas être négatif.',
        ];
    }
}
