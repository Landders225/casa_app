<?php

namespace App\Domain\Affectation;

/**
 * Résultat d'un calcul de répartition automatique (ADR-45, ADR-48) — le MÊME
 * objet sert à l'aperçu (rien d'écrit) et au compte rendu d'exécution. Objet
 * immuable. Ne contient que des nombres et l'identité des ÉVALUATEURS (déjà
 * visible de l'administrateur) — jamais une donnée de candidat.
 *
 * `nonAffectes` (Lot 3, ADR-48) : dossiers affectables NON répartis car la
 * somme des cibles explicites (évaluateurs sans cible compris, une fois le
 * reste réparti) n'atteint pas `nombreAffectables` — toujours 0 hors
 * utilisation des cibles (répartition égale classique, ADR-45).
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
        public readonly int $nonAffectes = 0,
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
            'non_affectes' => $this->nonAffectes,
        ];
    }
}
