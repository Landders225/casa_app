<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
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
 *
 * Implémente `ShouldntReport` — flux métier ATTENDU (un candidat ou un
 * visiteur a simplement tenté un dépôt après clôture), pas une erreur
 * applicative : ne pas journaliser à chaque occurrence, sous peine de noyer
 * les vraies anomalies. ⚠️ `public function report(): bool { return false; }`
 * NE suffit PAS dans ce Handler (`Illuminate\Foundation\Exceptions\Handler::
 * reportThrowable()`, Laravel 11/12) : seul un retour `!== false` y stoppe la
 * journalisation par défaut — `false` y retombe donc, à l'inverse de l'intuition
 * usuelle. `ShouldntReport` est vérifié PLUS TÔT, dans `shouldntReport()`, et
 * empêche `report()` même d'être appelé ; c'est le seul mécanisme fiable ici
 * (prouvé par `ClotureCampagneBlocageTest::test_aucune_trace_de_log_pour_un_409_campagne_fermee`).
 */
class CampagneFermeeException extends Exception implements ShouldntReport
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'campagne_fermee',
        ], 409);
    }
}
