<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — journal d'audit, table `journal_audit` (docs/mld.md §8, ADR-12).
 * Table entière 🔴, réservée évaluateur/administrateur.
 *
 * APPEND-ONLY : garanti par PostgreSQL (trigger BEFORE UPDATE/DELETE/TRUNCATE)
 * ET par l'absence de toute route/policy de modification. Ce modèle n'expose
 * donc volontairement que la création.
 */
class JournalAudit extends Model
{
    use HasUuids;

    protected $table = 'journal_audit';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'auteur_id',
        'role',
        'action',
        'module',
        'objet',
        'ancienne_valeur',
        'nouvelle_valeur',
        'motif',
        'resultat',
        'horodatage',
    ];

    protected function casts(): array
    {
        return ['horodatage' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
