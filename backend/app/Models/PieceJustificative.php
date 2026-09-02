<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CASA — pièce justificative, table `piece_justificative` (docs/mld.md §3, ADR-11).
 *
 * ⚠️ `chemin_stockage` est 🔴 : chemin interne sur le disque privé `documents`,
 * n'apparaît JAMAIS dans une réponse API (cf. PieceJustificativeResource). Le
 * seul accès au fichier est GET /api/pieces/{piece}/download (route applicative
 * authentifiée + autorisée), jamais une URL statique.
 *
 * Exclusivité (CHECK `piece_rattachee_dossier_xor_experience`) :
 *  - rattachement='dossier'    -> candidature_id + type_document_code NON NULL ;
 *  - rattachement='experience' -> les deux NULL (le lien se fait via
 *    experience_professionnelle.piece_justificative_id).
 */
class PieceJustificative extends Model
{
    use HasUuids;

    protected $table = 'piece_justificative';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'candidature_id',
        'type_document_code',
        'rattachement',
        'nom_original',
        'chemin_stockage',
        'taille_octets',
        'type_mime',
        'depose_le',
    ];

    protected function casts(): array
    {
        return [
            'taille_octets' => 'integer',
            'depose_le' => 'datetime',
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
     * @return BelongsTo<TypeDocument, $this>
     */
    public function typeDocument(): BelongsTo
    {
        return $this->belongsTo(TypeDocument::class, 'type_document_code', 'code');
    }

    /**
     * Expérience dont cette pièce est le justificatif (rattachement='experience').
     *
     * @return HasOne<ExperienceProfessionnelle, $this>
     */
    public function experience(): HasOne
    {
        return $this->hasOne(ExperienceProfessionnelle::class, 'piece_justificative_id');
    }

    /**
     * Id de la candidature propriétaire, quel que soit le rattachement.
     */
    public function candidatureIdProprietaire(): ?string
    {
        if ($this->candidature_id !== null) {
            return $this->candidature_id;
        }

        return $this->experience()->value('candidature_id');
    }
}
