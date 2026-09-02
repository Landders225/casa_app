<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CASA — barème versionné, table `grille` (docs/mld.md §6, ADR-04). 🔴.
 *
 * Au plus une grille `actif = true` (index partiel `one_active_grille`). Le
 * scoring d'un brouillon utilise toujours la grille active ; une évaluation
 * verrouillée fige `evaluation_dossier.grille_id` et n'est jamais recalculée.
 */
class Grille extends Model
{
    use HasUuids;

    protected $table = 'grille';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['version', 'label', 'date_effet', 'actif', 'created_at'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'date_effet' => 'date',
            'actif' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Volet, $this>
     */
    public function volets(): HasMany
    {
        return $this->hasMany(Volet::class, 'grille_id');
    }

    /**
     * Grille active (garantie unique par l'index partiel). Lève si absente.
     */
    public static function active(): self
    {
        return static::query()->where('actif', true)->firstOrFail();
    }
}
