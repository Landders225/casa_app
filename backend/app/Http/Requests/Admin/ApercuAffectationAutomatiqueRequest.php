<?php

namespace App\Http\Requests\Admin;

use App\Domain\Supervision\FiltreCandidatures;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Affectation automatique par critères (ADR-45) — APERÇU (base de la requête
 * d'exécution, qui y ajoute `nombre_affectables`).
 *
 *   POST /api/admin/affectations/automatique/apercu
 *     { criteres: {…}, evaluateur_ids?: [uuid…], nombre_correspondants }
 *   POST /api/admin/affectations/automatique
 *     { …idem, nombre_affectables }   (`AffectationAutomatiqueRequest`)
 *
 * `criteres` : les MÊMES filtres que la recherche (`FiltreCandidatures`,
 * source unique), en corps POST (le terme de recherche peut être un nom —
 * jamais dans une URL, ADR-39). `array:<clés>` : toute clé hors liste blanche
 * (tri, page, `inclure_brouillons`…) → 422, jamais ignorée en silence.
 * Aucune liste d'identifiants de dossiers n'est acceptée.
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
}
