<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Inscription d'un candidat (Lot 7, public) — comble ADR-13.
 *
 *   POST /api/register
 *   {
 *     email, password, password_confirmation,
 *     prenom, nom, sexe, date_naissance, cni, telephone, ville_residence,
 *     residence_ci: true, cgu: true
 *   }
 *
 * Crée `utilisateur` (role=candidat) + `candidat`. L'éligibilité initiale
 * (âge 18-30 + résidence CI) est vérifiée dans le contrôleur via
 * ServiceEligibiliteInitiale (422 avec motif si non remplie).
 *
 * ADR-07 : nationalité et diplôme n'existent PAS côté candidat — tout champ de ce
 * type est explicitement `prohibited` (jamais persisté, même par erreur front).
 */
class RegisterRequest extends FormRequest
{
    /** Route publique : aucune autorisation préalable. */
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
            // Longueur d'abord (NIST) ; pas de symbole imposé (Q4a), pas de
            // contrôle HaveIBeenPwned en v1 (Q4b — durcissement futur).
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()->mixedCase()],

            'prenom' => ['required', 'string', 'max:100'],
            'nom' => ['required', 'string', 'max:100'],
            'sexe' => ['required', Rule::in(['F', 'H'])],
            'date_naissance' => ['required', 'date', 'before:today'],
            'cni' => ['required', 'string', 'max:50'],
            'telephone' => ['required', 'string', 'max:20'],
            'ville_residence' => ['required', 'string', 'max:100'],

            // Fixé à l'inscription, JAMAIS modifiable ensuite (Q2b) — un vrai
            // changement de résidence relève d'une démarche administrative.
            'residence_ci' => ['required', 'boolean'],

            // Acte juridique : validé ET horodaté en base (Q2c, cgu_acceptees_le).
            'cgu' => ['required', 'accepted'],

            // ADR-07 : verrou dur.
            'nationalite' => ['prohibited'],
            'nationalite_confirmee' => ['prohibited'],
            'diplome' => ['prohibited'],
            'diplome_verifie' => ['prohibited'],
            'sc04' => ['prohibited'],
            'sc04_plus_haut_diplome' => ['prohibited'],
            // Le rôle ne se choisit pas : /register crée TOUJOURS un candidat.
            'role' => ['prohibited'],
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
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'date_naissance.before' => 'La date de naissance doit être dans le passé.',
            'cgu.accepted' => "Vous devez accepter les conditions d'utilisation pour créer un compte.",
            'residence_ci.required' => 'Le lieu de résidence doit être précisé.',
            'nationalite.prohibited' => 'La nationalité ne peut pas être déclarée par le candidat : elle est vérifiée sur la CNI.',
            'nationalite_confirmee.prohibited' => 'La nationalité ne peut pas être déclarée par le candidat : elle est vérifiée sur la CNI.',
            'diplome.prohibited' => 'Le diplôme ne peut pas être déclaré par le candidat : il est vérifié sur pièce.',
            'diplome_verifie.prohibited' => 'Le diplôme ne peut pas être déclaré par le candidat : il est vérifié sur pièce.',
            'sc04.prohibited' => 'Le diplôme ne peut pas être déclaré par le candidat : il est vérifié sur pièce.',
            'sc04_plus_haut_diplome.prohibited' => 'Le diplôme ne peut pas être déclaré par le candidat : il est vérifié sur pièce.',
            'role.prohibited' => 'Le rôle ne peut pas être choisi : /register crée toujours un compte candidat.',
        ];
    }
}
