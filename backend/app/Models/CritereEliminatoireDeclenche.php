<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — critère éliminatoire effectivement déclenché pour une candidature
 * (docs/mld.md §4, ADR-06). Table entière 🔴 : jamais renvoyée à un candidat,
 * ni à la soumission ni après (avant publication).
 *
 * `origine` distingue ce qui est connu dès la soumission de ce qui n'est connu
 * qu'après vérification évaluateur.
 */
class CritereEliminatoireDeclenche extends Model
{
    use HasUuids;

    protected $table = 'critere_eliminatoire_declenche';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'code_critere',
        'detail',
        'origine',
        'declenche_le',
    ];

    protected function casts(): array
    {
        return ['declenche_le' => 'datetime'];
    }

    /**
     * @return BelongsTo<Candidature, $this>
     */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class, 'candidature_id');
    }
}
