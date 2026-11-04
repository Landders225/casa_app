<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refus d'une affectation automatique par critères (ADR-45). Code applicatif
 * dédié par garde-fou — le front distingue « la liste a changé, reconfirmez »
 * (`liste_modifiee`) des autres refus sans se fier au texte libre (même
 * patron que `CampagneFermeeException`, ADR-44).
 *
 * Levée DANS la transaction d'exécution : toute écriture déjà faite est
 * annulée. Le message ne contient que des nombres, jamais une identité.
 *
 * `ShouldntReport` : un refus est une réponse métier normale, pas une
 * anomalie — il ne doit JAMAIS atteindre les logs applicatifs (sans cela,
 * Laravel le journalise malgré `render()`).
 */
class AffectationAutomatiqueRefusee extends Exception implements ShouldntReport
{
    public function __construct(
        string $message,
        public readonly string $codeApplicatif,
        public readonly int $statut,
    ) {
        parent::__construct($message);
    }

    public static function listeModifiee(string $message): self
    {
        return new self($message, 'liste_modifiee', 409);
    }

    public static function campagnePubliee(): self
    {
        return new self(
            "Les résultats d'une campagne concernée sont déjà publiés : aucune affectation automatique n'est possible sur ces dossiers.",
            'campagne_publiee',
            409,
        );
    }

    public static function plafondDepasse(int $nombre, int $plafond): self
    {
        return new self(
            "{$nombre} dossiers correspondent à vos critères : au-delà de {$plafond} par opération, affinez vos filtres (campagne, filière…).",
            'plafond_depasse',
            422,
        );
    }

    public static function aucunEvaluateur(): self
    {
        return new self(
            'Aucun évaluateur actif disponible pour la répartition.',
            'aucun_evaluateur',
            422,
        );
    }

    public static function evaluateurInvalide(): self
    {
        return new self(
            "Un des évaluateurs choisis est inconnu, désactivé ou n'a pas le rôle « évaluateur ».",
            'evaluateur_invalide',
            422,
        );
    }

    public static function rienAAffecter(): self
    {
        return new self(
            'Aucun dossier affectable parmi ceux qui correspondent à vos critères (déjà affectés, non éligibles ou non soumis).',
            'rien_a_affecter',
            422,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->codeApplicatif,
        ], $this->statut);
    }
}
