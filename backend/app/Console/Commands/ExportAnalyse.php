<?php

namespace App\Console\Commands;

use App\Domain\Export\AnalyseExcel;
use App\Domain\Export\GardeFuite;
use App\Domain\Export\ServiceExportAnalyse;
use App\Models\JournalAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * `php artisan casa:export-analyse --operateur=<email admin> [--dry-run]`
 * (Lot export-analyse).
 *
 * Produit un .xlsx d'analyse (1 ligne = 1 candidature, TOUTE donnée
 * identifiante ou de score exclue — cf. AnalyseExcel::DESCRIPTIONS et la
 * feuille Dictionnaire livrée dans le fichier) hors zone publique
 * (storage/app/private/exports-analyse), chmod 600.
 *
 * Lecture 100% en transaction PostgreSQL READ ONLY
 * ({@see ServiceExportAnalyse::construire()}) — cette commande elle-même ne
 * fait QUE : résoudre l'opérateur, décider quoi faire du résultat déjà
 * calculé (bloquer / simuler / écrire), et journaliser.
 *
 * Refus de livrer : si une ville de `candidat.ville_residence` ne peut être
 * classée par App\Domain\Export\NormalisationVille, AUCUN fichier n'est
 * écrit (`--dry-run` ou pas) — seules les valeurs brutes non classées sont
 * affichées en console pour investigation.
 */
class ExportAnalyse extends Command
{
    protected $signature = 'casa:export-analyse
        {--operateur= : E-mail d\'un compte administrateur existant (résolu en auteur du journal_audit)}
        {--dry-run : Affiche colonnes/effectifs/contrôles de cohérence, écrit une entrée journal_audit allégée, aucune sortie de données, aucun fichier}';

    protected $description = "Génère un .xlsx d'analyse administrative (candidatures pseudonymisées, agrégats masqués k-anonymat).";

    public function __construct(private readonly ServiceExportAnalyse $service, private readonly AnalyseExcel $excel)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $operateurEmail = $this->option('operateur');
        if (! is_string($operateurEmail) || $operateurEmail === '') {
            $this->error('--operateur est obligatoire (e-mail d\'un compte administrateur).');

            return self::FAILURE;
        }

        try {
            $resultat = $this->service->construire($operateurEmail);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $operateur = $resultat['operateur'];

        if ($resultat['bloque']) {
            // Valeurs brutes affichées en CONSOLE SEULEMENT (éphémère,
            // nécessaire à l'opérateur pour corriger la table de
            // correspondance) — JAMAIS persistées dans journal_audit, qui ne
            // garde qu'un compte (une ville est une donnée personnelle).
            $this->error("Livraison refusée : des villes n'ont pas pu être classées (voir ci-dessous). Aucun fichier n'a été écrit.");
            $this->table(['ville (valeur brute)', 'effectif'], collect($resultat['villes_a_classer'])
                ->map(fn (int $n, string $ville) => [$ville, $n])->values()->all());

            JournalAudit::create([
                'auteur_id' => $operateur->id,
                'role' => $operateur->role,
                'action' => 'Export analyse — refusé (villes non classées)',
                'module' => 'Export',
                'objet' => $operateur->email,
                'nouvelle_valeur' => sprintf('%d valeur(s) de ville non classée(s) (voir la sortie console de la commande)', count($resultat['villes_a_classer'])),
                'resultat' => 'Échec',
            ]);

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d colonne(s), %d ligne(s) (simulation — aucune donnée affichée, aucun fichier écrit).', count($resultat['colonnes']), count($resultat['lignes'])));
            $this->line('Colonnes : '.implode(', ', $resultat['colonnes']));
            $this->info('Contrôle de cohérence (effectifs uniquement) :');
            $this->table(
                ['contrôle', 'effectif'],
                [
                    ['zone = Étranger avec residence_ci = vrai', $resultat['coherence']['zone_etrangere_residence_ci_vrai']],
                    ['zone = Abidjan/Intérieur avec residence_ci = faux', $resultat['coherence']['zone_ci_residence_ci_faux']],
                ]
            );

            JournalAudit::create([
                'auteur_id' => $operateur->id,
                'role' => $operateur->role,
                'action' => 'Export analyse — simulation (--dry-run)',
                'module' => 'Export',
                'objet' => $operateur->email,
                'nouvelle_valeur' => sprintf('simulation ; %d colonne(s) ; %d ligne(s)', count($resultat['colonnes']), count($resultat['lignes'])),
                'resultat' => 'Succès',
            ]);

            return self::SUCCESS;
        }

        $octets = $this->excel->generer($resultat);
        $cheminRelatif = 'exports-analyse/export-analyse-'.now()->format('Ymd-His').'.xlsx';
        Storage::disk('local')->put($cheminRelatif, $octets);
        $cheminAbsolu = Storage::disk('local')->path($cheminRelatif);
        chmod($cheminAbsolu, 0600);

        // Dernier filet AVANT de déclarer un succès : relit RÉELLEMENT le
        // fichier déjà écrit et cherche une cellule EXACTEMENT égale à un
        // identifiant connu de cet export. Jamais exécuté en --dry-run (qui
        // n'écrit aucun fichier, donc rien à relire).
        $fuite = GardeFuite::detecter($cheminAbsolu, $resultat['valeurs_interdites']);
        if ($fuite !== null) {
            Storage::disk('local')->delete($cheminRelatif);

            $this->error("Fuite détectée après génération (catégorie : {$fuite['categorie']}) — fichier supprimé, aucune livraison.");

            JournalAudit::create([
                'auteur_id' => $operateur->id,
                'role' => $operateur->role,
                'action' => 'Export analyse — fuite détectée après génération, fichier supprimé',
                'module' => 'Export',
                'objet' => $operateur->email,
                'nouvelle_valeur' => "catégorie={$fuite['categorie']}",
                'resultat' => 'Échec',
            ]);

            return self::FAILURE;
        }

        $sha256 = hash('sha256', $octets);

        JournalAudit::create([
            'auteur_id' => $operateur->id,
            'role' => $operateur->role,
            'action' => 'Export analyse',
            'module' => 'Export',
            'objet' => $operateur->email,
            'nouvelle_valeur' => sprintf(
                '%d colonne(s) ; %d ligne(s) ; SHA-256=%s ; fichier=%s',
                count($resultat['colonnes']),
                count($resultat['lignes']),
                $sha256,
                $cheminRelatif
            ),
            'resultat' => 'Succès',
        ]);

        $this->info("Fichier écrit (hors zone publique, chmod 600) : {$cheminAbsolu}");
        $this->info("SHA-256 : {$sha256}");
        $this->info(sprintf('%d ligne(s), %d colonne(s).', count($resultat['lignes']), count($resultat['colonnes'])));

        return self::SUCCESS;
    }
}
