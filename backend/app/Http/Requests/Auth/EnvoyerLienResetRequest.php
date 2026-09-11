<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Demande de réinitialisation de mot de passe (Lot 13, ADR-32) — PUBLIC.
 *
 *   POST /api/mot-de-passe/oubli   { email }
 *
 * Volontairement PAS de règle `exists:utilisateur,email` : ce serait
 * l'énumération la plus simple qui soit (un 422 « e-mail inconnu » suffirait à
 * distinguer un compte existant d'un compte inexistant). Le format de l'e-mail
 * est la seule chose validée ici ; l'existence n'est jamais confirmée ni
 * infirmée (cf. `MotDePasseController::envoyerLien`).
 */
class EnvoyerLienResetRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => "L'adresse e-mail est obligatoire.",
            'email.email' => "L'adresse e-mail n'est pas valide.",
        ];
    }
}
