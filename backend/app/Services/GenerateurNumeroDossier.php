<?php

namespace App\Services;

use App\Models\Campagne;
use App\Models\Candidature;
use Illuminate\Support\Facades\DB;

/**
 * Génère le `numero_dossier` d'une candidature : `CASA-<année>-<6 chiffres>`
 * (format de la maquette, ex. CASA-2026-000123). L'année est celle de
 * `campagne.date_ouverture`.
 *
 * Sérialisation par `lockForUpdate` dans une transaction. Un `SEQUENCE`
 * PostgreSQL serait plus robuste sous forte concurrence — suffisant ici.
 */
class GenerateurNumeroDossier
{
    public function generer(Campagne $campagne): string
    {
        $prefixe = sprintf('CASA-%d-', $campagne->date_ouverture->year);

        return DB::transaction(function () use ($prefixe) {
            $dernier = Candidature::query()
                ->where('numero_dossier', 'like', $prefixe.'%')
                ->orderByDesc('numero_dossier')
                ->lockForUpdate()
                ->value('numero_dossier');

            $suivant = $dernier
                ? ((int) substr($dernier, strlen($prefixe))) + 1
                : 1;

            return $prefixe.str_pad((string) $suivant, 6, '0', STR_PAD_LEFT);
        });
    }
}
