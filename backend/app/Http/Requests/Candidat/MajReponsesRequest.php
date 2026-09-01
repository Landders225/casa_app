<?php

namespace App\Http\Requests\Candidat;

use App\Domain\Candidature\ChampsFormulaire;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH partiel de `reponse_formulaire`. Contrat : objet JSON plat, sous-ensemble
 * des champs autorisés. Seules les clés présentes sont écrites ; un `null`
 * explicite efface le champ. Aucun ordre d'étape imposé.
 */
class MajReponsesRequest extends FormRequest
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
        return ChampsFormulaire::reglesReponses();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ChampsFormulaire::messages();
    }

    /**
     * Données validées restreintes aux seules colonnes déclaratives présentes
     * dans la requête (défense en profondeur : rien d'autre n'est jamais écrit).
     *
     * @return array<string, mixed>
     */
    public function donneesReponses(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(ChampsFormulaire::champsAutorises()),
        );
    }
}
