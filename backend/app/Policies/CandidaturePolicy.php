<?php

namespace App\Policies;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Deux jeux d'accès distincts sur la même candidature :
 *
 *  - CANDIDAT  (view / update) : uniquement le propriétaire. Refus = 404 (jamais
 *    403 : un 403 confirmerait l'existence de l'id — énumération).
 *
 *  - ÉVALUATEUR (voirCommeEvaluateur / verifierCommeEvaluateur, Lot 4a) :
 *    l'évaluateur affecté (`evaluateur_id`), OU tout administrateur (admin ⊇
 *    évaluateur, ADR-10 : accès à TOUS les dossiers). Refus = 404 également.
 */
class CandidaturePolicy
{
    // --- Candidat (propriétaire) ---

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

    // --- Évaluateur / administrateur (Lot 4a) ---

    public function voirCommeEvaluateur(User $user, Candidature $candidature): Response
    {
        return $this->peutInstruire($user, $candidature)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function verifierCommeEvaluateur(User $user, Candidature $candidature): Response
    {
        return $this->peutInstruire($user, $candidature)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function peutInstruire(User $user, Candidature $candidature): bool
    {
        if ($user->isAdministrateur()) {
            return true; // admin ⊇ évaluateur : accès à tous les dossiers
        }

        return $user->isEvaluateur()
            && $user->membreEquipe !== null
            && $candidature->evaluateur_id === $user->membreEquipe->id;
    }
}
