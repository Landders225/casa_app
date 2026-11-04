<?php

namespace App\Domain\Affectation;

/**
 * Résultat d'un calcul de répartition automatique (ADR-45) — le MÊME objet
 * sert à l'aperçu (rien d'écrit) et au compte rendu d'exécution. Objet
 * immuable. Ne contient que des nombres et l'identité des ÉVALUATEURS (déjà
 * visible de l'administrateur) — jamais une donnée de candidat.
 */
final class PlanAffectation
{
    /**
     * @param  array{deja_affectes: int, non_eligibles: int, statut_non_affectable: int}  $ignores
     * @param  list<array{id: string, prenom: string, nom: string, charge_actuelle: int, a_recevoir: int}>  $evaluateurs
     */
    public function __construct(
        public readonly int $nombreCorrespondants,
        public readonly int $nombreAffectables,
        public readonly array $ignores,
        public readonly array $evaluateurs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nombre_correspondants' => $this->nombreCorrespondants,
            'nombre_affectables' => $this->nombreAffectables,
            'ignores' => $this->ignores,
            'evaluateurs' => array_map(fn (array $e) => [
                ...$e,
                'charge_finale' => $e['charge_actuelle'] + $e['a_recevoir'],
            ], $this->evaluateurs),
        ];
    }
}
