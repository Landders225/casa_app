<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Supervision des candidatures — recherche + filtres + tri (lot recherche admin).
 *
 *   POST /api/admin/candidatures
 *     { recherche?, statut_interne?, filiere?, evaluateur?, campagne?,
 *       eligibilite?, decision?, inclure_brouillons?, tri_colonne?,
 *       tri_direction?, page? }
 *
 * POST plutôt que GET : `recherche` ne doit jamais atteindre une query string
 * archivée en clair par les access log nginx/Apache (ADR-39).
 *
 * `eligibilite`/`decision`/`tri_colonne`/`tri_direction` sont des LISTES
 * BLANCHES strictes (`Rule::in`), reprises des contraintes CHECK réelles de
 * `candidature`/`decision_candidature` (cf. audit, migrations
 * `2026_09_02_100016_create_candidature_table.php` et
 * `2026_09_02_100028_create_decision_candidature_table.php`) — jamais une
 * valeur libre injectée dans un `where`/`orderBy`.
 */
class RechercherCandidaturesRequest extends FormRequest
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
            'recherche' => ['sometimes', 'nullable', 'string', 'max:100'],
            'statut_interne' => ['sometimes', 'nullable', 'string'],
            'filiere' => ['sometimes', 'nullable', 'string'],
            'evaluateur' => ['sometimes', 'nullable', 'string'],
            'campagne' => ['sometimes', 'nullable', 'string'],
            'inclure_brouillons' => ['sometimes', 'boolean'],
            'eligibilite' => ['sometimes', 'nullable', Rule::in(['non_verifie', 'eligible', 'non_eligible'])],
            'decision' => ['sometimes', 'nullable', Rule::in(['retenu', 'liste_attente', 'non_retenu', 'indisponible', 'sans_decision'])],
            'tri_colonne' => ['sometimes', 'nullable', Rule::in(['numero_dossier', 'candidat', 'score_dossier', 'score_entretien', 'date_soumission'])],
            'tri_direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
