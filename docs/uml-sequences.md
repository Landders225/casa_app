# CASA — Diagrammes de séquence (UML)

Lot 0 — Conception. Format Mermaid `sequenceDiagram` (texte-versionnable). Ces 3 séquences illustrent les workflows critiques et servent de spécification aux endpoints des lots d'implémentation ultérieurs — **aucun code n'est produit dans ce lot.**

## (a) Soumission d'une candidature + calcul d'éligibilité côté serveur

Illustre la règle 3 (logique OR sites) et la règle de communication différée dès la soumission (le candidat n'apprend jamais directement "non éligible").

```mermaid
sequenceDiagram
    actor C as Candidat
    participant F as Frontend (React SPA)
    participant A as API Laravel
    participant E as ServiceEligibilite (serveur)
    participant DB as PostgreSQL

    C->>F: Étape 10/10 — "Soumettre ma candidature"
    F->>A: POST /api/candidatures/{id}/soumettre
    A->>A: Policy : candidat propriétaire, statut_interne = brouillon
    A->>DB: SELECT reponse_formulaire, experience_professionnelle
    DB-->>A: réponses brutes
    A->>E: checkCriteresEliminatoires(reponses)
    Note over E: Logique versionnée côté serveur (règle 3 : OR sur<br/>acces_plateau / acces_deux_plateaux_vallons, etc.)<br/>— PAS un moteur de règles générique (décision validée).

    alt Critère(s) éliminatoire(s) déclenché(s)
        E-->>A: { eliminatoire: true, criteres: [...] }
        A->>DB: INSERT critere_eliminatoire_declenche (1..n lignes)
        A->>DB: UPDATE candidature SET statut_interne='non_eligible',<br/>statut_eligibilite_interne='non_eligible'
    else Aucun critère déclenché
        E-->>A: { eliminatoire: false }
        A->>DB: UPDATE candidature SET statut_interne='soumis',<br/>statut_eligibilite_interne='eligible', date_soumission=now()
    end

    A->>DB: INSERT journal_audit (action="Soumission de candidature")
    A-->>F: 200 { numero_dossier, statut_public: "en_cours_de_traitement" }
    Note over A,F: Le corps de la réponse est **identique** dans les deux<br/>branches ci-dessus : statut_interne réel, motifs<br/>d'élimination et score ne sont JAMAIS renvoyés ici.
    F-->>C: "Candidature n° CASA-2026-XXXXXX soumise.<br/>Vous recevrez une notification à chaque étape."
```

## (b) Validation/verrouillage d'une évaluation + correction exceptionnelle admin

Illustre les règles 4 (verrouillage réel) et 5 (audit persistant).

```mermaid
sequenceDiagram
    actor Ev as Évaluateur
    actor Ad as Administrateur
    participant F as Frontend
    participant A as API Laravel
    participant S as ServiceScoring (serveur)
    participant DB as PostgreSQL

    rect rgb(235, 245, 242)
    Note over Ev,DB: Évaluation initiale
    Ev->>F: Ouvre l'écran d'évaluation du dossier
    F->>A: GET /api/candidatures/{id}/evaluation
    A->>A: Policy : role ∈ {evaluateur, administrateur}
    A-->>F: réponses formulaire + grille active + score courant (brouillon)

    Ev->>F: Ajuste vérification (nationalité, diplôme) + notation qualitative
    F->>A: PATCH /api/candidatures/{id}/evaluation (brouillon)
    A->>DB: UPDATE verification_dossier, reponse_formulaire.mo04_note_etoiles
    A->>S: computeScores(candidature, grille_active)
    S-->>A: score détaillé par rubrique (non persisté tant que non validé)
    A-->>F: score recalculé (aperçu)

    Ev->>F: "Valider définitivement"
    F->>A: POST /api/candidatures/{id}/evaluation/valider
    A->>A: Policy : dossier_verrouille = false
    A->>S: computeScores (snapshot final)
    S-->>A: score_total /65 + score par rubrique
    A->>DB: INSERT evaluation_dossier (valide=true, score_total, grille_id snapshot)
    A->>DB: INSERT score_rubrique_dossier (6 lignes)
    A->>DB: UPDATE candidature SET dossier_verrouille=true,<br/>dossier_verrouille_par=Ev, statut_interne='evalue'
    A->>DB: INSERT journal_audit (action="Validation d'évaluation")
    A-->>F: 200 "Évaluation validée et verrouillée"
    end

    rect rgb(251, 240, 218)
    Note over Ev,DB: Tentative de modification après verrouillage
    Ev->>F: Tente de rouvrir le dossier
    F->>A: PATCH /api/candidatures/{id}/evaluation
    A->>A: Policy : dossier_verrouille = true ET role = evaluateur
    A-->>F: 403 Forbidden — "Dossier verrouillé, seul un administrateur peut corriger"
    end

    rect rgb(251, 230, 230)
    Note over Ad,DB: Correction exceptionnelle (admin uniquement)
    Ad->>F: "Correction exceptionnelle" + saisie du motif (obligatoire)
    F->>A: POST /api/candidatures/{id}/evaluation/correction { motif }
    A->>A: Policy : role = administrateur STRICTEMENT
    A->>DB: SELECT evaluation_dossier.score_total (ancienne valeur)
    Ad->>F: Modifie une réponse, revalide
    F->>A: POST /api/candidatures/{id}/evaluation/valider (mode correction)
    A->>S: computeScores (nouveau snapshot)
    A->>DB: UPDATE evaluation_dossier, score_rubrique_dossier
    A->>DB: INSERT journal_audit (action="Correction exceptionnelle",<br/>ancienne_valeur, nouvelle_valeur, motif, auteur=Ad)
    A-->>F: 200 "Correction enregistrée"
    end
```

## (c) Publication des résultats — ce que voit le candidat avant / après

Illustre la règle 1 (confidentialité côté serveur) et la règle 2 (communication différée). Le point clé : **un unique composant serveur (`StatutPublicResolver`) est le seul chemin de lecture autorisé pour un rôle candidat** — jamais un accès direct à `statut_interne`/`decision_candidature`.

```mermaid
sequenceDiagram
    actor Ad as Administrateur
    actor Ca as Candidat
    participant A as API Laravel
    participant R as StatutPublicResolver (serveur)
    participant Cl as ServiceClassement (serveur)
    participant DB as PostgreSQL

    rect rgb(235, 245, 242)
    Note over Ca,DB: AVANT publication (à tout moment après soumission)
    Ca->>A: GET /api/mes-candidatures/{id}
    A->>A: Policy : candidat propriétaire
    A->>R: resoudreStatutPublic(candidature)
    R->>DB: SELECT candidature.statut_interne, campagne.publication
    DB-->>R: statut_interne = 'evalue' (ex.), publication = NULL
    Note over R: publication absente ⇒ statut_public FIGÉ à<br/>"en_cours_de_traitement", quelle que soit la valeur<br/>réelle de statut_interne (même 'non_eligible').
    R-->>A: { statut_public: "en_cours_de_traitement", decision: null, motif: null }
    A-->>Ca: 200 { statut_public: "en_cours_de_traitement" }
    Note over A,Ca: score, grille, rang, classement, motif_interne :<br/>jamais chargés pour cette requête (règle 1).
    end

    rect rgb(230, 240, 251)
    Note over Ad,DB: Publication (administrateur)
    Ad->>A: GET /api/campagnes/{id}/classement (aperçu, lecture seule)
    A->>Cl: classer(campagne, toutes filières)
    Cl-->>A: classement par filière (score final, rang, décision proposée)
    Ad->>A: POST /api/campagnes/{id}/publier
    A->>A: Policy : role = administrateur
    A->>Cl: classer (calcul définitif)
    A->>DB: INSERT/UPDATE decision_candidature (rang, decision) — pour chaque candidature classée
    A->>DB: INSERT publication (campagne_id, publiee_le, publiee_par=Ad)
    A->>DB: INSERT journal_audit (action="Publication des résultats")
    A-->>Ad: 200 "Résultats publiés"
    end

    rect rgb(225, 245, 235)
    Note over Ca,DB: APRÈS publication
    Ca->>A: GET /api/mes-candidatures/{id}
    A->>R: resoudreStatutPublic(candidature)
    R->>DB: SELECT campagne.publication (existe), decision_candidature
    DB-->>R: decision='retenu', motif_communicable=NULL, rang=3 (interne)
    Note over R: publication existe ⇒ decision + motif_communicable<br/>(ou message générique fixe si NULL) exposés.<br/>rang et motif_interne : jamais lus par ce chemin.
    R-->>A: { statut_public: "decision_publiee", decision: "retenu", motif: null }
    A-->>Ca: 200 { statut_public: "decision_publiee", decision: "retenu" }
    Ca->>Ca: Affiche "Félicitations, votre candidature est retenue !"
    end
```

## Principe transverse illustré par (c)

`StatutPublicResolver` (ou équivalent — Laravel API Resource dédiée, non contournable) est le **seul** point du code serveur autorisé à lire `candidature.statut_interne` et `decision_candidature` pour construire une réponse destinée à un rôle `candidat`. Aucun contrôleur candidat ne doit interroger ces colonnes directement. C'est la traduction opérationnelle de la règle 1 (confidentialité) et de l'exigence 2 (statut interne ≠ statut affiché, dès le modèle) — détaillée comme décision d'architecture dans `docs/ADR.md`.
