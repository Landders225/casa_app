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

    public function test_aucune_requete_sql_brute_sous_app(): void
    {
        $racine = dirname(__DIR__, 3).'/app';
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, RecursiveDirectoryIterator::SKIP_DOTS));

        $coupables = [];
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }
            $code = $this->sansCommentaires(file_get_contents($fichier->getPathname()));
            foreach (self::MOTIFS_INTERDITS as $motif) {
                if (str_contains($code, $motif)) {
                    $coupables[] = str_replace($racine.'/', '', $fichier->getPathname()).' → '.$motif;
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
