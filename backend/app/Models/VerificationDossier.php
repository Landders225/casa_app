<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — vérification du dossier par l'évaluateur, table `verification_dossier`
 * (docs/mld.md §4, ADR-07). Table entière 🔴 — jamais renvoyée à un candidat.
 * 1-1 avec `candidature`. SEULE source de vérité pour nationalité / diplôme :
 * jamais auto-déclarés par le candidat.
 */
class VerificationDossier extends Model
{
    protected $table = 'verification_dossier';

    protected $primaryKey = 'candidature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nationalite_confirmee',
        'diplome_verifie',
        'verifie_par',
        'verifie_le',
    ];

    protected function casts(): array
    {
        return [
            'nationalite_confirmee' => 'boolean',
            'verifie_le' => 'datetime',
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
     * @return BelongsTo<MembreEquipe, $this>
     */
    public function verifiePar(): BelongsTo
    {
        return $this->belongsTo(MembreEquipe::class, 'verifie_par');
    }
}
