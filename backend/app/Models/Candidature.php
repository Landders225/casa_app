<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * CASA — candidature, table `candidature` (docs/mld.md §3).
 *
 * ⚠️ Colonnes 🔴 (jamais sérialisées vers un rôle candidat, cf.
 * dictionnaire-donnees.md + ADR-03) : `statut_interne`,
 * `statut_eligibilite_interne`, `dossier_verrouille*`, `evaluateur_id`,
 * `commentaire_evaluateur`, `date_evaluation`. Le seul composant autorisé à
 * lire `statut_interne` pour construire une réponse candidat est
 * App\Services\StatutPublicResolver.
 */
class Candidature extends Model
{
    use HasUuids;

    protected $table = 'candidature';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * `statut_interne` / `statut_eligibilite_interne` / verrouillage / affectation
     * ne sont volontairement PAS `fillable` : ils sont pilotés par les lots
     * évaluation / soumission, jamais par une requête candidat.
     *
     * @var list<string>
     */
    protected $fillable = [
        'candidat_id',
        'campagne_id',
        'filiere_id',
        'numero_dossier',
        'cqp_confirme',
        'date_soumission',
    ];

    protected function casts(): array
    {
        return [
            'cqp_confirme' => 'boolean',
            'dossier_verrouille' => 'boolean',
            'date_soumission' => 'datetime',
            'date_evaluation' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Candidat, $this>
     */
    public function candidat(): BelongsTo
    {
        return $this->belongsTo(Candidat::class, 'candidat_id');
    }

    /**
     * @return BelongsTo<Campagne, $this>
     */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'campagne_id');
    }

    /**
     * @return BelongsTo<Filiere, $this>
     */
    public function filiere(): BelongsTo
    {
        return $this->belongsTo(Filiere::class, 'filiere_id');
    }

    /**
     * Membre d'équipe affecté à l'évaluation (🔴).
     *
     * @return BelongsTo<MembreEquipe, $this>
     */
    public function evaluateur(): BelongsTo
    {
        return $this->belongsTo(MembreEquipe::class, 'evaluateur_id');
    }

    /**
     * Vérification du dossier par l'évaluateur (🔴, 0 ou 1).
     *
     * @return HasOne<VerificationDossier, $this>
     */
    public function verification(): HasOne
    {
        return $this->hasOne(VerificationDossier::class, 'candidature_id');
    }

    /**
     * @return HasOne<ReponseFormulaire, $this>
     */
    public function reponseFormulaire(): HasOne
    {
        return $this->hasOne(ReponseFormulaire::class, 'candidature_id');
    }

    /**
     * Évaluation du volet Dossier (🔴, 0 ou 1). Snapshot figé à la validation
     * (Lot 4b, ADR-04).
     *
     * @return HasOne<EvaluationDossier, $this>
     */
    public function evaluationDossier(): HasOne
    {
        return $this->hasOne(EvaluationDossier::class, 'candidature_id');
    }

    /**
     * @return HasMany<ExperienceProfessionnelle, $this>
     */
    public function experiences(): HasMany
    {
        return $this->hasMany(ExperienceProfessionnelle::class, 'candidature_id');
    }

    /**
     * @return HasMany<ClassementFilierePreference, $this>
     */
    public function classement(): HasMany
    {
        return $this->hasMany(ClassementFilierePreference::class, 'candidature_id');
    }

    /**
     * Pièces du dossier (rattachement='dossier'). Les justificatifs d'expérience
     * (rattachement='experience') ont `candidature_id` NULL et sont accessibles
     * via `experiences.pieceJustificative`.
     *
     * @return HasMany<PieceJustificative, $this>
     */
    public function piecesDossier(): HasMany
    {
        return $this->hasMany(PieceJustificative::class, 'candidature_id');
    }

    /**
     * Critères éliminatoires déclenchés (🔴 — jamais exposés au candidat).
     *
     * @return HasMany<CritereEliminatoireDeclenche, $this>
     */
    public function criteresEliminatoires(): HasMany
    {
        return $this->hasMany(CritereEliminatoireDeclenche::class, 'candidature_id');
    }

    public function estBrouillon(): bool
    {
        return $this->statut_interne === 'brouillon';
    }
}
