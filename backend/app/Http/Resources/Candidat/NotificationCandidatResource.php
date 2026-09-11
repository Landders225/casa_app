<?php

namespace App\Http\Resources\Candidat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Une notification in-app du candidat — `GET /api/candidat/notifications`
 * (Lot 12c). Passthrough du contenu déjà neutre écrit par `toDatabase()`
 * (Lot 12b/12c, ADR-33) : cette Resource ne recompose rien, elle ne fait que
 * sérialiser `data` (déjà cast en tableau par `DatabaseNotification`) + l'état
 * de lecture.
 *
 * @mixin DatabaseNotification
 */
class NotificationCandidatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie' => $this->data['categorie'] ?? null,
            'titre' => $this->data['titre'] ?? null,
            'message' => $this->data['message'] ?? null,
            'lien' => $this->data['lien'] ?? null,
            'lue' => $this->read_at !== null,
            'creee_le' => $this->created_at?->toIso8601String(),
        ];
    }
}
