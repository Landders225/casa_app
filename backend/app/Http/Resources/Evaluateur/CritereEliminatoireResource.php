<?php

namespace App\Http\Resources\Evaluateur;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Critère éliminatoire déclenché (🔴). L'évaluateur doit voir pourquoi un
 * dossier est non éligible ; le candidat, jamais.
 *
 * @mixin \App\Models\CritereEliminatoireDeclenche
 */
class CritereEliminatoireResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code_critere' => $this->code_critere,
            'detail' => $this->detail,
            'origine' => $this->origine,
            'declenche_le' => $this->declenche_le?->toIso8601String(),
        ];
    }
}
