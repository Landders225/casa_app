<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CASA — entretien, table `entretien` (docs/mld.md §5, ADR-04). Table entière 🔴
 * — JAMAIS renvoyée à un candidat. 1-1 avec `candidature` (`candidature_id` = PK).
 *
 * N'existe qu'une fois le dossier verrouillé (Lot 4b). `statut` :
 *  - `planifie` : date / heure / lieu posés ;
 *  - `realise`  : présence renseignée, sous-notes en cours de saisie (brouillon) ;
 *  - `valide`   : SNAPSHOT figé — `score_total` (/35) + `grille_id` de la grille
 *    alors active + les `note_sous_critere_entretien` (12 — cf. D-4c-4).
 *    Verrouillé (D-4c-1) :
 *    aucune modification possible, aucune ré-ouverture (correction admin = lot
 *    ultérieur). Jamais recalculé si la grille change ensuite.
 *
 * `statut_interne` de la candidature n'est PAS modifié par la validation
 * d'entretien (reste `evalue`) — l'avancement fin vit ici (comme la vérification
 * au Lot 4a).
 */
class Entretien extends Model
{
    protected $table = 'entretien';

    protected $primaryKey = 'candidature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'statut',
        'date',
        'heure',
        'lieu',
        'evaluateur_id',
        'presence',
        'observation',
        'grille_id',
        'score_total',
        'valide_le',
        'valide_par',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'score_total' => 'decimal:1',
            'valide_le' => 'datetime',
        ];
    }

    public function estVerrouille(): bool
    {
        return $this->statut === 'valide';
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
     * Membre d'équipe qui conduit l'entretien.
     *
     * @return BelongsTo<MembreEquipe, $this>
     */
    public function evaluateur(): BelongsTo
    {
        return $this->belongsTo(MembreEquipe::class, 'evaluateur_id');
    }

    /**
     * @return BelongsTo<MembreEquipe, $this>
     */
    public function validePar(): BelongsTo
    {
        return $this->belongsTo(MembreEquipe::class, 'valide_par');
    }

    /**
     * Les 12 sous-notes (brutes, ADR-05 ; D-4c-4). Mutables tant que `statut != 'valide'`.
     *
     * @return HasMany<NoteSousCritereEntretien, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(NoteSousCritereEntretien::class, 'entretien_id', 'candidature_id');
    }
}
