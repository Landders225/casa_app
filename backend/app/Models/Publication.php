<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — publication des résultats d'une campagne, table `publication`
 * (docs/mld.md §7, ADR-08). 1-1 avec `campagne`.
 *
 * Son EXISTENCE rend la décision visible du candidat (`StatutPublicResolver`,
 * ADR-03). Le Lot 5a ne fait que LIRE cette table (existe ? → classement non
 * recalculable) ; l'acte de publication est le Lot 5b. `publiee_par` est 🔴.
 */
class Publication extends Model
{
    use HasUuids;

    protected $table = 'publication';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['campagne_id', 'publiee_le', 'publiee_par'];

    protected function casts(): array
    {
        return ['publiee_le' => 'datetime'];
    }

    /**
     * @return BelongsTo<Campagne, $this>
     */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'campagne_id');
    }

    /**
     * Membre d'équipe (administrateur) qui a publié. 🔴.
     *
     * @return BelongsTo<MembreEquipe, $this>
     */
    public function publiePar(): BelongsTo
    {
        return $this->belongsTo(MembreEquipe::class, 'publiee_par');
    }
}
