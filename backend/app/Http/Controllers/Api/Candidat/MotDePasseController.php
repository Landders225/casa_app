<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Candidat\ChangerMotDePasseRequest;
use App\Models\JournalAudit;
use App\Notifications\MotDePasseModifie;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Changement de mot de passe — candidat CONNECTÉ (Lot 13, ADR-32).
 *
 *   PUT /api/candidat/mot-de-passe   { current_password, password, password_confirmation }
 *
 * `ChangerMotDePasseRequest` exige la preuve du mot de passe ACTUEL — jamais
 * de changement sans elle. À la différence du reset « oublié » (self-service,
 * `auteur_id` = l'utilisateur lui-même de toute façon ici aussi — c'est TOUJOURS
 * l'intéressé qui agit sur son propre compte), on régénère la session courante
 * ET on invalide TOUTES les autres (un changement de mot de passe sert souvent
 * à couper un accès qu'on ne contrôle plus).
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
