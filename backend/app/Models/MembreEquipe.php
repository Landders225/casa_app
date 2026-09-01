<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — profil équipe projet, table `membre_equipe` (docs/mld.md §1).
 * 1-1 avec `utilisateur` (role = evaluateur OU administrateur). Le sous-type
 * exact est lu sur `utilisateur.role`, pas dupliqué ici (ADR-10).
 */
class MembreEquipe extends Model
{
    use HasUuids;

    protected $table = 'membre_equipe';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'utilisateur_id',
        'prenom',
        'nom',
        'poste',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
