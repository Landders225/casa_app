<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — table `sous_critere_entretien` (docs/mld.md §6, volet Entretien).
 * PRES.01…, REL.01…, EO.01…, MOE.01… avec `max_points` (numeric(3,1)).
 */
class SousCritereEntretien extends Model
{
    use HasUuids;

    protected $table = 'sous_critere_entretien';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['rubrique_id', 'code', 'label', 'max_points'];

    protected function casts(): array
    {
        return ['max_points' => 'decimal:1'];
    }

    /**
     * @return BelongsTo<Rubrique, $this>
     */
    public function rubrique(): BelongsTo
    {
        return $this->belongsTo(Rubrique::class, 'rubrique_id');
    }
}
