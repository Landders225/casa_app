<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — expérience professionnelle déclarée, table
 * `experience_professionnelle` (docs/mld.md §3). Table entière 🔴 comme source
 * de scoring, mais le candidat déclare et édite ses propres expériences.
 *
 * `piece_justificative_id` est nullable depuis le Lot 3a (upload = Lot 3b ;
 * règle « 1 expérience = 1 justificatif » vérifiée à la soumission, Lot 3c).
 */
class ExperienceProfessionnelle extends Model
{
    use HasUuids;

    protected $table = 'experience_professionnelle';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['domaine', 'duree_categorie'];

    /**
     * @return BelongsTo<Candidature, $this>
     */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class, 'candidature_id');
    }
}
