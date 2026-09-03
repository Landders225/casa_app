<?php

namespace App\Http\Requests\Candidat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mise à jour du profil candidat (Lot 7) — propriétaire uniquement.
 *
 *   PATCH /api/candidat/profil
 *   { prenom?, nom?, sexe?, date_naissance?, cni?, telephone?, ville_residence? }
 *
 * PATCH partiel : tous les champs sont `sometimes`.
 *
 * NON éditables ici (par conception) :
 *  - `email` : c'est un identifiant de connexion — changement = flux dédié avec
 *    ré-authentification (point ouvert, D-7-2) ;
 *  - `residence_ci` : fixé et contrôlé à l'inscription (Q2b) — un changement réel
 *    de résidence relève d'une démarche administrative, pas d'un toggle ;
 *  - nationalité / diplôme : n'existent pas côté candidat (ADR-07).
 * Ces champs sont `prohibited` : les envoyer renvoie un 422 explicite.
 *
 * Si `date_naissance` est fournie, le contrôleur relance
 * ServiceEligibiliteInitiale (une correction qui ferait sortir de la tranche
 * 18-30 est refusée en 422).
 */
class MettreAJourProfilRequest extends FormRequest
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
            'prenom' => ['sometimes', 'required', 'string', 'max:100'],
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'sexe' => ['sometimes', 'required', Rule::in(['F', 'H'])],
            'date_naissance' => ['sometimes', 'required', 'date', 'before:today'],
            'cni' => ['sometimes', 'required', 'string', 'max:50'],
            'telephone' => ['sometimes', 'required', 'string', 'max:20'],
            'ville_residence' => ['sometimes', 'required', 'string', 'max:100'],

            'email' => ['prohibited'],
            'residence_ci' => ['prohibited'],
            'nationalite' => ['prohibited'],
            'nationalite_confirmee' => ['prohibited'],
            'diplome' => ['prohibited'],
            'diplome_verifie' => ['prohibited'],
            'sc04' => ['prohibited'],
            'sc04_plus_haut_diplome' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.prohibited' => "L'adresse e-mail ne se modifie pas ici.",
            'residence_ci.prohibited' => "Le lieu de résidence est fixé à l'inscription et ne peut pas être modifié en libre-service.",
            'nationalite.prohibited' => 'La nationalité est vérifiée sur la CNI par un évaluateur (ADR-07).',
            'diplome.prohibited' => 'Le diplôme est vérifié sur pièce par un évaluateur (ADR-07).',
            'date_naissance.before' => 'La date de naissance doit être dans le passé.',
        ];
    }
}
