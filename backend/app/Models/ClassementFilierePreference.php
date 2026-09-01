<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — ordre de préférence des filières exprimé par le candidat (MO.03),
 * table `classement_filiere_preference` (docs/mld.md §3). 🟢 : préférence du
 * candidat lui-même, pas un classement de candidats. Clé composite
 * (candidature_id, filiere_id) — manipulée uniquement via la relation
 * `Candidature::classement()` (create / delete en masse).
 */
class ClassementFilierePreference extends Model
{
    protected $table = 'classement_filiere_preference';

    public $incrementing = false;

    public $timestamps = false;

    // Clé composite réelle (candidature_id, filiere_id). Eloquent n'a besoin
    // que d'un nom ici : le modèle n'est jamais chargé/sauvé individuellement,
    // seulement créé/supprimé en masse via Candidature::classement().
    protected $primaryKey = 'candidature_id';

    /**
     * @var list<string>
     */
    protected $fillable = ['filiere_id', 'rang'];

    protected function casts(): array
    {
        return ['rang' => 'integer'];
    }

    /**
     * @return BelongsTo<Filiere, $this>
     */
    public function filiere(): BelongsTo
    {
        return $this->belongsTo(Filiere::class, 'filiere_id');
    }
}
