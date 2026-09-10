<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membre d'équipe vu par l'ADMINISTRATEUR sur l'écran de GESTION des comptes
 * (Lot 11b) — `GET /api/admin/membres`.
 *
 * Contrairement à {@see EvaluateurResource} (annuaire minimal pour peupler un
 * sélecteur d'affectation), c'est bien une FICHE DE COMPTE : elle expose
 * l'e-mail (identifiant de connexion), le rôle et le statut `actif` — ce que
 * l'admin doit voir pour gérer l'équipe.
 *
 * Liste BLANCHE stricte : on énumère les champs un par un. JAMAIS
 * `mot_de_passe_hash` (🔴), jamais un champ interne ou un timestamp technique.
 *
 * @mixin User
 */
class MembreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membre = $this->membreEquipe;

        return [
            'id' => $this->id, // id `utilisateur` — cible des routes de gestion
            'email' => $this->email,
            'role' => $this->role,
            'actif' => (bool) $this->actif,
            'prenom' => $membre?->prenom,
            'nom' => $membre?->nom,
            'poste' => $membre?->poste,
            'derniere_connexion_le' => $this->derniere_connexion_le?->toIso8601String(),
            'dossiers_affectes' => (int) ($membre?->dossiers_affectes_count ?? 0),
            'dossiers_evalues' => (int) ($membre?->dossiers_evalues_count ?? 0),
        ];
    }
}
