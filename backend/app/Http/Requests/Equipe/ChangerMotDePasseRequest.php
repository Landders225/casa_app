<?php

namespace App\Http\Requests\Equipe;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Changement de mot de passe — membre d'équipe CONNECTÉ, évaluateur OU admin
 * (Lot 15a). Copie structurelle de `Candidat\ChangerMotDePasseRequest` (Lot 13,
 * ADR-32) — DUPLIQUÉE plutôt que partagée (Étape 1, Q1) : même précédent que
 * `Auth\MotDePasseController` vs `Candidat\MotDePasseController`, zéro risque
 * sur le code Lot 13 déjà livré.
 *
 *   PUT /api/equipe/mot-de-passe
 *   { current_password, password, password_confirmation }
 *
 * `current_password` : preuve de connaissance du mot de passe ACTUEL — JAMAIS
 * de changement sans elle (vérifiée manuellement via `Hash::check`, pas la
 * règle `current_password` de Laravel qui suppose une colonne `password` sur
 * le guard par défaut).
 *
 * `password` : mêmes règles qu'à la création d'un compte équipe (ADR-16), +
 * `different` du mot de passe actuel.
 */
class ChangerMotDePasseRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => [
                'required', 'string', 'confirmed', 'different:current_password',
                Password::min(10)->letters()->numbers()->mixedCase(),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $actuel = $this->input('current_password');
            $hash = $this->user()->mot_de_passe_hash;

            if (is_string($actuel) && $actuel !== '' && ! Hash::check($actuel, $hash)) {
                $validator->errors()->add('current_password', 'Le mot de passe actuel est incorrect.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Le mot de passe actuel est obligatoire.',
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
            'password.different' => "Le nouveau mot de passe doit être différent de l'actuel.",
        ];
    }
}
