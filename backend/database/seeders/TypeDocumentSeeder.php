<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Référentiel des 7 LIGNES du dossier (table `type_document`) — les 6
 * conformes à App_maquette/assets/js/mock-data.js (DOC_TYPES), + `cmu`
 * ajouté au Lot 18 (hors maquette — demande projet, pas un portage).
 *
 * ⚠️ 7 lignes en base ≠ 7 pièces OBLIGATOIRES : `residence`/`lettre` sont
 * retirées de l'obligation au Lot D (`ContraintesFichier::TYPES_DOSSIER`
 * n'en compte plus que 5), mais leur ligne référentielle reste ICI, INCHANGÉE
 * — intégrité FK avec tout `piece_justificative` déjà déposé (on ne détruit
 * jamais un dépôt candidat), et la route d'upload/suppression continue de
 * les accepter (`TYPES_ACCEPTES`). Seul le wizard cesse de les proposer.
 *
 * `libelle` de `diplome` (Lot D) : élargi ("...ou toute autre preuve de
 * scolarité") pour couvrir plus de justificatifs recevables. Cette valeur
 * n'est PAS celle réellement affichée à l'écran (les 3 libellés frontend —
 * wizard, Documents candidat, fiche évaluateur — sont des constantes
 * dupliquées à dessein, cf. `formStructure.js`/`optionLabels.js`) ; alignée
 * ici quand même par cohérence pour un futur lecteur du code (coût nul).
 *
 * `upsert` (Lot 18) : corrige une inexactitude découverte en l'écrivant — le
 * commentaire de `SeedReferentiel::handle()` promettait déjà « idempotents :
 * firstOrCreate », mais ce seeder faisait un simple `insert`, qui aurait
 * échoué (clé dupliquée) sur une base où des lignes existent déjà. Avec
 * `upsert`, `php artisan casa:seed-referentiel --force` reste le chemin de
 * mise à niveau en PRODUCTION (`update` sur `libelle` uniquement).
 */
class TypeDocumentSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('type_document')->upsert([
            ['code' => 'cni',       'libelle' => "Carte Nationale d'Identité"],
            ['code' => 'residence', 'libelle' => 'Certificat de résidence'],
            ['code' => 'diplome',   'libelle' => 'Diplôme ou bulletin de notes ou toute autre preuve de scolarité'],
            ['code' => 'cv',        'libelle' => 'Curriculum Vitae'],
            ['code' => 'lettre',    'libelle' => 'Lettre de motivation'],
            ['code' => 'photo',     'libelle' => "Photo d'identité"],
            ['code' => 'cmu',       'libelle' => 'Couverture Maladie Universelle (CMU)'],
        ], uniqueBy: ['code'], update: ['libelle']);
    }
}
