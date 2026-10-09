<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ExportAnalyse;
use App\Domain\Export\AnalyseExcel;
use App\Domain\Export\ServiceExportAnalyse;
use App\Models\Campagne;
use App\Models\Candidature;
use App\Models\EvaluationDossier;
use App\Models\Grille;
use App\Models\JournalAudit;
use App\Models\PieceJustificative;
use App\Models\TypeDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Evaluateur\CreeContexteEvaluation;
use Tests\TestCase;

/**
 * Lot export-analyse — `casa:export-analyse`. Lecture seule, hors zone
 * publique, chmod 600, livraison bloquée si une ville reste "À classer",
 * aucune donnée identifiante ou de score dans le résultat (cf. la feuille
 * Dictionnaire livrée dans le fichier pour le détail colonne par colonne).
 */
class ExportAnalyseCommandTest extends TestCase
{
    use CreeContexteEvaluation;
    use RefreshDatabase;

    private Campagne $campagne;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('documents');
        $this->seedReferentiels();
        $this->campagne = Campagne::ouverte()->firstOrFail();
        $this->admin = $this->creerAdmin('admin-export@casa-demo.ci');
    }

    /**
     * Candidature soumise, avec réponses au formulaire, prête à apparaître
     * dans l'export — construite directement en base (pas besoin de repasser
     * par tout le flux HTTP de soumission ici).
     *
     * @param  array<string, mixed>  $champsCandidat
     * @param  array<string, mixed>  $champsCandidature
     */
    private function candidature(array $champsCandidat = [], array $champsCandidature = [], ?string $statutInterne = 'soumis'): Candidature
    {
        $candidatUser = $this->creerCandidat('candidat-'.Str::random(12).'@casa-demo.ci', $champsCandidat);

        $candidature = Candidature::create(array_merge([
            'candidat_id' => $candidatUser->candidat->id,
            'campagne_id' => $this->campagne->id,
            'filiere_id' => $this->idFiliere('cuisine'),
            'numero_dossier' => sprintf('CASA-2026-%06d', ++$this->seq),
        ], $champsCandidature));

        // statut_interne / statut_eligibilite_interne sont volontairement
        // ABSENTS de $fillable (Candidature::create() les ignore silencieusement)
        // — affectation directe requise, comme dans CreeContexteEvaluation.
        $candidature->forceFill([
            'statut_interne' => $statutInterne,
            'statut_eligibilite_interne' => 'non_verifie',
            'date_soumission' => $statutInterne === 'brouillon' ? null : now(),
        ])->saveQuietly();

        $candidature->reponseFormulaire?->fill($this->reponsesEligibles())->save();

        return $candidature->fresh();
    }

    /**
     * Relit la feuille "Données" et renvoie une liste de lignes
     * associatives (en-tête => valeur) — ouverture RÉELLE du binaire produit.
     *
     * @return list<array<string, mixed>>
     */
    private function lignesDeDonnees(string $chemin): array
    {
        $feuille = IOFactory::load($chemin)->getSheetByName('Données');
        $enTetes = [];
        foreach ($feuille->getRowIterator(3, 3) as $ligne) {
            foreach ($ligne->getCellIterator() as $cellule) {
                $enTetes[$cellule->getColumn()] = (string) $cellule->getValue();
            }
        }

        $lignes = [];
        foreach ($feuille->getRowIterator(4) as $ligne) {
            $vide = true;
            $donnee = [];
            foreach ($ligne->getCellIterator() as $cellule) {
                $valeur = $cellule->getValue();
                if ($valeur !== null && $valeur !== '') {
                    $vide = false;
                }
                $donnee[$enTetes[$cellule->getColumn()] ?? $cellule->getColumn()] = $valeur;
            }
            if (! $vide) {
                $lignes[] = $donnee;
            }
        }

        return $lignes;
    }

    /** @return list<string> toutes les valeurs de cellules non vides, toutes feuilles. */
    private function toutesLesCellules(string $chemin): array
    {
        $valeurs = [];
        foreach (IOFactory::load($chemin)->getAllSheets() as $feuille) {
            foreach ($feuille->getRowIterator() as $ligne) {
                foreach ($ligne->getCellIterator() as $cellule) {
                    $valeur = $cellule->getValue();
                    if ($valeur !== null && $valeur !== '') {
                        $valeurs[] = (string) $valeur;
                    }
                }
            }
        }

        return $valeurs;
    }

    private function seulFichierExport(): string
    {
        $fichiers = Storage::disk('local')->allFiles('exports-analyse');
        $this->assertCount(1, $fichiers, 'Un seul fichier devrait avoir été écrit.');

        return Storage::disk('local')->path($fichiers[0]);
    }

    // --- Opérateur ----------------------------------------------------

    public function test_echoue_sans_operateur(): void
    {
        $this->artisan('casa:export-analyse')->assertExitCode(1);
        $this->assertDatabaseCount('journal_audit', 0);
    }

    public function test_echoue_si_l_email_ne_correspond_a_aucun_administrateur(): void
    {
        $this->candidature();

        $this->artisan('casa:export-analyse', ['--operateur' => 'inconnu@casa-demo.ci'])->assertExitCode(1);
        $this->assertDatabaseCount('journal_audit', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('exports-analyse'));
    }

    public function test_echoue_si_l_email_correspond_a_un_compte_non_administrateur(): void
    {
        $evaluateur = $this->creerEvaluateur('evaluateur-export@casa-demo.ci');

        $this->artisan('casa:export-analyse', ['--operateur' => $evaluateur->email])->assertExitCode(1);
        $this->assertDatabaseCount('journal_audit', 0);
    }

    // --- Blocage si ville non classée (correction #1) ------------------

    public function test_aucun_fichier_ni_aucune_livraison_si_une_ville_n_est_pas_classee(): void
    {
        $this->candidature(['ville_residence' => 'Mordor']);

        $this->artisan('casa:export-analyse', ['--operateur' => $this->admin->email])
            ->assertExitCode(1);

        $this->assertCount(0, Storage::disk('local')->allFiles('exports-analyse'));
        $this->assertDatabaseHas('journal_audit', [
            'auteur_id' => $this->admin->id,
            'resultat' => 'Échec',
        ]);
    }

    /**
     * La ville brute non classée (donnée personnelle) est affichée en
     * CONSOLE SEULEMENT (éphémère, pour que l'opérateur corrige la table de
     * correspondance) — jamais persistée dans journal_audit, qui ne garde
     * qu'un compte.
     */
    public function test_la_ville_brute_non_classee_n_apparait_pas_dans_journal_audit(): void
    {
        $this->candidature(['ville_residence' => 'Mordor']);

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);

        $entree = JournalAudit::first();
        $this->assertStringNotContainsString('Mordor', $entree->nouvelle_valeur, 'La ville brute ne doit jamais être persistée.');
        $this->assertStringContainsString('1 valeur', $entree->nouvelle_valeur, 'Un compte, lui, peut/doit être journalisé.');
    }

    public function test_le_blocage_n_affecte_pas_dry_run_non_plus(): void
    {
        $this->candidature(['ville_residence' => 'Mordor']);

        $this->artisan('casa:export-analyse', ['--operateur' => $this->admin->email, '--dry-run' => true])
            ->assertExitCode(1);

        $this->assertCount(0, Storage::disk('local')->allFiles('exports-analyse'));
    }

    // --- --dry-run : aucune donnée, aucun fichier, audit allégé --------

    public function test_dry_run_n_ecrit_aucun_fichier(): void
    {
        $this->candidature();

        $this->artisan('casa:export-analyse', ['--operateur' => $this->admin->email, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertCount(0, Storage::disk('local')->allFiles('exports-analyse'));
    }

    public function test_dry_run_ecrit_une_entree_journal_audit_allegee_sans_sha256(): void
    {
        $this->candidature();

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email, '--dry-run' => true]);

        $this->assertDatabaseCount('journal_audit', 1);
        $entree = JournalAudit::first();
        $this->assertSame($this->admin->id, $entree->auteur_id);
        $this->assertSame('Succès', $entree->resultat);
        $this->assertStringContainsStringIgnoringCase('simulation', $entree->nouvelle_valeur);
        $this->assertStringNotContainsStringIgnoringCase('sha-256', $entree->nouvelle_valeur);
        $this->assertStringNotContainsString('SHA256=', $entree->nouvelle_valeur);
    }

    public function test_dry_run_affiche_les_colonnes_et_le_controle_de_coherence_sans_valeur(): void
    {
        $this->candidature();

        $sortie = $this->artisan('casa:export-analyse', ['--operateur' => $this->admin->email, '--dry-run' => true])
            ->assertExitCode(0);

        $sortie->expectsOutputToContain('colonne(s)');
        $sortie->expectsOutputToContain('Contrôle de cohérence');
    }

    // --- Exécution réelle : fichier, droits, SHA-256, audit complet ----

    public function test_execution_reelle_ecrit_un_fichier_chmod_600_hors_zone_publique(): void
    {
        $this->candidature();

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);

        $chemin = $this->seulFichierExport();
        $this->assertFileExists($chemin);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($chemin)), -4));
    }

    public function test_execution_reelle_ecrit_une_entree_journal_audit_avec_sha256(): void
    {
        $this->candidature();

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);

        $this->assertDatabaseCount('journal_audit', 1);
        $entree = JournalAudit::first();
        $this->assertSame('Succès', $entree->resultat);
        $this->assertStringContainsString('SHA-256=', $entree->nouvelle_valeur);

        $chemin = $this->seulFichierExport();
        preg_match('/SHA-256=([0-9a-f]{64})/', $entree->nouvelle_valeur, $m);
        $this->assertSame(hash_file('sha256', $chemin), $m[1] ?? null);
    }

    // --- Exclusion stricte des données identifiantes/scores -------------

    public function test_aucune_donnee_personnelle_ni_aucun_score_dans_le_fichier_produit(): void
    {
        $candidature = $this->candidature([
            'prenom' => 'Prenomunique',
            'nom' => 'Nomunique',
            'cni' => 'CI999999999',
            'numero_cmu' => 'CMU999999999',
            'telephone' => '0712345678',
        ]);
        $candidature->candidat->utilisateur->update(['email' => 'secret-identifiable@casa-demo.ci']);

        $this->seedBareme();
        EvaluationDossier::create([
            'candidature_id' => $candidature->id,
            'grille_id' => Grille::active()->id,
            'score_total' => '45.0',
            'valide' => true,
        ]);

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $chemin = $this->seulFichierExport();
        $cellules = $this->toutesLesCellules($chemin);
        $toutesLesCellules = implode('|', $cellules);

        // Valeurs qui ne peuvent QUE résulter d'une fuite si elles apparaissent
        // en SOUS-CHAÎNE (texte libre, identifiants longs — pas de collision
        // possible par hasard).
        foreach ([
            'Prenomunique', 'Nomunique', 'CI999999999', 'CMU999999999', '0712345678',
            'secret-identifiable@casa-demo.ci', $candidature->numero_dossier, $candidature->id,
        ] as $valeurExclue) {
            $this->assertStringNotContainsString((string) $valeurExclue, $toutesLesCellules, "« {$valeurExclue} » ne doit JAMAIS apparaître dans l'export.");
        }

        // Le score (45.0) est un nombre court : une recherche en sous-chaîne
        // donnerait un faux positif (ex. "4457b7d6" dans un id_pseudonyme). On
        // vérifie plutôt qu'AUCUNE cellule n'est EXACTEMENT ce score.
        $this->assertNotContains('45.0', $cellules);
        $this->assertNotContains('45', $cellules);
        $this->assertNotContains(45.0, $cellules);
    }

    // --- Périmètre : soumis uniquement par défaut (constat production) --
    // Même définition que le tableau de bord admin : Candidature::scopeSoumises()
    // (statut_interne != 'brouillon'), réutilisée telle quelle, jamais dupliquée.

    public function test_par_defaut_les_brouillons_sont_exclus(): void
    {
        $this->candidature(statutInterne: 'brouillon');
        $this->candidature(statutInterne: 'soumis');

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $etats = array_column($lignes, 'etat_dossier');
        $this->assertNotContains('brouillon', $etats, json_encode($lignes));
        $this->assertContains('soumis', $etats, json_encode($lignes));
        $this->assertCount(1, $lignes, 'Seule la candidature soumise doit être exportée.');
    }

    public function test_option_inclure_brouillons_retrouve_l_ancien_perimetre(): void
    {
        $this->candidature(statutInterne: 'brouillon');
        $this->candidature(statutInterne: 'soumis');

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email, '--inclure-brouillons' => true]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $etats = array_column($lignes, 'etat_dossier');
        $this->assertContains('brouillon', $etats, json_encode($lignes));
        $this->assertContains('soumis', $etats, json_encode($lignes));
        $this->assertCount(2, $lignes);
    }

    /** Le masquage k=5 (ville/zone) se calcule sur le périmètre FILTRÉ, pas sur le total brut. */
    public function test_le_masquage_se_calcule_sur_le_perimetre_filtre(): void
    {
        // 6 brouillons "Bouaké" (exclus) + seulement 2 candidatures soumises
        // "Bouaké" : si le masquage comptait les brouillons, 8 >= seuil et
        // "Bouaké" resterait visible à tort ; sur le périmètre réel (2 < 5),
        // elle doit être masquée en "Autre ville".
        for ($i = 0; $i < 6; $i++) {
            $this->candidature(['ville_residence' => 'Bouaké'], statutInterne: 'brouillon');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->candidature(['ville_residence' => 'Bouaké'], statutInterne: 'soumis');
        }

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $this->assertCount(2, $lignes);
        foreach ($lignes as $ligne) {
            $this->assertSame('Autre ville', $ligne['ville_normalisee'], 'Masqué : 2 < 5 sur le périmètre réellement exporté (brouillons exclus du comptage).');
        }
    }

    /** Le périmètre utilisé est affiché dans la sortie, --dry-run compris. */
    public function test_le_perimetre_est_affiche_dans_la_sortie(): void
    {
        $this->candidature(statutInterne: 'soumis');

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $this->assertStringContainsString('Périmètre : Candidatures soumises uniquement', Artisan::output());

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email, '--dry-run' => true]);
        $this->assertStringContainsString('Périmètre : Candidatures soumises uniquement', Artisan::output());

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email, '--inclure-brouillons' => true]);
        $this->assertStringContainsString('Périmètre : Toutes les candidatures, brouillons compris', Artisan::output());
    }

    /** La feuille Synthèse indique le nombre de brouillons exclus (masqué si < 5). */
    public function test_synthese_indique_les_brouillons_exclus(): void
    {
        $this->candidature(statutInterne: 'soumis');
        for ($i = 0; $i < 2; $i++) {
            $this->candidature(statutInterne: 'brouillon');
        }

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $feuille = IOFactory::load($this->seulFichierExport())->getSheetByName('Synthèse');

        $paires = [];
        foreach ($feuille->getRowIterator() as $ligne) {
            $a = $feuille->getCell('A'.$ligne->getRowIndex())->getValue();
            $b = $feuille->getCell('B'.$ligne->getRowIndex())->getValue();
            if ($a !== null && $a !== '') {
                $paires[(string) $a] = $b;
            }
        }

        $this->assertSame('<5', $paires['Exclus de cet export'] ?? null, '2 brouillons exclus < seuil : masqué.');
    }

    // --- Masquage k-anonymat au niveau ligne (correction #2) ------------

    public function test_commune_sous_le_seuil_est_masquee_en_autre_commune(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->candidature(['ville_residence' => 'Abidjan-Marcory']);
        }

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        foreach ($lignes as $ligne) {
            $this->assertSame('Autre commune', $ligne['commune_abidjan']);
            $this->assertSame('Abidjan', $ligne['zone']);
        }
    }

    public function test_commune_au_dessus_du_seuil_est_nommee(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->candidature(['ville_residence' => 'Abidjan-Cocody']);
        }

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        foreach ($lignes as $ligne) {
            $this->assertSame('Cocody', $ligne['commune_abidjan']);
        }
    }

    public function test_ville_interieur_sous_le_seuil_est_masquee_en_autre_ville(): void
    {
        $this->candidature(['ville_residence' => 'Bouaké']);

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $this->assertSame('Autre ville', $lignes[0]['ville_normalisee']);
        $this->assertSame('Intérieur', $lignes[0]['zone']);
    }

    // --- piece_<code>_presente généré depuis type_document EN BASE ------

    public function test_colonnes_piece_generees_depuis_type_document_en_base(): void
    {
        $this->candidature();

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email, '--dry-run' => true]);
        $sortie = Artisan::output();

        foreach (TypeDocument::pluck('code') as $code) {
            $this->assertStringContainsString("piece_{$code}_presente", $sortie);
        }
    }

    public function test_nb_pieces_dossier_distinct_de_nb_justificatifs_experience(): void
    {
        $candidature = $this->candidature();

        PieceJustificative::create([
            'candidature_id' => $candidature->id,
            'type_document_code' => 'cni',
            'rattachement' => 'dossier',
            'nom_original' => 'cni.pdf',
            'chemin_stockage' => $candidature->id.'/a.pdf',
            'taille_octets' => 100,
            'type_mime' => 'application/pdf',
            'depose_le' => now(),
        ]);
        PieceJustificative::create([
            'candidature_id' => $candidature->id,
            'type_document_code' => 'cv',
            'rattachement' => 'dossier',
            'nom_original' => 'cv.pdf',
            'chemin_stockage' => $candidature->id.'/b.pdf',
            'taille_octets' => 100,
            'type_mime' => 'application/pdf',
            'depose_le' => now(),
        ]);

        $pieceExperience = PieceJustificative::create([
            'rattachement' => 'experience',
            'nom_original' => 'justif.pdf',
            'chemin_stockage' => $candidature->id.'/c.pdf',
            'taille_octets' => 100,
            'type_mime' => 'application/pdf',
            'depose_le' => now(),
        ]);
        // candidature_id/piece_justificative_id ne sont pas dans $fillable :
        // création via la relation (fixe candidature_id), puis forceFill.
        $experience = $candidature->experiences()->create([
            'domaine' => 'hotellerie',
            'duree_categorie' => '6_12',
        ]);
        $experience->forceFill(['piece_justificative_id' => $pieceExperience->id])->save();

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $this->assertSame(2, (int) $lignes[0]['nb_pieces_dossier']);
        $this->assertSame(1, (int) $lignes[0]['nb_justificatifs_experience']);
        $this->assertSame(1, (int) $lignes[0]['exp_hotellerie_nb']);
        $this->assertSame('6_12', $lignes[0]['exp_hotellerie_duree_max']);
        $this->assertSame('oui', $lignes[0]['piece_cni_presente']);
        $this->assertSame('oui', $lignes[0]['piece_cv_presente']);
        $this->assertSame('non', $lignes[0]['piece_photo_presente']);
    }

    // --- tranche_age calculée à campagne.date_ouverture, pas à now() ----

    public function test_tranche_age_utilise_la_date_d_ouverture_de_la_campagne_pas_aujourd_hui(): void
    {
        $this->campagne->forceFill(['date_ouverture' => '2020-06-15'])->save();
        // Né le 2000-06-15 : 20 ans à l'ouverture de campagne (2020), pas
        // l'âge "aujourd'hui" si le test tournait des années plus tard.
        Carbon::setTestNow('2026-10-08');

        $this->candidature(['date_naissance' => '2000-06-15']);

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $this->assertSame('18-20', $lignes[0]['tranche_age']);

        Carbon::setTestNow();
    }

    // --- Synthèse : jamais de total à côté d'une cellule masquée --------

    /**
     * Lit le bloc "Zone" de la feuille Synthèse (entre le titre "Zone" et le
     * titre "Filière" qui suit) sous forme de paires libellé => valeur.
     *
     * @return array<string, int|string>
     */
    private function blocZoneDeLaSynthese(string $chemin): array
    {
        $feuille = IOFactory::load($chemin)->getSheetByName('Synthèse');

        $paires = [];
        foreach ($feuille->getRowIterator() as $ligne) {
            $a = $feuille->getCell('A'.$ligne->getRowIndex())->getValue();
            $b = $feuille->getCell('B'.$ligne->getRowIndex())->getValue();
            if ($a !== null && $a !== '') {
                $paires[] = [(string) $a, $b];
            }
        }

        $debut = null;
        $fin = null;
        foreach ($paires as $i => [$libelle]) {
            if ($libelle === 'Zone' && $debut === null) {
                $debut = $i;
            } elseif ($libelle === 'Filière' && $debut !== null && $fin === null) {
                $fin = $i;
            }
        }

        $parLibelle = [];
        foreach (array_slice($paires, $debut, $fin - $debut) as [$libelle, $valeur]) {
            $parLibelle[$libelle] = $valeur;
        }

        return $parLibelle;
    }

    // --- Fusion « Hors Abidjan » (ADR-38) — valeurs fictives uniquement -

    /**
     * Cas principal : la zone rare (1 ligne, sous le seuil) est fusionnée
     * DANS le groupe Intérieur (6 lignes, au-dessus du seuil) — le groupe
     * fusionné (7 lignes) n'est donc jamais lui-même sous le seuil.
     */
    public function test_zone_rare_est_fusionnee_dans_un_groupe_au_dessus_du_seuil(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->candidature(['ville_residence' => 'Bouaké']); // Intérieur, 6 >= seuil
        }
        $this->candidature(['ville_residence' => 'Maroc']); // zone Étranger, 1 < seuil

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $zones = array_unique(array_column($lignes, 'zone'));
        $this->assertSame(['Hors Abidjan'], $zones, 'Zone rare ET Intérieur doivent toutes deux être relabellisées « Hors Abidjan » — jamais « Étranger » ni « Intérieur » isolément.');

        $villesNormalisees = array_column($lignes, 'ville_normalisee');
        $this->assertCount(1, array_filter($villesNormalisees, fn ($v) => $v === 'Autre ville'), 'La ligne fusionnée (ex-Étranger) doit porter « Autre ville ».');
        // Les 6 lignes Bouaké (>= seuil) gardent leur propre nom de ville —
        // la fusion de zone n'affecte pas le masquage PAR VILLE, orthogonal.
        $this->assertCount(6, array_filter($villesNormalisees, fn ($v) => $v === 'Bouaké'));

        // Dans Synthèse, "Hors Abidjan" porte le total fusionné (7) — jamais
        // "Étranger" ni "Intérieur" séparément.
        $blocZone = $this->blocZoneDeLaSynthese($this->seulFichierExport());
        $this->assertArrayNotHasKey('Étranger', $blocZone);
        $this->assertArrayNotHasKey('Intérieur', $blocZone);
        $this->assertSame(7, $blocZone['Hors Abidjan'] ?? null);
    }

    /** Sans aucune zone rare, Intérieur n'est jamais renommé. */
    public function test_sans_zone_rare_interieur_reste_interieur(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->candidature(['ville_residence' => 'Bouaké']);
        }

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $this->assertSame(['Intérieur'], array_unique(array_column($lignes, 'zone')));
    }

    /**
     * Cas limite : le groupe Intérieur + zones rares combiné reste LUI-MÊME
     * sous le seuil (ici, aucune ligne Intérieur du tout : 0 + 1 < seuil) —
     * la fusion s'étend alors aussi à Abidjan, pour TOUTES les lignes.
     */
    public function test_cas_limite_fusion_etendue_a_abidjan(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->candidature(['ville_residence' => 'Abidjan']);
        }
        $this->candidature(['ville_residence' => 'Maroc']); // zone Étranger, 1 < seuil ; 0 ligne Intérieur

        Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);
        $lignes = $this->lignesDeDonnees($this->seulFichierExport());

        $zones = array_unique(array_column($lignes, 'zone'));
        $this->assertSame(['Non précisée / Autre'], $zones, 'Même les lignes Abidjan doivent basculer : le groupe Intérieur+rare (1) reste sous le seuil.');

        $villesNormalisees = array_unique(array_column($lignes, 'ville_normalisee'));
        $this->assertSame(['Non précisée / Autre'], $villesNormalisees);

        // Cellule vide : PhpSpreadsheet relit une chaîne vide comme `null`.
        $communes = array_unique(array_map(fn ($v) => $v ?? '', array_column($lignes, 'commune_abidjan')));
        $this->assertSame([''], $communes, 'Plus aucune commune individualisée une fois la fusion totale déclenchée.');
    }

    // --- Coherence --dry-run (correction #4) ----------------------------

    public function test_controle_de_coherence_detecte_les_incoherences_zone_residence_ci(): void
    {
        // Ville à l'étranger, mais résidence_ci = true : incohérence.
        $this->candidature(['ville_residence' => 'Maroc', 'residence_ci' => true]);
        // Ville en Côte d'Ivoire (Abidjan), mais résidence_ci = false : incohérence.
        $this->candidature(['ville_residence' => 'Abidjan', 'residence_ci' => false]);

        $sortie = $this->artisan('casa:export-analyse', ['--operateur' => $this->admin->email, '--dry-run' => true])
            ->assertExitCode(0);

        $sortie->expectsOutputToContain('1');
    }

    // --- GardeFuite : dernier filet après écriture réelle ---------------

    /**
     * Simule une fuite qui aurait échappé à la construction normale des
     * lignes (ex. régression future) : on substitue à AnalyseExcel une
     * version qui plante volontairement le CNI du candidat dans une
     * cellule. Le garde-fou doit la détecter en relisant le VRAI fichier
     * écrit sur disque (pas la structure en mémoire).
     */
    public function test_garde_fuite_supprime_le_fichier_et_sort_en_echec_si_un_identifiant_fuit(): void
    {
        $candidature = $this->candidature();
        $cni = $candidature->candidat->cni;

        // AnalyseExcel qui plante volontairement le CNI dans une cellule —
        // simule une fuite qui aurait échappé à la construction normale des
        // lignes (ex. régression future), sans affaiblir le vrai générateur.
        $excel = new class extends AnalyseExcel
        {
            public string $valeurAInjecter = '';

            public function generer(array $resultat): string
            {
                $octets = parent::generer($resultat);

                $chemin = tempnam(sys_get_temp_dir(), 'casa_fuite_src_');
                file_put_contents($chemin, $octets);
                $classeur = IOFactory::load($chemin);
                // Cellule hors des 60 colonnes réelles (donc pas une
                // collision avec une colonne existante), mais modeste : une
                // cellule lointaine (ex. "ZZ999") gonflerait la dimension de
                // la feuille à ~700k cellules et épuiserait la mémoire à la
                // relecture — ce n'est pas ce qu'on veut tester ici.
                $classeur->getSheetByName('Données')->setCellValue('CA10', $this->valeurAInjecter);

                $writer = new Xlsx($classeur);
                ob_start();
                $writer->save('php://output');
                @unlink($chemin);

                return (string) ob_get_clean();
            }
        };
        $excel->valeurAInjecter = $cni;

        // Construction DIRECTE de la commande (pas Artisan::call) : les
        // commandes sont résolues et mises en cache par le noyau console dès
        // le premier appel Artisan de ce test (ici, celui fait par
        // seedReferentiels() dans setUp(), AVANT qu'on puisse rebinder
        // AnalyseExcel) — un rebind après coup via $this->app->bind() ne
        // serait donc jamais pris en compte par cette instance déjà en cache.
        $command = new ExportAnalyse($this->app->make(ServiceExportAnalyse::class), $excel);
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $code = $command->run(new ArrayInput(['--operateur' => $this->admin->email]), $output);
        $sortie = $output->fetch();

        $this->assertNotSame(0, $code, 'La commande doit sortir en échec quand une fuite est détectée.');
        $this->assertCount(0, Storage::disk('local')->allFiles('exports-analyse'), 'Le fichier contenant la fuite doit être supprimé.');
        $this->assertStringNotContainsString($cni, $sortie, "Le CNI ne doit JAMAIS apparaître dans le message d'erreur.");
        $this->assertStringContainsString('cni', $sortie, 'La catégorie (sans la valeur), elle, peut/doit être mentionnée.');

        $this->assertDatabaseCount('journal_audit', 1);
        $entree = JournalAudit::first();
        $this->assertSame('Échec', $entree->resultat);
        $this->assertStringNotContainsString($cni, $entree->nouvelle_valeur, 'Le CNI ne doit JAMAIS apparaître dans journal_audit.');
        $this->assertStringContainsString('cni', $entree->nouvelle_valeur);
    }

    /**
     * Test négatif : exécution normale, SANS fuite injectée — le fichier
     * doit être conservé (le garde-fou ne doit jamais supprimer un fichier
     * sain).
     */
    public function test_garde_fuite_conserve_le_fichier_quand_il_n_y_a_aucune_fuite(): void
    {
        $this->candidature();

        $code = Artisan::call('casa:export-analyse', ['--operateur' => $this->admin->email]);

        $this->assertSame(0, $code);
        $this->assertCount(1, Storage::disk('local')->allFiles('exports-analyse'), 'Sans fuite, le fichier doit être conservé.');
        $this->assertDatabaseHas('journal_audit', ['resultat' => 'Succès']);
    }
}
