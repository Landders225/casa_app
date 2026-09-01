<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CASA — réponses brutes du formulaire, table `reponse_formulaire`
 * (docs/mld.md §3, ADR-05). Table entière 🔴 EN TANT QUE source de scoring.
 *
 * Frontière (Lot 3a) : le candidat LIT et ÉCRIT ses propres déclarations
 * (tous les champs `sc*`, `se*`, langues, informatique, `acces_*`, `di*`,
 * `mo04_lettre_motivation`). Il ne peut JAMAIS toucher `mo04_note_etoiles`
 * (saisie évaluateur) — cette colonne est absente de `$fillable` et de la
 * Resource candidat.
 */
class ReponseFormulaire extends Model
{
    protected $table = 'reponse_formulaire';

    protected $primaryKey = 'candidature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    /**
     * Champs déclaratifs modifiables par le candidat. `mo04_note_etoiles`,
     * `candidature_id` en sont volontairement absents.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sc01_scolarise_actuellement',
        'sc02_derniere_classe',
        'sc03_document_justifiant_niveau',
        'sc05_beneficiaire_formation_actuelle',
        'sc06_deja_beneficie_formation',
        'sc07_filiere_suivie',
        'sc08_mene_a_terme',
        'sc09_motif_non_achevement',
        'se01_vit_avec',
        'se02_orphelin',
        'se03_situation_emploi',
        'se04_source_revenu',
        'se05_personnes_a_charge',
        'se06_soutien_menage',
        'langue_ecrit',
        'langue_parle',
        'langue_comprehension',
        'info_word',
        'info_excel',
        'info_internet',
        'acces_plateau',
        'acces_deux_plateaux_vallons',
        'mo04_lettre_motivation',
        'di01_disponible_lun_ven',
        'di02_contraintes',
        'di03_engagement_complet',
    ];

    protected function casts(): array
    {
        return [
            'langue_ecrit' => 'integer',
            'langue_parle' => 'integer',
            'langue_comprehension' => 'integer',
            'info_word' => 'integer',
            'info_excel' => 'integer',
            'info_internet' => 'integer',
            'mo04_note_etoiles' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Candidature, $this>
     */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class, 'candidature_id');
    }
}
