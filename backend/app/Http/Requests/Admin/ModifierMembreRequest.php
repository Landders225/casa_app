<?php

namespace App\Http\Requests\Admin;

use App\Services\GestionCompteEquipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Gestion d'un compte d'équipe EXISTANT (Lot 11b, étendue au Lot 15a).
 *
 *   PATCH /api/admin/membres/{utilisateur}
 *
 * DEUX actions INDÉPENDANTES, portées par la MÊME route :
 *  - `{ actif: bool }` — (dés)activation (Lot 11b). Garde-fous métier G1/G2
 *    dans {@see GestionCompteEquipe::definirActivation}.
 *  - `{ prenom, nom, poste }` — édition d'identité (Lot 15a), les 3 ENSEMBLE
 *    (`required_with` croisé) — un enregistrement RH que seul l'admin corrige,
 *    jamais le membre lui-même (route `role:administrateur` strict, un
 *    évaluateur n'atteint même pas ce contrôleur).
 *
 * L'UI n'envoie JAMAIS les deux à la fois (Étape 1, Q3) — mais le serveur les
 * accepte indépendamment ou ensemble, et EXIGE qu'au moins un des deux groupes
 * soit présent (sinon 422 : rien à faire).
 *
 * `role` / `email` / `password` / `mot_de_passe_hash` ne passent JAMAIS par ici
 * — `prohibited` (422 si présents), inchangé depuis le Lot 11b.
 */
class ModifierMembreRequest extends FormRequest
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
            // Pas de `sometimes` sur le trio : `sometimes` SAUTE toute la
            // validation d'un champ absent — y compris `required_with`, ce qui
            // viderait la règle « les 3 ensemble » de son effet. `required_with`
            // seul reste implicite (s'évalue même champ absent) et ne rend le
            // champ obligatoire QUE si un des deux autres est présent.
            'actif' => ['sometimes', 'boolean'],
            'prenom' => ['required_with:nom,poste', 'string', 'max:100'],
            'nom' => ['required_with:prenom,poste', 'string', 'max:100'],
            'poste' => ['required_with:prenom,nom', 'string', 'max:100'],

            'role' => ['prohibited'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'mot_de_passe_hash' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny(['actif', 'prenom', 'nom', 'poste'])) {
                $validator->errors()->add('actif', 'Fournissez au moins un statut (« actif ») ou une identité (prénom/nom/poste).');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'actif.required' => 'Le statut cible est obligatoire.',
            'role.prohibited' => 'Le rôle ne se modifie pas : il est fixé à la création du compte.',
            'prenom.required_with' => 'Le prénom, le nom et le poste doivent être envoyés ensemble.',
            'nom.required_with' => 'Le prénom, le nom et le poste doivent être envoyés ensemble.',
            'poste.required_with' => 'Le prénom, le nom et le poste doivent être envoyés ensemble.',
        ];
    }
}
