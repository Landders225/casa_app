<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dépôt candidat refusé parce que la campagne concernée n'est plus `ouverte`
 * (ADR-44). Code applicatif dédié (`campagne_fermee`) — distinct de
 * tout autre 409 (ex. « déjà soumise », « dossier verrouillé ») — pour que le
 * front sache afficher l'écran dédié sans se fier au message en texte libre.
 *
 * Forme UNIQUE, partagée par tous les points de blocage introduits par ADR-44
 * (`EnsureCandidatureModifiable`, `SoumissionController`, `CandidatureController`,
 * `RegisterController`) — seul le message change selon le site.
 */
class CampagneFermeeException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'campagne_fermee',
        ], 409);
    }
}
