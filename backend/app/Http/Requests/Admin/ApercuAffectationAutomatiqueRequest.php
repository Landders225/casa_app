<?php

namespace App\Http\Requests\Admin;

use App\Domain\Supervision\FiltreCandidatures;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Affectation automatique par critères (ADR-45, ADR-48) — APERÇU (base de la
 * requête d'exécution, qui y ajoute `nombre_affectables`).
 *
 *   POST /api/admin/affectations/automatique/apercu
 *     { criteres: {…}, evaluateur_ids?: [uuid…], nombre_correspondants, cibles?: [{evaluateur_id, nombre}…] }
 *   POST /api/admin/affectations/automatique
 *     { …idem, nombre_affectables }   (`AffectationAutomatiqueRequest`)
 *
 * `criteres` : les MÊMES filtres que la recherche (`FiltreCandidatures`,
 * source unique), en corps POST (le terme de recherche peut être un nom —
 * jamais dans une URL, ADR-39). `array:<clés>` : toute clé hors liste blanche
 * (tri, page, `inclure_brouillons`…) → 422, jamais ignorée en silence.
 * Aucune liste d'identifiants de dossiers n'est acceptée.
 *
 * `cibles` (Lot 3, ADR-48) : nombre de dossiers à recevoir POUR CETTE
 * OPÉRATION, facultatif par évaluateur — jamais un plafond de charge totale.
 * Absent ou vide = répartition égale classique (ADR-45), inchangée. Un seul
 * évaluateur par cible (`distinct`) ; 0..1000 (même plafond que l'opération).
 * La cohérence avec l'ensemble résolu d'évaluateurs (restriction, actifs) est
 * vérifiée par `ResolutionCibles`, pas ici (elle a besoin de ce calcul).
 */
class ApercuAffectationAutomatiqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'criteres' => ['present', 'array:'.implode(',', FiltreCandidatures::CLES)],
            ...FiltreCandidatures::regles('criteres.'),
            'evaluateur_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'evaluateur_ids.*' => ['uuid', 'distinct'],
            'nombre_correspondants' => ['required', 'integer', 'min:0'],
            'cibles' => ['sometimes', 'nullable', 'array'],
            'cibles.*.evaluateur_id' => ['required', 'uuid', 'distinct'],
            'cibles.*.nombre' => ['required', 'integer', 'between:0,1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function criteres(): array
    {
        return $this->validated('criteres') ?? [];
    }

    /**
     * @return list<string>|null
     */
    public function evaluateurIds(): ?array
    {
        $ids = $this->validated('evaluateur_ids');

        return $ids === null ? null : array_values($ids);
    }

    /**
     * @return array<string, int>|null id évaluateur => cible, ou null si absent/vide (répartition égale classique)
     */
    public function cibles(): ?array
    {
        $cibles = $this->validated('cibles');
        if ($cibles === null || $cibles === []) {
            return null;
        }

        return collect($cibles)->mapWithKeys(fn (array $c) => [$c['evaluateur_id'] => (int) $c['nombre']])->all();
    }
}
