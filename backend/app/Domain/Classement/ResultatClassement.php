<?php

namespace App\Domain\Classement;

/**
 * Résultat complet du calcul du classement d'une campagne (toutes filières).
 * Objet immuable.
 */
final class ResultatClassement
{
    /**
     * @param  list<LigneClassement>  $lignes
     * @param  list<array{code: string, nom: string, quota: int}>  $filieres
     */
    public function __construct(
        public readonly array $lignes,
        public readonly array $filieres,
    ) {
    }

    /**
     * @return list<string>
     */
    public function candidatureIds(): array
    {
        return array_map(fn (LigneClassement $l) => $l->candidatureId, $this->lignes);
    }
}
