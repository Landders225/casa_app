<?php

namespace App\Http\Requests\Auth;

use App\Rules\PolitiqueMotDePasse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Réinitialisation effective — candidat DÉCONNECTÉ, muni du lien reçu par
 * e-mail (Lot 13, ADR-32).
 *
 *   POST /api/mot-de-passe/reinitialiser
 *   { token, email, password, password_confirmation }
 *
 * `token`/`email` sont validés par `Password::reset()` (côté contrôleur) — pas
 * ici, pour que « token invalide » et « e-mail inconnu » ressortent avec le
 * MÊME message générique (anti-énumération), jamais un 422 de champ distinct.
 */
class ReinitialiserMotDePasseRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PolitiqueMotDePasse::regles()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.uncompromised' => PolitiqueMotDePasse::MESSAGE_COMPROMIS,
        ];
    }
}
