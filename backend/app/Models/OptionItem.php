<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — table `option_item` (docs/mld.md §6). Une réponse possible d'un item
 * `choix`, avec ses `points` (ex. SC.04=BAC → 3 ; SC.04=CEPE → 0, eliminatoire).
 */
class OptionItem extends Model
{
    use HasUuids;

    protected $table = 'option_item';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['item_id', 'valeur', 'label', 'points', 'eliminatoire'];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'eliminatoire' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
