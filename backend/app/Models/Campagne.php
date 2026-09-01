<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * CASA — campagne / cohorte, table `campagne` (docs/mld.md §2). Colonnes 🟢.
 */
class Campagne extends Model
{
    use HasUuids;

    protected $table = 'campagne';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['nom', 'statut', 'date_ouverture', 'date_cloture', 'places_totales', 'description'];

    protected function casts(): array
    {
        return [
            'date_ouverture' => 'date',
            'date_cloture' => 'date',
        ];
    }

    /**
     * @return BelongsToMany<Filiere, $this>
     */
    public function filieres(): BelongsToMany
    {
        return $this->belongsToMany(Filiere::class, 'campagne_filiere', 'campagne_id', 'filiere_id')
            ->withPivot('quota');
    }

    public function scopeOuverte($query)
    {
        return $query->where('statut', 'ouverte');
    }
}
