<?php

namespace App\Http\Controllers\Api\Equipe;

use App\Http\Controllers\Controller;
use App\Http\Requests\Equipe\ChangerMotDePasseRequest;
use App\Models\JournalAudit;
use App\Notifications\MotDePasseModifie;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Changement de mot de passe — membre d'équipe CONNECTÉ (évaluateur OU admin,
 * Lot 15a). Self-service : distinct de `Admin\MembreController::reinitialiserMotDePasse`
 * (Lot 11b, un admin réinitialise le mot de passe D'UN AUTRE membre).
 *
 *   PUT /api/equipe/mot-de-passe   { current_password, password, password_confirmation }
 *
 * Copie structurelle de `Candidat\MotDePasseController` (Lot 13) — même
 * comportement : régénère la session courante PUIS purge les AUTRES sessions
 * (l'ordre compte, cf. docstring d'origine), e-mail de confirmation
 * (`MotDePasseModifie`, déjà générique — aucun changement).
 */
class MotDePasseController extends Controller
{
    public function update(ChangerMotDePasseRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['mot_de_passe_hash' => $request->validated('password')])->save(); // cast 'hashed'

        // Nouvel identifiant de session AVANT de purger : la ligne courante n'est
        // pas encore écrite en base sous ce nouvel id (elle le sera en fin de
        // requête) — le DELETE ci-dessous ne peut donc pas se supprimer lui-même,
        // et emporte bien toutes les AUTRES sessions déjà ouvertes.
        $request->session()->regenerate();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        JournalAudit::create([
            'auteur_id' => $user->id,
            'role' => $user->role,
            'action' => 'Changement de mot de passe',
            'module' => 'Compte',
            'objet' => $user->email,
            'resultat' => 'Succès',
        ]);

        $user->notify(new MotDePasseModifie(now()->format('d/m/Y à H:i')));

        return response()->json(['message' => 'Votre mot de passe a été modifié.']);
    }
}
