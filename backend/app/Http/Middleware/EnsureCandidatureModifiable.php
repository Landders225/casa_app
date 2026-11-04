<?php

namespace App\Http\Middleware;

use App\Exceptions\CampagneFermeeException;
use App\Models\Candidature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme l'édition d'une candidature (endpoints 3a/3b) dès qu'elle n'est plus en
 * brouillon, OU dès que sa campagne n'est plus ouverte (ADR-44).
 *
 * Ordre : propriété D'ABORD (404 — pas d'énumération d'id, cohérent avec
 * l'isolation 3a), puis état candidature (409), puis état campagne (409). Un
 * non-propriétaire n'apprend donc jamais si la candidature est soumise, ni si
 * sa campagne est close.
 *
 * Le 409 « déjà soumise » est IDENTIQUE que le résultat d'éligibilité interne
 * soit `soumis` ou `non_eligible` (règle reine du Lot 3c) : le candidat sait
 * seulement qu'il a soumis, jamais s'il est éligible.
 */
class EnsureCandidatureModifiable
{
    public function handle(Request $request, Closure $next): Response
    {
        $parametre = $request->route('candidature');
        $candidature = $parametre instanceof Candidature
            ? $parametre
            : Candidature::query()->whereKey($parametre)->first();

        $user = $request->user();

        $proprietaire = $candidature instanceof Candidature
            && $user?->candidat !== null
            && $candidature->candidat_id === $user->candidat->id;

        abort_unless($proprietaire, 404);

        abort_if(
            ! $candidature->estBrouillon(),
            409,
            "Le dossier a été soumis : il n'est plus modifiable.",
        );

        if ($candidature->campagneFermee()) {
            throw new CampagneFermeeException(
                'Les candidatures sont closes : vos modifications n\'ont pas pu être enregistrées.',
            );
        }

        return $next($request);
    }
}
