<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CASA — profil candidat, table `candidat` (docs/mld.md §1). 1-1 avec `utilisateur`.
 * Toutes les colonnes sont 🟢 (cf. dictionnaire-donnees.md).
 */
class Candidat extends Model
{
    use HasUuids;

    protected $table = 'candidat';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'utilisateur_id',
        'prenom',
        'nom',
        'sexe',
        'date_naissance',
        'cni',
        'telephone',
        'ville_residence',
        'residence_ci',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'residence_ci' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /**
     * @return HasMany<Candidature, $this>
     */
    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class, 'candidat_id');
    }
}
