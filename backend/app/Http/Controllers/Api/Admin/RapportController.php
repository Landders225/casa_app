<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Rapports\RapportCsv;
use App\Domain\Rapports\RapportExcel;
use App\Domain\Rapports\ServiceRapports;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\RapportResource;
use App\Models\Campagne;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * RAPPORTS & STATISTIQUES de pilotage (Lot 11c, ADR-30) — administrateur STRICT.
 *
 *   GET /api/admin/rapports?campagne={uuid|toutes}       agrégats (JSON)
 *   GET /api/admin/rapports/export.csv?campagne=…        mêmes agrégats (CSV)
 *   GET /api/admin/rapports/export.xlsx?campagne=…       mêmes agrégats (Excel, Lot 15c)
 *
 * `role:administrateur` seul — JAMAIS un évaluateur (même via le recouvrement
 * ADR-10 : ce sont des stats globales de pilotage, pas son travail d'évaluation),
 * JAMAIS un candidat.
 *
 * Les trois points de sortie passent par la MÊME {@see ServiceRapports} : le
 * garde-fou k-anonymat (suppression des petites cellules, aucune cross-tab,
 * aucune ligne individuelle) s'applique à l'identique à l'écran et aux deux exports.
 *
 * `campagne` :
 *  - absent  → campagne COURANTE (ouverte, sinon la plus récente) — le CoPil
 *    pilote une cohorte à la fois ;
 *  - `toutes` → agrégat toutes campagnes confondues (vue d'ensemble) ;
 *  - `{uuid}` → cette campagne (404 si inconnue).
 */
class RapportController extends Controller
{
    public function __construct(private readonly ServiceRapports $rapports) {}

    public function index(Request $request): RapportResource
    {
        return new RapportResource($this->rapports->agreger($this->campagne($request)));
    }

    public function exportCsv(Request $request, RapportCsv $csv): Response
    {
        $donnees = $this->rapports->agreger($this->campagne($request));
        $nom = $donnees['perimetre']['campagne']['nom'] ?? 'toutes-campagnes';
        $fichier = 'casa-rapport-'.Str::slug($nom).'-'.now()->format('Y-m-d').'.csv';

        return response($csv->generer($donnees), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$fichier.'"',
        ]);
    }

    public function exportXlsx(Request $request, RapportExcel $excel): Response
    {
        $donnees = $this->rapports->agreger($this->campagne($request));
        $nom = $donnees['perimetre']['campagne']['nom'] ?? 'toutes-campagnes';
        $fichier = 'casa-rapport-'.Str::slug($nom).'-'.now()->format('Y-m-d').'.xlsx';

        return response($excel->generer($donnees), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$fichier.'"',
        ]);
    }

    /**
     * Résout le périmètre demandé. `null` = toutes campagnes.
     */
    private function campagne(Request $request): ?Campagne
    {
        $param = $request->query('campagne');

        if ($param === 'toutes') {
            return null;
        }

        if (is_string($param) && $param !== '') {
            return Campagne::query()->findOrFail($param);
        }

        // Défaut : la campagne courante (ouverte, sinon la plus récente).
        return Campagne::query()->where('statut', 'ouverte')->first()
            ?? Campagne::query()->orderByDesc('date_ouverture')->first();
    }
}
