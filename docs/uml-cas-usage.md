# CASA — Diagrammes de cas d'usage (UML)

Lot 0 — Conception. Format PlantUML (texte-versionnable). Un diagramme par rôle ; `Administrateur` inclut par hérédité tous les cas d'usage d'`Évaluateur` (règle validée : admin ⊇ évaluateur), plus ses cas propres.

## Candidat

```plantuml
@startuml cas-usage-candidat
left to right direction
actor Candidat

rectangle "Espace candidat" {
  usecase "Créer un compte" as UC1
  usecase "Se connecter / se déconnecter" as UC2
  usecase "Remplir le formulaire de candidature" as UC3
  usecase "Confirmer la filière choisie" as UC3a
  usecase "Déclarer une expérience professionnelle" as UC3b
  usecase "Déposer une pièce justificative" as UC3c
  usecase "Classer ses filières par préférence" as UC3d
  usecase "Enregistrer un brouillon" as UC4
  usecase "Soumettre sa candidature" as UC5
  usecase "Consulter le statut de sa candidature" as UC6
  usecase "Consulter le résultat (après publication)" as UC7
  usecase "Gérer ses documents" as UC8
  usecase "Modifier ses coordonnées" as UC9
  usecase "Consulter l'aide / FAQ" as UC10
}

Candidat --> UC1
Candidat --> UC2
Candidat --> UC3
UC3 ..> UC3a : <<include>>
UC3 ..> UC3b : <<include>>
UC3 ..> UC3c : <<include>>
UC3 ..> UC3d : <<include>>
Candidat --> UC4
Candidat --> UC5
Candidat --> UC6
Candidat --> UC7
Candidat --> UC8
Candidat --> UC9
Candidat --> UC10

note right of UC6
  Ne renvoie **jamais** le score, la grille, le
  classement ni un statut interne "non éligible"
  avant publication — cf. docs/ADR.md.
end note

note right of UC7
  Décision finale uniquement (Retenu / Liste
  d'attente / Non retenu / Indisponible) + motif
  communicable éventuel. Jamais le score chiffré.
end note
@enduml
```

## Évaluateur

```plantuml
@startuml cas-usage-evaluateur
left to right direction
actor "Évaluateur\n(Membre équipe)" as Evaluateur

rectangle "Espace évaluateur" {
  usecase "Se connecter / se déconnecter" as UC1
  usecase "Consulter les candidatures affectées" as UC2
  usecase "Consulter la fiche candidat" as UC3
  usecase "Vérifier le dossier (nationalité, diplôme)" as UC4
  usecase "Évaluer le dossier (grille /65)" as UC5
  usecase "Valider et verrouiller l'évaluation" as UC6
  usecase "Planifier un entretien" as UC7
  usecase "Conduire et noter l'entretien (grille /35)" as UC8
  usecase "Valider et verrouiller l'entretien" as UC9
  usecase "Consulter le classement par filière" as UC10
  usecase "Consulter les rapports & statistiques" as UC11
}

Evaluateur --> UC1
Evaluateur --> UC2
Evaluateur --> UC3
Evaluateur --> UC4
Evaluateur --> UC5
Evaluateur --> UC6
Evaluateur --> UC7
Evaluateur --> UC8
Evaluateur --> UC9
Evaluateur --> UC10
Evaluateur --> UC11

note right of UC6
  Une fois validée : verrouillée. Plus aucune
  modification possible par l'évaluateur.
end note
note right of UC10
  Lecture seule : la publication est réservée
  à l'administrateur (cf. diagramme Administrateur).
end note
@enduml
```

## Administrateur

```plantuml
@startuml cas-usage-administrateur
left to right direction
actor Administrateur
actor "Évaluateur\n(cas d'usage hérités)" as EvaluateurRef

rectangle "Espace administrateur" {
  usecase "Tous les cas d'usage Évaluateur" as UCInherited
  usecase "Gérer les campagnes (créer/ouvrir/clôturer)" as UC1
  usecase "Affecter un évaluateur à un dossier" as UC2
  usecase "Marquer un dossier éliminé manuellement" as UC3
  usecase "Effectuer une correction exceptionnelle" as UC4
  usecase "Publier les résultats d'une campagne" as UC5
  usecase "Déclarer un candidat retenu indisponible" as UC6
  usecase "Saisir un motif de non-retenue" as UC7
  usecase "Gérer les filières CQP" as UC8
  usecase "Gérer la grille d'évaluation (versions/pondérations)" as UC9
  usecase "Gérer les quotas par filière/campagne" as UC10
  usecase "Consulter le journal d'audit" as UC11
  usecase "Gérer les utilisateurs / évaluateurs" as UC12
  usecase "Gérer les paramètres généraux" as UC13
}

Administrateur --|> EvaluateurRef
Administrateur --> UCInherited
Administrateur --> UC1
Administrateur --> UC2
Administrateur --> UC3
Administrateur --> UC4
Administrateur --> UC5
Administrateur --> UC6
Administrateur --> UC7
Administrateur --> UC8
Administrateur --> UC9
Administrateur --> UC10
Administrateur --> UC11
Administrateur --> UC12
Administrateur --> UC13

note right of UC4
  Motif obligatoire. Seul cas d'usage permettant de
  rouvrir un dossier/entretien verrouillé. Tracé
  intégralement au journal d'audit (ancienne/nouvelle
  valeur).
end note
note right of UC5
  Rattachée à la campagne (pas un interrupteur
  global) — cf. décision validée, docs/ADR.md.
end note
note right of UC6
  Promeut automatiquement le premier candidat de
  la liste d'attente de la même filière.
end note
@enduml
```
