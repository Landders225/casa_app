<?php

namespace App\Policies;

use App\Models\Candidature;
use App\Models\PieceJustificative;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un candidat n'accède qu'aux pièces de SA candidature. Refus = 404 (jamais 403,
 * cohérence avec l'isolation du Lot 3a). La propriété est résolue via
 * `candidature_id` (pièce de dossier) OU via l'expérience rattachée
 * (`experience_professionnelle.piece_justificative_id`).
 */
class PieceJustificativePolicy
{
    public function download(User $user, PieceJustificative $piece): Response
    {
        return $this->estProprietaire($user, $piece)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function estProprietaire(User $user, PieceJustificative $piece): bool
    {
        if ($user->candidat === null) {
            return false;
        }

        $candidatureId = $piece->candidatureIdProprietaire();

        return $candidatureId !== null
            && Candidature::query()
                ->whereKey($candidatureId)
                ->where('candidat_id', $user->candidat->id)
                ->exists();
    }
}
