<?php

namespace App\Http\Requests\Evaluateur;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saisie / mise à jour de la vérification du dossier par l'évaluateur.
 * Les deux champs sont facultatifs (vérification progressive) et acceptent
 * `null` (remise à « en attente »).
 */
class VerifierDossierRequest extends FormRequest
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
            'nationalite_confirmee' => ['sometimes', 'nullable', 'boolean'],
            'diplome_verifie' => ['sometimes', 'nullable', 'string', Rule::in(['cepe', 'cap', 'bepc', 'bac', 'bt_bep'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'diplome_verifie.in' => 'Diplôme non reconnu (cepe, cap, bepc, bac ou bt_bep).',
            'nationalite_confirmee.boolean' => 'La confirmation de nationalité doit être vraie, fausse ou nulle.',
        ];
    }

    /**
     * Sous-ensemble des clés effectivement fournies (pour un merge partiel).
     *
     * @return array<string, mixed>
     */
    public function champsVerification(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['nationalite_confirmee', 'diplome_verifie']),
        );
    }
}
