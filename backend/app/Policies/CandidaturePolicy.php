<?php

namespace App\Policies;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un candidat n'accède JAMAIS à la candidature d'un autre.
 *
 * Refus = 404 (et non 403) : un 403 confirmerait que l'identifiant existe
 * (énumération). Avec 404 on ne distingue pas « n'existe pas » de « existe mais
 * pas à vous » — même logique que le message de login générique du Lot 2.
 */
class CandidaturePolicy
{
    public function view(User $user, Candidature $candidature): Response
    {
        return $this->estProprietaire($user, $candidature)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Candidature $candidature): Response
    {
        return $this->estProprietaire($user, $candidature)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function estProprietaire(User $user, Candidature $candidature): bool
    {
        return $user->candidat !== null
            && $candidature->candidat_id === $user->candidat->id;
    }
}
