<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CASA — table `item` (docs/mld.md §6, volet Dossier). Un item de la grille
 * (SC.01…, SE.02…, LANG.FR, EXP.DUREE, MO.04, DI.02…). `max_points` NULL quand
 * `notee = false`.
 */
class Item extends Model
{
    use HasUuids;

    protected $table = 'item';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rubrique_id', 'code', 'label', 'type', 'max_points',
        'notation_evaluateur', 'notee', 'eliminatoire', 'eliminatoire_groupe',
    ];

    protected function casts(): array
    {
        return [
            'max_points' => 'decimal:2',
            'notation_evaluateur' => 'boolean',
            'notee' => 'boolean',
            'eliminatoire' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Rubrique, $this>
     */
    public function rubrique(): BelongsTo
    {
        return $this->belongsTo(Rubrique::class, 'rubrique_id');
    }

    /**
     * @return HasMany<OptionItem, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(OptionItem::class, 'item_id');
    }
}
