<?php

namespace App\Http\Requests\Admin;

use App\Services\ProvisionnementMembreEquipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un compte d'équipe depuis l'écran d'administration (Lot 11b).
 *
 *   POST /api/admin/membres  { email, role, prenom, nom, poste }
 *
 * `role` STRICTEMENT {evaluateur, administrateur} — `candidat` ou toute autre
 * valeur → 422, exactement comme `casa:create-membre`. Aucun mot de passe dans
 * le corps : le serveur le génère (`ProvisionnementMembreEquipe::genererMotDePasse`).
 *
 * Verrous mass-assignment : les champs qui ne doivent JAMAIS transiter par une
 * requête (`actif`, `id`, un hash, un mot de passe imposé, un faux drapeau) sont
 * `prohibited` — présents ⇒ 422, jamais un effet silencieux.
 */
class EnregistrerMembreRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('utilisateur', 'email')],
            'role' => ['required', Rule::in(ProvisionnementMembreEquipe::ROLES)],
            'prenom' => ['required', 'string', 'max:100'],
            'nom' => ['required', 'string', 'max:100'],
            'poste' => ['required', 'string', 'max:100'],

            // Jamais pilotables par le client.
            'actif' => ['prohibited'],
            'id' => ['prohibited'],
            'password' => ['prohibited'],
            'mot_de_passe_hash' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Un compte existe déjà pour cette adresse e-mail.',
            'email.email' => "L'adresse e-mail n'est pas valide.",
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Le rôle doit être « évaluateur » ou « administrateur ».',
            'actif.prohibited' => 'Le statut ne se choisit pas à la création : un compte est actif par défaut.',
            'password.prohibited' => 'Le mot de passe est généré par le serveur : ne pas le fournir.',
            'mot_de_passe_hash.prohibited' => 'Champ interne — non modifiable.',
            'is_admin.prohibited' => 'Le rôle se définit via le champ « role » uniquement.',
        ];
    }
}
