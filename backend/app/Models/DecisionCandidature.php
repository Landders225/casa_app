<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — décision interne sur une candidature, table `decision_candidature`
 * (docs/mld.md §7, ADR-03). 1-1 avec `candidature`.
 *
 * Confidentialité :
 *  - `rang` (🔴)              : jamais communiqué à personne d'autre que le staff ;
 *                              NULL pour un `non_eligible` (non classé, cf. D-5a-4).
 *  - `decision` (🟡)          : retenu / liste_attente / non_retenu / indisponible ;
 *                              visible du candidat SEULEMENT si une `publication`
 *                              existe (Lot 5b — pas encore) ;
 *  - `motif_interne` (🔴)     : note interne, JAMAIS communiquée ;
 *  - `motif_communicable` (🟡): affiché au candidat après publication, sinon
 *                              message générique.
 *
 * Écrite dès le Lot 5a (calcul du classement) — SANS `publication`, donc
 * invisible du candidat (`StatutPublicResolver` inchangé).
 */
class DecisionCandidature extends Model
{
    protected $table = 'decision_candidature';

    protected $primaryKey = 'candidature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'rang',
        'decision',
        'motif_interne',
        'motif_communicable',
    ];

    protected function casts(): array
    {
        return ['rang' => 'integer'];
    }

    /**
     * @return BelongsTo<Candidature, $this>
     */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class, 'candidature_id');
    }
}
