<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CASA — évaluation du volet Dossier, table `evaluation_dossier`
 * (docs/mld.md §4, ADR-04). Table entière 🔴 — JAMAIS renvoyée à un candidat.
 *
 * SNAPSHOT : `score_total` (/65) et les 6 lignes `score_rubrique_dossier` sont
 * figés à la validation, avec `grille_id` = version exacte utilisée. Un
 * changement de grille ultérieur ne recalcule jamais cette ligne (le contrôleur
 * d'aperçu renvoie le snapshot tel quel dès que `valide = true`).
 *
 * 1-1 avec `candidature` (`candidature_id` = PK).
 */
class EvaluationDossier extends Model
{
    protected $table = 'evaluation_dossier';

    protected $primaryKey = 'candidature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'grille_id',
        'score_total',
        'valide',
        'valide_le',
        'valide_par',
    ];

    protected function casts(): array
    {
        return [
            'score_total' => 'decimal:1',
            'valide' => 'boolean',
            'valide_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidature, $this>
     */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class, 'candidature_id');
    }

    /**
     * @return BelongsTo<Grille, $this>
     */
    public function grille(): BelongsTo
    {
        return $this->belongsTo(Grille::class, 'grille_id');
    }

    /**
     * @return BelongsTo<MembreEquipe, $this>
     */
    public function validePar(): BelongsTo
    {
        return $this->belongsTo(MembreEquipe::class, 'valide_par');
    }

    /**
     * Détail figé par rubrique (6 lignes).
     *
     * @return HasMany<ScoreRubriqueDossier, $this>
     */
    public function scoresRubriques(): HasMany
    {
        return $this->hasMany(ScoreRubriqueDossier::class, 'evaluation_dossier_id', 'candidature_id');
    }
}
