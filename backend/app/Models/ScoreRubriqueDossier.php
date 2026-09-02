<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — détail figé du score par rubrique, table `score_rubrique_dossier`
 * (docs/mld.md §4). 🔴. 6 lignes par `evaluation_dossier`.
 *
 * `score_obtenu` : `numeric(6,4)` depuis le Lot 4b (D-4b-1) — les rubriques
 * `experience` / `langues` produisent des décimales périodiques (scoring.js).
 *
 * Clé primaire composite (evaluation_dossier_id, rubrique_id) : ce modèle sert
 * surtout à l'insertion en masse et à la lecture via la relation
 * `EvaluationDossier::scoresRubriques()`.
 */
class ScoreRubriqueDossier extends Model
{
    protected $table = 'score_rubrique_dossier';

    protected $primaryKey = 'evaluation_dossier_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['evaluation_dossier_id', 'rubrique_id', 'score_obtenu'];

    protected function casts(): array
    {
        return ['score_obtenu' => 'decimal:4'];
    }

    /**
     * @return BelongsTo<Rubrique, $this>
     */
    public function rubrique(): BelongsTo
    {
        return $this->belongsTo(Rubrique::class, 'rubrique_id');
    }
}
