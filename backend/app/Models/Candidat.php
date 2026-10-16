<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Normalizer;

/**
 * CASA — profil candidat, table `candidat` (docs/mld.md §1). 1-1 avec `utilisateur`.
 * Toutes les colonnes sont 🟢 (cf. dictionnaire-donnees.md).
 */
class Candidat extends Model
{
    use HasUuids;

    protected $table = 'candidat';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'utilisateur_id',
        'prenom',
        'nom',
        'sexe',
        'date_naissance',
        'cni',
        'numero_cmu',
        'telephone',
        'ville_residence',
        'residence_ci',
    ];

    /**
     * Défense en profondeur — l'autorité de non-fuite reste l'API Resource
     * (liste blanche), cf. ADR-02 (même principe que `User::$hidden`).
     * `recherche_normalisee` (ADR-39) n'a aucune vocation à être exposée :
     * c'est un index interne, jamais une donnée à afficher ou à exporter.
     *
     * @var list<string>
     */
    protected $hidden = [
        'recherche_normalisee',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'residence_ci' => 'boolean',
        ];
    }

    /**
     * `recherche_normalisee` (lot recherche-admin, ADR-39) n'est jamais
     * mass-assignable : toujours recalculée ici, jamais acceptée en entrée.
     */
    protected static function booted(): void
    {
        static::saving(function (Candidat $candidat) {
            $candidat->recherche_normalisee = self::normaliserRecherche($candidat->prenom, $candidat->nom);
        });
    }

    /**
     * Normalisation utilisée pour la recherche candidatures admin (ADR-39) :
     * insensible à la casse, aux accents (`Str::ascii`), aux apostrophes et
     * aux tirets — et, depuis le correctif prod, aux variantes de
     * PRÉSENTATION Unicode (lettres mathématiques italiques/grasses/script,
     * pleine largeur, ligatures) via NFKC avant la translittération ASCII.
     * SOURCE UNIQUE — ne jamais dupliquer cette règle ailleurs (même
     * principe que `NormalisationVille::classer()`).
     */
    public static function normaliserRecherche(?string $prenom, ?string $nom): string
    {
        $brut = trim(self::normaliserUnicode($prenom ?? '').' '.self::normaliserUnicode($nom ?? ''));
        $sansSeparateurs = str_replace(["'", '’', '-'], '', Str::ascii($brut));
        $espacesUniques = trim(preg_replace('/\s+/', ' ', $sansSeparateurs) ?? '');

        return mb_strtolower($espacesUniques);
    }

    /**
     * Correctif prod (constat réel : 1 candidat/527 écrit ENTIÈREMENT en
     * lettres Unicode « mathématiques », blocs U+1D400–U+1D7FF + U+210E —
     * 0 lettre ASCII, `recherche_normalisee` ressortait vide). NFKC
     * (`Normalizer::FORM_KC`) réduit ces variantes de présentation à leur
     * forme canonique (𝑎𝑏𝑐 → abc, ℎ → h, ﬁ → fi, Ａ → A) — nécessaire
     * AVANT `Str::ascii()` ci-dessus, qui ne connaît que le bloc Latin et
     * supprime silencieusement tout caractère hors de ce bloc, NFKC ou pas.
     * Garde-fou : `Normalizer` (extension `intl`) absent, ou normalisation
     * refusée (`false`, chaîne malformée) -> valeur d'origine inchangée,
     * jamais d'exception.
     */
    private static function normaliserUnicode(string $valeur): string
    {
        if (! class_exists(Normalizer::class)) {
            return $valeur;
        }

        $normalisee = Normalizer::normalize($valeur, Normalizer::FORM_KC);

        return $normalisee === false ? $valeur : $normalisee;
    }

    /**
     * Recalcule `recherche_normalisee` pour TOUTES les lignes déjà en base —
     * appelée par la migration `..._add_recherche_normalisee_to_candidat_table`
     * (backfill) et testable isolément (lignes insérées hors du hook, via
     * `DB::table('candidat')->insert()`, ex. `ComptesDemoSeeder`).
     *
     * Délibérément en Query Builder BRUT (`DB::table()`), PAS en `save()`
     * Eloquent : un backfill n'est pas une vraie modification de la ligne
     * (le reste de la ligne est strictement inchangé) et ne doit déclencher
     * NI le hook `booted()` ci-dessus (boucle sans intérêt, il recalculerait
     * la même valeur), NI `updated_at` (qui mentirait sur la date de
     * dernière modification réelle du candidat). `Candidat::normaliserRecherche()`
     * reste l'UNIQUE fonction de normalisation — appelée ici directement en
     * PHP, pas dupliquée en SQL.
     */
    public static function rafraichirRechercheNormalisee(): void
    {
        DB::table('candidat')->select('id', 'prenom', 'nom')->orderBy('id')->chunk(500, function ($candidats) {
            foreach ($candidats as $candidat) {
                DB::table('candidat')->where('id', $candidat->id)->update([
                    'recherche_normalisee' => static::normaliserRecherche($candidat->prenom, $candidat->nom),
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /**
     * @return HasMany<Candidature, $this>
     */
    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class, 'candidat_id');
    }
}
