<?php

namespace App\Domain\Audit;

use App\Models\Candidature;
use App\Models\JournalAudit;
use App\Models\PieceJustificative;
use App\Models\User;

/**
 * Journalisation de la consultation (aperçu OU téléchargement direct) d'une
 * pièce par un ÉVALUATEUR/ADMINISTRATEUR (Lot 19) — jamais par le candidat
 * propriétaire, qui ne consulte que sa propre donnée.
 *
 * Volume : un évaluateur peut ouvrir la même pièce plusieurs fois pendant une
 * session de notation (vérification, puis notation, puis entretien) — tracer
 * CHAQUE ouverture noierait le journal d'audit sous des lignes de lecture,
 * sans valeur ajoutée (contrairement aux autres lignes de `journal_audit`,
 * qui tracent toutes une ÉCRITURE). DÉDUPLIQUÉ à 1 ligne par (auteur, pièce,
 * jour calendaire) — recherche best-effort (`exists()` puis `create()`), PAS
 * une contrainte d'unicité : une course très rare entre deux requêtes quasi
 * simultanées peut produire 2 lignes le même jour, accepté (ce n'est qu'un
 * journal de lecture, pas un contrôle de sécurité) plutôt que d'ajouter un
 * index d'expression (= migration) pour un cas limite sans enjeu.
 *
 * Contenu VOLONTAIREMENT minimal (🔴 jamais `nom_original`, qui peut contenir
 * une identité dans le nom de fichier déposé par le candidat) : seulement le
 * numéro de dossier (`objet`) et le type de pièce (`nouvelle_valeur`). Les
 * justificatifs d'expérience n'ont pas de `type_document_code` individuel :
 * libellé générique unique « justificatif_experience » — une dédoublonnée
 * entre plusieurs expériences du MÊME dossier le MÊME jour est acceptée (même
 * raisonnement que ci-dessus).
 */
final class JournalisationConsultationPiece
{
    public const MODULE = 'Documents';

    public const ACTION = 'Consultation pièce';

    public static function enregistrerSiNouvelle(User $auteur, PieceJustificative $piece): void
    {
        $candidatureId = $piece->candidatureIdProprietaire();
        if ($candidatureId === null) {
            return;
        }

        $numeroDossier = Candidature::query()->whereKey($candidatureId)->value('numero_dossier');
        if ($numeroDossier === null) {
            return;
        }

        $typePiece = $piece->rattachement === 'experience'
            ? 'justificatif_experience'
            : (string) $piece->type_document_code;

        $dejaConsignee = JournalAudit::query()
            ->where('auteur_id', $auteur->id)
            ->where('action', self::ACTION)
            ->where('objet', $numeroDossier)
            ->where('nouvelle_valeur', $typePiece)
            ->whereDate('horodatage', now()->toDateString())
            ->exists();

        if ($dejaConsignee) {
            return;
        }

        JournalAudit::create([
            'auteur_id' => $auteur->id,
            'role' => $auteur->role,
            'action' => self::ACTION,
            'module' => self::MODULE,
            'objet' => $numeroDossier,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => $typePiece,
            'resultat' => 'Succès',
        ]);
    }
}
