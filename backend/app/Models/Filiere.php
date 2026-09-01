<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * CASA — filière CQP, table `filiere` (docs/mld.md §2). Toutes colonnes 🟢.
 */
class Filiere extends Model
{
    use HasUuids;

    protected $table = 'filiere';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'nom', 'description', 'icone', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Campagne, $this>
     */
    public function campagnes(): BelongsToMany
    {
        return $this->belongsToMany(Campagne::class, 'campagne_filiere', 'filiere_id', 'campagne_id')
            ->withPivot('quota');
    }
}
