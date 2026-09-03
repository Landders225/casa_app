<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditResource;
use App\Models\JournalAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * JOURNAL D'AUDIT — consultation (Lot 6a) — administrateur strict ABSOLU.
 *
 *   GET /api/admin/audit
 *     ?module= &action= &auteur=<email> &date_debut= &date_fin= &recherche= &page=
 *
 * LECTURE SEULE : pas de POST/PUT/DELETE. L'immuabilité est déjà garantie par le
 * trigger PostgreSQL append-only + l'absence de route de modification (ADR-12).
 *
 * ⚠️ `?recherche=` est borné à `action` + `objet`. Il ne touche JAMAIS
 * `ancienne_valeur` / `nouvelle_valeur` / `motif` (🔴 : scores, motifs internes)
 * — une recherche plein-texte sur ces colonnes serait un moyen détourné de
 * fouiller le contenu confidentiel en masse. Le contenu sensible se lit ligne
 * par ligne, ne se cherche pas.
 */
class AuditController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'date_debut' => ['sometimes', 'date'],
            'date_fin' => ['sometimes', 'date'],
        ]);

        $query = JournalAudit::query()
            ->with(['auteur.membreEquipe', 'auteur.candidat'])
            ->orderByDesc('horodatage');

        if ($module = $request->query('module')) {
            $query->where('module', $module);
        }
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($auteur = $request->query('auteur')) {
            $query->whereHas('auteur', fn ($q) => $q->where('email', $auteur));
        }
        if ($debut = $request->query('date_debut')) {
            $query->where('horodatage', '>=', $debut);
        }
        if ($fin = $request->query('date_fin')) {
            $query->where('horodatage', '<=', $fin.' 23:59:59');
        }
        if ($recherche = $request->query('recherche')) {
            // Borné à action + objet — jamais les colonnes 🔴 (cf. docbloc).
            $query->where(fn ($q) => $q
                ->where('action', 'ilike', '%'.$recherche.'%')
                ->orWhere('objet', 'ilike', '%'.$recherche.'%'));
        }

        return AuditResource::collection($query->paginate(20)->withQueryString());
    }
}
