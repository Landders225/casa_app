<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * GARDE EXÉCUTABLE — aucune requête SQL brute dans le code applicatif (Lot 10).
 *
 * Audit initial : `app/` ne contient AUCUN `DB::raw / whereRaw / selectRaw /
 * orderByRaw / havingRaw / DB::statement / DB::unprepared / fromRaw`. Tout passe
 * par Eloquent / le Query Builder, qui paramètrent les valeurs. Le SQL brut
 * n'existe que dans `database/migrations/` (chaînes 100 % statiques, jamais
 * d'entrée utilisateur — hors périmètre de ce test).
 *
 * Ce test échoue si une régression future réintroduit une requête brute sous
 * `app/`. Si un tel besoin apparaît un jour (agrégat complexe), il faudra le
 * whitelister ICI explicitement, avec la preuve que l'entrée est bindée.
 *
 * EXCEPTION whitelistée (Lot export-analyse) :
 * `App\Domain\Export\ServiceExportAnalyse::lireEnLectureSeule()` appelle
 * `DB::statement('SET TRANSACTION READ ONLY')` — une commande de niveau
 * TRANSACTION, pas une requête, sans AUCUN équivalent Eloquent/Query Builder.
 * Preuve que rien n'est bindable ni injectable : la chaîne passée est un
 * littéral fixe à 100%, sans aucune concaténation ni variable — contrairement
 * à un `whereRaw`/`selectRaw`, cette commande n'accepte même pas de
 * paramètre côté PostgreSQL.
 *
 * EXCEPTION whitelistée (lot recherche-admin, ADR-39) :
 * `App\Http\Controllers\Api\Admin\CandidatureSupervisionController::trierAvecNullsLast()`
 * appelle `orderByRaw("$colonneQualifiee $dir NULLS LAST")` — `NULLS LAST`
 * n'a pas d'équivalent dans le Query Builder Eloquent (MySQL ne le supporte
 * pas, Laravel ne l'a donc jamais abstrait), et un ORDER BY ne peut de toute
 * façon jamais lier un IDENTIFIANT de colonne via `?` (seules les VALEURS se
 * lient). Preuve que rien n'est injectable malgré l'absence de binding :
 * `$colonneQualifiee` est un des 5 littéraux PHP choisis par le `switch` de
 * `appliquerTri()` (jamais une chaîne issue de `$request`) et `$dir` est
 * verrouillé à la ligne au-dessus à `'asc'`/`'desc'` par un simple ternaire —
 * aucune des deux variables ne peut contenir autre chose qu'une valeur fixée
 * par le code lui-même. La recherche elle-même (`appliquerRecherche()`)
 * n'utilise AUCUN SQL brut : `where('candidat.recherche_normalisee', 'ilike',
 * ...)` est un `where()` Eloquent ordinaire (même précédent qu'`AuditController`),
 * la normalisation (casse/accents/apostrophes/tirets) étant précalculée côté
 * PHP à l'écriture (`Candidat::normaliserRecherche()`, migration
 * `2026_09_19_100000_add_recherche_normalisee_to_candidat_table.php`) plutôt
 * qu'appelée en SQL (`unaccent()`) à la lecture.
 */
class PasDeSqlBrutTest extends TestCase
{
    private const MOTIFS_INTERDITS = [
        'DB::raw(',
        '->raw(',
        'whereRaw(',
        'orWhereRaw(',
        'selectRaw(',
        'orderByRaw(',
        'groupByRaw(',
        'havingRaw(',
        'fromRaw(',
        'DB::statement(',
        'DB::unprepared(',
        'DB::select(',
        'DB::insert(',
        'DB::update(',
        'DB::delete(',
    ];

    /** fichier (relatif à app/) => motifs explicitement whitelistés, avec preuve ci-dessus. */
    private const EXCEPTIONS = [
        'Domain/Export/ServiceExportAnalyse.php' => ["DB::statement('SET TRANSACTION READ ONLY')"],
        'Http/Controllers/Api/Admin/CandidatureSupervisionController.php' => [
            'orderByRaw("$colonneQualifiee $dir NULLS LAST")',
        ],
    ];

    public function test_aucune_requete_sql_brute_sous_app(): void
    {
        $racine = dirname(__DIR__, 3).'/app';
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, RecursiveDirectoryIterator::SKIP_DOTS));

        $coupables = [];
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }
            $relatif = str_replace($racine.'/', '', $fichier->getPathname());
            $code = $this->sansCommentaires(file_get_contents($fichier->getPathname()));
            foreach (self::MOTIFS_INTERDITS as $motif) {
                if (! str_contains($code, $motif)) {
                    continue;
                }
                // Ne retire QUE la chaîne exacte whitelistée (preuve ci-dessus) —
                // toute AUTRE occurrence du même motif dans ce fichier reste détectée.
                $codeSansExceptions = $code;
                foreach (self::EXCEPTIONS[$relatif] ?? [] as $exception) {
                    $codeSansExceptions = str_replace($exception, '', $codeSansExceptions);
                }
                if (str_contains($codeSansExceptions, $motif)) {
                    $coupables[] = $relatif.' → '.$motif;
                }
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Requête SQL brute détectée sous app/ — Eloquent/Query Builder uniquement (Lot 10) :\n".implode("\n", $coupables),
        );
    }

    public function test_le_scan_voit_bien_des_fichiers(): void
    {
        $racine = dirname(__DIR__, 3).'/app';
        $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $n++;
            }
        }
        $this->assertGreaterThan(80, $n, 'Le scan ne trouve pas assez de fichiers — chemin cassé ?');
    }

    private function sansCommentaires(string $code): string
    {
        // Retire /* ... */ et // ... pour ne pas piéger sur un exemple en commentaire.
        $code = preg_replace('#/\*.*?\*/#s', '', $code);

        return preg_replace('#//.*$#m', '', $code);
    }
}
