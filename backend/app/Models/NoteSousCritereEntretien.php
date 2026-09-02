<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — sous-note d'entretien, table `note_sous_critere_entretien`
 * (docs/mld.md §5, ADR-05). 🔴. Les 12 sous-notes (cf. D-4c-4) persistées
 * individuellement, séparément du score agrégé `entretien.score_total`.
 *
 * `points_attribues` : `numeric(3,1)` (0.1 de précision, demi-points admis).
 * Clé primaire composite (entretien_id, sous_critere_id) — insertion en masse +
 * lecture via `Entretien::notes()`.
 */
class NoteSousCritereEntretien extends Model
{
    protected $table = 'note_sous_critere_entretien';

    protected $primaryKey = 'entretien_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['entretien_id', 'sous_critere_id', 'points_attribues'];

    protected function casts(): array
    {
        return ['points_attribues' => 'decimal:1'];
    }

    /**
     * @return BelongsTo<SousCritereEntretien, $this>
     */
    public function sousCritere(): BelongsTo
    {
        return $this->belongsTo(SousCritereEntretien::class, 'sous_critere_id');
    }
}
