<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EnvoyerLienResetRequest;
use App\Http\Requests\Auth\ReinitialiserMotDePasseRequest;
use App\Models\JournalAudit;
use App\Models\User;
use App\Notifications\MotDePasseModifie;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Mot de passe oublié — candidat (ou tout compte) DÉCONNECTÉ (Lot 13, ADR-32).
 * Mécanisme NATIF Laravel (`Password` broker, `password_reset_tokens`) — rien
 * de réinventé.
 *
 *   POST /api/mot-de-passe/oubli           { email }
 *   POST /api/mot-de-passe/reinitialiser   { token, email, password, password_confirmation }
 *
 * ANTI-ÉNUMÉRATION (ADR-32) — le cœur de ce contrôleur :
 *  - `envoyerLien` IGNORE délibérément le retour du broker (compte trouvé /
 *    introuvable / throttlé) → réponse 200 IDENTIQUE dans tous les cas ;
 *  - `reinitialiser` ne distingue jamais « token invalide » de « e-mail
 *    inconnu » → même 422 générique pour les deux.
 * Ne PAS « corriger » ce contrôleur pour renvoyer un message différent selon
 * le cas : ce serait réintroduire l'énumération que ce lot élimine.
 */
class MotDePasseController extends Controller
{
    private const MESSAGE_ENVOI = 'Si un compte existe pour cette adresse, un lien de réinitialisation vient d\'être envoyé.';

    private const MESSAGE_LIEN_INVALIDE = 'Ce lien de réinitialisation est invalide ou a expiré.';

    public function envoyerLien(EnvoyerLienResetRequest $request): JsonResponse
    {
        // Résultat NON INSPECTÉ (cf. docblock de classe). `Password::sendResetLink`
        // envoie une notification `ShouldQueue` (Lot 12a) — le contrôleur ne
        // ralentit donc pas selon que l'e-mail existe ou non (pas d'oracle temporel).
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => self::MESSAGE_ENVOI]);
    }

    public function reinitialiser(ReinitialiserMotDePasseRequest $request): JsonResponse
    {
        $statut = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $motDePasse) {
                DB::transaction(function () use ($user, $motDePasse) {
                    $user->forceFill(['mot_de_passe_hash' => $motDePasse])->save(); // cast 'hashed'

                    // Le compte a pu être compromis (c'est pour ça qu'on réinitialise) :
                    // on coupe TOUTES les sessions existantes, pas seulement une.
                    DB::table('sessions')->where('user_id', $user->id)->delete();

                    JournalAudit::create([
                        'auteur_id' => $user->id, // self-service, pas d'acteur authentifié
                        'role' => $user->role,
                        'action' => 'Réinitialisation du mot de passe (lien oublié)',
                        'module' => 'Compte',
                        'objet' => $user->email,
                        'resultat' => 'Succès',
                    ]);
                });

                // Confirmation — détection de prise de compte (ADR-32, Q4).
                $user->notify(new MotDePasseModifie(now()->format('d/m/Y à H:i')));
            },
        );

        if ($statut !== Password::PASSWORD_RESET) {
            // INVALID_USER et INVALID_TOKEN reçoivent le MÊME message (anti-énumération).
            return response()->json(['message' => self::MESSAGE_LIEN_INVALIDE], 422);
        }

        return response()->json(['message' => 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.']);
    }
}
