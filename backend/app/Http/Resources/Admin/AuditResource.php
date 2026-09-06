<?php

namespace App\Http\Resources\Admin;

use App\Models\JournalAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne du journal d'audit — `GET /api/admin/audit` (Lot 6a).
 *
 * ⚠️ ADMINISTRATEUR STRICT ABSOLU. `ancienne_valeur` / `nouvelle_valeur` /
 * `motif` contiennent des données 🔴 (scores dans les libellés, motifs internes).
 * Cette Resource n'est utilisée que par `AuditController` (route
 * `role:administrateur`) ; aucun autre rôle ne lit `journal_audit` (les autres
 * n'y font qu'écrire via `JournalAudit::create`).
 *
 * @mixin JournalAudit
 */
class AuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $auteur = $this->auteur;
        $nom = null;
        if ($auteur?->relationLoaded('membreEquipe') && $auteur->membreEquipe !== null) {
            $nom = $auteur->membreEquipe->prenom.' '.$auteur->membreEquipe->nom;
        } elseif ($auteur?->relationLoaded('candidat') && $auteur->candidat !== null) {
            $nom = $auteur->candidat->prenom.' '.$auteur->candidat->nom;
        }

        return [
            'id' => $this->id,
            'horodatage' => $this->horodatage?->toIso8601String(),
            'auteur' => [
                'email' => $auteur?->email,
                'role' => $this->role,
                'nom' => $nom,
            ],
            'action' => $this->action,
            'module' => $this->module,
            'objet' => $this->objet,
            'ancienne_valeur' => $this->ancienne_valeur,
            'nouvelle_valeur' => $this->nouvelle_valeur,
            'motif' => $this->motif,
            'resultat' => $this->resultat,
        ];
    }
}
