<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CASA — table `volet` (docs/mld.md §6). `dossier` /65, `entretien` /35.
 */
class Volet extends Model
{
    use HasUuids;

    protected $table = 'volet';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['grille_id', 'code', 'label', 'max_points'];

    protected function casts(): array
    {
        return ['max_points' => 'decimal:1'];
    }

    /**
     * @return BelongsTo<Grille, $this>
     */
    public function grille(): BelongsTo
    {
        return $this->belongsTo(Grille::class, 'grille_id');
    }

    /**
     * @return HasMany<Rubrique, $this>
     */
    public function rubriques(): HasMany
    {
        return $this->hasMany(Rubrique::class, 'volet_id')->orderBy('ordre');
    }
}
