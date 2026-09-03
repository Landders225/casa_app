<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangerStatutFiliereRequest;
use App\Models\Filiere;
use App\Models\JournalAudit;
use Illuminate\Http\JsonResponse;

/**
 * FILIÈRES — activation / désactivation (Lot 6a) — administrateur strict.
 *
 *   PATCH /api/admin/filieres/{filiere}  { actif: bool }
 *
 * Une filière `actif = false` :
 *  - n'accepte plus de nouvelle candidature (impact `POST /api/candidatures` → 422) ;
 *  - apparaît `actif: false` sur la route publique `GET /api/filieres`.
 *
 * Idempotent : repasser `actif` à sa valeur → 200 sans ligne d'audit.
 */
class FiliereController extends Controller
{
    public function changerStatut(ChangerStatutFiliereRequest $request, Filiere $filiere): JsonResponse
    {
        $actif = (bool) $request->validated('actif');

        if ((bool) $filiere->actif !== $actif) {
            $filiere->forceFill(['actif' => $actif])->save();

            JournalAudit::create([
                'auteur_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => $actif ? 'Activation de filière' : 'Désactivation de filière',
                'module' => 'Filières',
                'objet' => $filiere->nom,
                'ancienne_valeur' => $actif ? 'Inactive' : 'Active',
                'nouvelle_valeur' => $actif ? 'Active' : 'Inactive',
                'resultat' => 'Succès',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $filiere->id,
                'code' => $filiere->code,
                'nom' => $filiere->nom,
                'actif' => (bool) $filiere->actif,
            ],
        ]);
    }
}
