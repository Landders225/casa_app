<?php

namespace App\Services;

use App\Models\Candidature;

/**
 * Dérivation serveur du statut affichable au candidat (ADR-03).
 *
 * SEUL composant autorisé à lire `candidature.statut_interne` pour construire
 * une réponse destinée à un rôle candidat. Aucun contrôleur candidat ne doit
 * interroger `statut_interne` directement.
 *
 * Lot 3a : seul `brouillon` est atteignable (rien n'est soumis). La branche
 * « publication existe » (décision publiée) sera ajoutée au lot Publication.
 */
class StatutPublicResolver
{
    public function resoudre(Candidature $candidature): string
    {
        if ($candidature->statut_interne === 'brouillon') {
            return 'brouillon';
        }

        // soumis / en_instruction / non_eligible / evalue, sans publication :
        // statut neutre, quelle que soit la valeur interne réelle (ADR-03).
        return 'en_cours_de_traitement';
    }
}
