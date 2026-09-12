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
 * Verrouillage IDENTITÉ post-soumission (Lot 15b) : dès que la candidature la
 * plus récente a `date_soumission` non nul, `prenom` / `nom` / `sexe` /
 * `date_naissance` / `cni` basculent eux aussi en `prohibited` — ce sont
 * exactement les champs « identité stable » que l'évaluateur a déjà vérifiés
 * sur pièce (ADR-07) ; l'évaluateur lit `candidature.candidat` EN DIRECT (pas
 * un snapshot), donc les corriger après coup changerait ce qu'il a déjà vu.
 * `telephone` / `ville_residence` restent éditables dans tous les cas
 * (coordonnées pratiques, sans impact sur le dossier déjà transmis). Signal
 * choisi : `date_soumission`, pas `statut_interne` — c'est déjà exactement ce
 * que lit le frontend (`Profil.jsx`, `dejaSoumis`), pas une liste de statuts à
 * maintenir en double.
 *
 * Si `date_naissance` est fournie (donc seulement AVANT soumission), le
 * contrôleur relance ServiceEligibiliteInitiale (une correction qui ferait
 * sortir de la tranche 18-30 est refusée en 422).
 */
class MettreAJourProfilRequest extends FormRequest
{
    private const CHAMPS_IDENTITE = ['prenom', 'nom', 'sexe', 'date_naissance', 'cni'];

    private ?bool $identiteVerrouilleeCache = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $verrouille = $this->identiteVerrouillee();

        return [
            'prenom' => $verrouille ? ['prohibited'] : ['sometimes', 'required', 'string', 'max:100'],
            'nom' => $verrouille ? ['prohibited'] : ['sometimes', 'required', 'string', 'max:100'],
            'sexe' => $verrouille ? ['prohibited'] : ['sometimes', 'required', Rule::in(['F', 'H'])],
            'date_naissance' => $verrouille ? ['prohibited'] : ['sometimes', 'required', 'date', 'before:today'],
            'cni' => $verrouille ? ['prohibited'] : ['sometimes', 'required', 'string', 'max:50'],
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
        $messages = [
            'email.prohibited' => "L'adresse e-mail ne se modifie pas ici.",
            'residence_ci.prohibited' => "Le lieu de résidence est fixé à l'inscription et ne peut pas être modifié en libre-service.",
            'nationalite.prohibited' => 'La nationalité est vérifiée sur la CNI par un évaluateur (ADR-07).',
            'diplome.prohibited' => 'Le diplôme est vérifié sur pièce par un évaluateur (ADR-07).',
            'date_naissance.before' => 'La date de naissance doit être dans le passé.',
        ];

        if ($this->identiteVerrouillee()) {
            foreach (self::CHAMPS_IDENTITE as $champ) {
                $messages["{$champ}.prohibited"] = 'Votre dossier est déjà transmis à l\'évaluateur : ce champ ne se '
                    ."modifie plus en libre-service. Contactez l'équipe si une correction est nécessaire.";
            }
        }

        return $messages;
    }

    /**
     * `true` dès que la candidature la plus récente du candidat a été soumise
     * (`date_soumission` non nul) — mémoïsé, `rules()` et `messages()` y font
     * chacun appel.
     */
    private function identiteVerrouillee(): bool
    {
        return $this->identiteVerrouilleeCache ??= (function (): bool {
            $candidat = $this->user()?->candidat;
            if ($candidat === null) {
                return false;
            }

            return $candidat->candidatures()->latest()->first()?->date_soumission !== null;
        })();
    }
}
