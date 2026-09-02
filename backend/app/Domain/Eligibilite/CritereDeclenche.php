<?php

namespace App\Domain\Eligibilite;

/**
 * Un critère éliminatoire effectivement déclenché pour une candidature
 * (miroir de `motifsElimination` de la maquette). Objet immuable.
 */
final class CritereDeclenche
{
    /**
     * @param  string  $code    ex. 'age_min', 'SC.01', 'acces_sites', 'francais'
     * @param  string  $detail  phrase explicative pour l'évaluateur
     * @param  string  $origine 'soumission_candidat' | 'verification_evaluateur'
     */
    public function __construct(
        public readonly string $code,
        public readonly string $detail,
        public readonly string $origine = 'soumission_candidat',
    ) {
    }
}
