<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Saisie des motifs de décision (Lot 5a) — `PUT /admin/candidatures/{c}/decision/motifs`.
 *
 *  - `motif_interne` (🔴)      : note interne, JAMAIS communiquée au candidat ;
 *  - `motif_communicable` (🟡) : affiché au candidat SEULEMENT après publication
 *    (Lot 5b) ; si absent → message générique.
 *
 * Champs partiels ; `null` explicite efface. N'affecte jamais `rang` / `decision`
 * (pilotés par le calcul du classement).
 */
class EnregistrerMotifsRequest extends FormRequest
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
            'motif_interne' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'motif_communicable' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function champsMotifs(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['motif_interne', 'motif_communicable']),
        );
    }
}
