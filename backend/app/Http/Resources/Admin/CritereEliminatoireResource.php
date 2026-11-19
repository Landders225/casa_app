<?php

namespace App\Http\Resources\Admin;

use App\Models\CritereEliminatoireDeclenche;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Critère éliminatoire déclenché (🔴), vu par l'ADMINISTRATEUR (Lot 4) — aide
 * au choix avant un rétablissement d'éligibilité (ADR-49).
 *
 * ⚠️ Duplicata volontaire d'`Evaluateur\CritereEliminatoireResource` : les
 * arbres de Resources admin/évaluateur/candidat restent étanches (garde de
 * non-pont), jamais réutilisés en croisé même quand le contenu est identique.
 *
 * @mixin CritereEliminatoireDeclenche
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
