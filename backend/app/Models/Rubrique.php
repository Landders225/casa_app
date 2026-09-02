<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CASA — table `rubrique` (docs/mld.md §6). `max_points` = poids officiel
 * (scoring.js `poids`) : Dossier 12/13/5/10/15/10 ; Entretien 8/10/8/9.
 */
class Rubrique extends Model
{
    use HasUuids;

    protected $table = 'rubrique';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['volet_id', 'code', 'label', 'max_points', 'ordre'];

    protected function casts(): array
    {
        return [
            'max_points' => 'decimal:1',
            'ordre' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Volet, $this>
     */
    public function volet(): BelongsTo
    {
        return $this->belongsTo(Volet::class, 'volet_id');
    }

    /**
     * Items notés (volet Dossier uniquement).
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'rubrique_id');
    }

    /**
     * Sous-critères (volet Entretien uniquement).
     *
     * @return HasMany<SousCritereEntretien, $this>
     */
    public function sousCriteres(): HasMany
    {
        return $this->hasMany(SousCritereEntretien::class, 'rubrique_id')->orderBy('code');
    }
}
