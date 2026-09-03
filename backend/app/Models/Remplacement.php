<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — remplacement liste d'attente → retenu, table `remplacement`
 * (docs/mld.md §7). Table entière 🔴 — JAMAIS sur un chemin candidat.
 *
 * Trace un acte exceptionnel (Lot 6b) : un candidat RETENU publié est déclaré
 * indisponible, le premier de la liste d'attente de la même filière est promu.
 * `candidature_promue_id` nullable (personne à promouvoir). Irréversible.
 */
class Remplacement extends Model
{
    use HasUuids;

    protected $table = 'remplacement';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_indisponible_id',
        'candidature_promue_id',
        'motif',
        'effectue_par',
        'effectue_le',
    ];

    protected function casts(): array
    {
        return ['effectue_le' => 'datetime'];
    }

    /**
     * @return BelongsTo<Candidature, $this>
     */
    public function candidatureIndisponible(): BelongsTo
    {
        return $this->belongsTo(Candidature::class, 'candidature_indisponible_id');
    }

    /**
     * @return BelongsTo<Candidature, $this>
     */
    public function candidaturePromue(): BelongsTo
    {
        return $this->belongsTo(Candidature::class, 'candidature_promue_id');
    }

    /**
     * @return BelongsTo<MembreEquipe, $this>
     */
    public function effectuePar(): BelongsTo
    {
        return $this->belongsTo(MembreEquipe::class, 'effectue_par');
    }
}
