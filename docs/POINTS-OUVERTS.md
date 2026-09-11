# Points ouverts — inventaire vivant

> **Rôle de ce fichier.** Tout ce qui a été **consciemment reporté** au fil des
> ~40 lots, réuni au même endroit, avec un statut et un pointeur vers la trace
> détaillée. Il est **vivant** : on le met à jour quand un point est traité ou
> qu'un nouveau apparaît. Le **journal figé** des décisions reste `docs/ADR.md`
> (on n'y réécrit pas l'histoire) ; ici on tient le **présent**.
>
> Dernière revue : **revue espace admin** (2026-09-11) — confrontation
> maquette/backend/écran sur Évaluateurs-Utilisateurs, Filières, Quotas,
> Grille d'évaluation, Notifications (flux d'activité + modèles d'e-mail) et
> Paramètres. Filières/quotas/campagne éclatés en 3 lignes distinctes
> (D-6a-2) ; grille d'évaluation et modèles d'e-mail tracés pour la première
> fois, tous deux avec garde-fou explicite (barème 🔴 ADR-02, indiscernabilité
> ADR-33). Avant : **Lot 12c** (historique in-app des notifications candidat,
> extension ADR-33) ; **Lot 12b** (les 4 mails métier, ADR-33) ; **Lot 13**
> (espace candidat : profil réconcilié sur le backend, mot de passe connecté
> et « oublié », ADR-32) ; **Lot 12a** (infra d'envoi d'e-mails, ADR-31) ;
> **Lot 11c** (écran Rapports, ADR-30). Voir aussi la revue de sécurité
> **Lot 10** ([`docs/AUDIT-SECURITE.md`](AUDIT-SECURITE.md)).

## Légende de statut

| Statut | Sens |
|---|---|
| 🔴 Bloquant prod | à traiter avant une vraie mise en service auprès de candidats réels |
| 🟠 Ouvert | fonctionnalité ou durcissement attendus, non bloquants pour une première prod pilote |
| 🟡 Décidé — opt-in | tranché, volontairement non activé par défaut ; activation documentée |
| 🟢 Traité | résolu ; ligne gardée pour l'historique, avec le lot de résolution |

---

## Institutionnel / contenu

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Relecture des textes candidats par les partenaires** (FAQ publiques évaluation & égalité de score ; textes de résultat / non-retenue / clôture ; libellés de la vitrine) | 🔴 Bloquant prod | Rédaction faite côté dev pour ne rien révéler de la grille (ADR-02) ; la formulation institutionnelle doit être validée par CCI-CI / FADV / AICS | ADR sur 8b-1 et 8b-3 (README, section Avancement) ; composants `pages/public/*`, `pages/candidat/MaCandidature.jsx` | Revue partenaires, puis ajustement de chaînes uniquement |

## Fonctionnalités candidat / inscription

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Vérification d'e-mail à l'inscription** (lien ou code) | 🟠 Ouvert | Aucune infra d'envoi d'e-mail dans le projet (`MAIL_MAILER=log`) | ADR — Points ouverts (D-7-2) | Choix d'un fournisseur SMTP + file d'attente + écran de confirmation |
| **Changement d'adresse e-mail** (identifiant de connexion) | 🟠 Ouvert | Flux dédié (ré-authentification + confirmation) non couvert au Lot 7 ; `PATCH /candidat/profil` interdit `email` (ADR-07) | ADR — Points ouverts (D-7-2) | Dépend de la vérification d'e-mail ci-dessus |
| **Durcissement mot de passe — `Password::uncompromised()` (HIBP)** | 🟠 Ouvert | Écarté en v1 : appel réseau (api.pwnedpasswords.com) sur le chemin d'inscription | ADR — Points ouverts ; **AUDIT-SECURITE.md R2** | Une ligne dans `RegisterRequest` + tolérance à l'indispo du service |
| ~~Inscription / profil candidat self-service~~ | 🟢 Traité (Lot 7) | — | ADR-16 | — |
| ~~Éligibilité initiale (tranche d'âge, résidence CI) à l'inscription~~ | 🟢 Traité (Lot 7) | `ServiceEligibiliteInitiale` (ferme D-3c-1) | ADR-16 | — |

## Espace candidat

Deux entrées de `navConfig.candidat` (`Documents`, `Aide`) restent **affichées
mais inertes** (fidélité maquette depuis le Lot 8a, comme l'était l'espace
admin avant le Lot 11a). `Mon profil` est câblé depuis le Lot 13,
`Notifications` depuis le Lot 12c (détail dans la section « Notifications »
ci-dessous).

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Changement de mot de passe candidat** (en libre-service, connecté) | 🟢 **Traité (Lot 13 — ADR-32)** | `PUT /api/candidat/mot-de-passe` : mot de passe actuel vérifié, règles ADR-16, invalide les AUTRES sessions, e-mail de confirmation | **ADR-32** ; `Candidat\MotDePasseController` ; `ChangementMotDePasseCard.jsx` ; `ChangementMotDePasseTest` | — |
| **Réinitialisation « mot de passe oublié »** (non connecté) | 🟢 **Traité (Lot 13 — ADR-32)** | Mécanisme natif Laravel (`Password` broker, `password_reset_tokens`), notification française `ShouldQueue` (Lot 12a). Réponse **strictement identique** que l'e-mail existe ou non (anti-énumération à 3 niveaux : réponse / timing / throttle) ; token usage unique + expiration 60 min ; lien exclu des logs nginx | **ADR-32** ; `Auth\MotDePasseController` ; `MotDePasseOublie.jsx` + `NouveauMotDePasse.jsx` ; `MotDePasseOublieTest` + `ReinitialisationMotDePasseTest` | — |
| **Écran « Mon profil » candidat** (consulter / corriger état civil) | 🟢 **Traité (Lot 13 — ADR-32)** | Réconcilié sur le BACKEND (source d'autorité, pas la maquette) : 7 champs éditables (prénom/nom/sexe/date_naissance/cni/téléphone/ville), e-mail et résidence CI en lecture seule, pas de champ nationalité/diplôme | **ADR-32** (D-13-1) ; `Profil.jsx` + `useProfil.js` ; `ProfilCandidatTest` (Lot 7, backend inchangé) | — |
| **Verrouiller l'identité candidat après soumission de la candidature** | 🟠 Ouvert | `PATCH /candidat/profil` reste ouvert même dossier soumis — l'évaluateur lit `candidature.candidat` **en direct** (pas de snapshot), donc une correction de CNI/nom post-soumission change ce qu'il voit. Le Lot 13 ajoute un bandeau d'avertissement, sans verrouiller (décision à part) | **ADR-32** ; `Profil.jsx` (bandeau si `date_soumission` non nul) | Décider quels champs verrouiller (identité vs coordonnées) + condition sur `statut_interne` côté `MettreAJourProfilRequest` |
| **Écran « Documents » candidat** (re-consultation / re-téléchargement) | 🟠 Ouvert | Entrée de nav inerte. Après soumission, **aucun moyen de revoir ou re-télécharger** les pièces (le wizard `StepDocuments` est le seul chemin, et seulement en brouillon). Endpoints prêts : `GET /api/candidatures/{c}/pieces` + `GET /api/pieces/{p}/download` | Revue espace candidat (2026-09-10) ; `PieceController` ; maquette `pages/candidate/documents.html` | 1 écran lecture seule (liste + download), endpoints prêts. Le re-upload post-soumission « sur demande d'un évaluateur » (maquette) = fonctionnalité séparée, non couverte au backend |
| **Page « Aide » candidat** (contacts + FAQ) | 🟠 Ouvert | Entrée de nav inerte. Page **statique** (3 cartes contact + accordéon FAQ) ; contenu « démo » dans la maquette. Seul point de contact offert au candidat, référencé par d'autres textes (« contactez l'équipe depuis la page Aide ») | Revue espace candidat (2026-09-10) ; maquette `pages/candidate/aide.html` ; **recoupe le 🔴 « Relecture des textes candidats par les partenaires »** (section Institutionnel) | Composant statique (faible effort technique) — **bloqué sur le contenu validé par CCI-CI / FADV / AICS** (coordonnées réelles, réponses FAQ) |
| **Changement de mot de passe équipe** (évaluateur / admin, self-service connecté) | 🟠 Ouvert | Le Lot 13 traite le candidat seulement (périmètre volontairement restreint, Q5). L'équipe n'a que le reset PAR UN ADMIN (Lot 11b, `POST /admin/membres/{u}/mot-de-passe`) — pas de self-service | **ADR-32** (Q5) | Généraliser `PUT /api/candidat/mot-de-passe` en `PUT /api/mot-de-passe` (tout utilisateur authentifié) ou dupliquer pour l'équipe |

## Espace administrateur

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Création / édition de campagne** (dates, ouverture/clôture, filières rattachées) | 🟠 Ouvert | Aucun endpoint d'écriture ; en prod ça se fait par `tinker` / SQL (documenté `DEPLOIEMENT.md` § 11.9). Le garde-fou « une seule campagne ouverte » est déjà appliqué côté lecture | ADR — Points ouverts (**D-6a-2**) ; ADR sur 8d-1 | Endpoints CRUD + écran + validations (fenêtres de dates) |
| **Création / édition d'une filière** (nom, description, icône) | 🟠 Ouvert — **volontairement restreint** | Le bouton « Ajouter une filière » est déjà `disabled` **dans la maquette elle-même** (5 CQP prioritaires définis par le projet, pas un catalogue ouvert à étoffer) ; `Filieres.jsx` ne construit QUE le toggle actif/inactif, cohérence déjà vérifiée à l'implémentation (Lot 8d-1) | ADR — Points ouverts (**D-6a-2**) ; maquette `cqp.html` (bouton désactivé) ; `Filieres.jsx` | Endpoint `PATCH` champs + formulaire — **si** le besoin se confirme un jour, pas un manque bloquant |
| **Édition du quota par campagne** (`campagne_filiere.quota`) | 🟠 Ouvert | Écrit **uniquement par `CampagneSeeder`** aujourd'hui — aucune route ne le lit ni ne l'écrit (`ServiceClassement` le lit seul, pour le calcul retenu/liste_attente/non_retenu). Délibérément porté par l'association campagne↔filière, pas par la filière : **ADR-09** corrige une erreur de modélisation de la maquette, qui traitait le quota comme un attribut permanent de la filière | **ADR-09** ; ADR — Points ouverts (**D-6a-2**) ; maquette `quotas.html` | Endpoint d'édition (probablement rattaché à l'édition de campagne ci-dessus, pas un écran séparé) + validation (quota ≥ retenus déjà décidés) |
| **Grille d'évaluation** (paramétrage des poids/barème) | 🟠 Ouvert — **ne pas construire sans besoin avéré** | Absent de bout en bout (aucune route, aucun écran, jamais tracé avant la revue admin du 2026-09-11). Le barème est 🔴 (**ADR-02** : ne jamais révéler la grille de notation) — une édition en base crée une surface où la pondération existe en clair côté client, alors qu'aujourd'hui elle ne quitte jamais le serveur. Les scores déjà validés sont figés en snapshot (**ADR-04**) donc pas de risque rétroactif ; le vrai risque est **en cours de campagne** (deux évaluateurs notant avec des poids différents avant/après une modification) — c'est exactement ce que le verrou « grille verrouillée si campagne ouverte » de la maquette (`grille.html`) empêchait, verrou absent puisque la fonctionnalité n'existe pas | Revue admin (2026-09-11) ; **ADR-02**, **ADR-04** ; `GrilleBaremeSeeder` (Lot 1, version 1 figée) ; maquette `grille.html` | **Ne pas construire avant un vrai besoin de faire évoluer le barème.** Si construit un jour : exiger le verrou anti-modification en campagne ouverte (repris de la maquette, absent aujourd'hui) et traiter comme un **lot de sécurité à part** (le barème est 🔴) — le modèle supporte déjà le versionnement (`Grille.version`, `activerGrilleClone` dans les tests Lot 4c/8d-2), donc une nouvelle version plutôt qu'une mutation en place |
| **Flux d'activité admin** (fil d'alertes système : nouvelles candidatures, dossiers signalés non éligibles, échéances de campagne, actions d'équipe) | 🟠 Ouvert — confort, faible enjeu | Absent (aucune route, aucun écran). Toutes les données existent déjà ailleurs (`journal_audit`, `GET /admin/candidatures`, `GET /admin/campagnes`) — ce serait un écran d'agrégation, pas un nouveau sous-système | Revue admin (2026-09-11) ; maquette `notifications.html` (espace **admin** — à ne pas confondre avec la section « Notifications » candidat/mails de ce fichier) | Écran de synthèse + éventuellement un endpoint d'agrégation dédié |
| **Paramètres généraux** (nom du projet, organisme porteur, e-mail de contact, langue de l'interface) | 🟠 Ouvert — bénin | Absent — ces valeurs sont aujourd'hui des chaînes en dur côté frontend (vitrine publique), pas une donnée éditable en base | Revue admin (2026-09-11) ; maquette `parametres.html` (onglet « Général ») | Table de configuration + endpoint + écran — faible enjeu |
| **Paramètres — Sécurité & Système** (onglets maquette `parametres.html`) | 🟠 Ouvert (Sécurité) / confort (Système) | **Sécurité** = changer son propre mot de passe (admin/évaluateur connecté) — **recoupe déjà** *« Changement de mot de passe équipe »* (section Espace candidat ci-dessus, ADR-32 Q5) : pas un manque séparé, un autre point d'entrée UI possible pour la même fonctionnalité. **Système** = valeurs de supervision en lecture seule (dernière sauvegarde, version, statut des services) — faible enjeu | ADR-32 (Q5, voir « Changement de mot de passe équipe ») ; maquette `parametres.html` (onglets « Sécurité »/« Système ») | Rien pour Sécurité (dupliquerait un point déjà tracé) ; éventuel endpoint healthcheck simple pour Système |
| **Correction exceptionnelle des auto-déclarations candidat** (SC/SE/DI, langues, expériences) | 🟠 Ouvert | Le **backend l'accepte déjà** intégralement (`CorrigerDossierRequest::reponses()`) ; l'UI (Lot 8d-3) ne construit que nationalité / SC.04 diplôme / MO.04 étoiles / commentaire | **ADR-25** (point ouvert explicite) ; `CorrectionDossierModal.jsx` | UI de formulaire pilotée par la structure de la grille — pas de backend à faire |
| ~~**Export réel des rapports** (Excel / PDF)~~ → voir « Écran Rapports & statistiques » dans **Comptes & équipe** ci-dessous | — | — | — | — |

## Comptes & équipe

`Évaluateurs` + `Utilisateurs` → écran unique « Équipe » (Lot 11b, D-11b-1) ;
`Rapports` → écran « Rapports & statistiques » (Lot 11c, ADR-30). Les 4
entrées restantes de la maquette (`Quotas`, `Grille d'évaluation`,
`Notifications`, `Paramètres`) sont désormais **affichées mais inertes**
dans `navConfig.js` (même patron que `Documents`/`Aide` côté candidat,
revue admin 2026-09-11) — aucune n'a d'écran (détail ligne par ligne
ci-dessus et dans la section « Notifications »).

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Provisionner des comptes équipe en production** (évaluateurs pour constituer le jury, admins supplémentaires) | 🟢 **Traité — CLI (Lot 11a)** | `casa:create-admin` ne créait qu'un administrateur ; `ComptesDemoSeeder` (crée `evaluateur@…`) n'est jamais joué en prod → impossible de constituer un jury | **`casa:create-membre {email} --role=evaluateur\|administrateur`** ; `DEPLOIEMENT.md` § 9 ; `ProvisionnementMembreEquipe` + `CreerMembreEquipeCommand` ; `CreateMembreTest` | — |
| **Écran « Équipe »** (ex-« Utilisateurs » + « Évaluateurs » : liste évaluateurs + admins avec charge, création, activation/désactivation, réinitialisation de mot de passe) | 🟢 **Traité (Lot 11b — ADR-29)** | Onglets inertes ; seule la CLI existait. Un seul écran unifié (candidats hors périmètre, D-11b-1) | **ADR-29** ; `MembreController` + `/api/admin/membres` (4 routes) ; `GestionCompteEquipe` ; `Equipe.jsx` ; `GestionMembresEquipeTest` ; `navConfig.js` (`equipe`) | — |
| **Coupure d'accès immédiate d'un compte désactivé** (session en cours, pas seulement au prochain login) | 🟢 **Traité (Lot 11b — ADR-29)** | `login` refusait déjà un compte inactif, mais une session ouverte survivait jusqu'à `SESSION_LIFETIME` (120 min) | **ADR-29** ; `EnsureUserActif` (groupe `auth:sanctum`) ; `EnsureUserActifTest` | — |
| **Désactiver un compte CANDIDAT** (fraude avérée au-delà de l'« élimination » d'une candidature) | 🟠 Ouvert | Le Lot 11b gère l'équipe (évaluateurs + admins), pas les candidats. Différent d'« éliminer une candidature » (ADR-15) : là c'est le **compte** qu'on bloque. `EnsureUserActif` couvrirait déjà l'exécution — il manque l'acte admin | `MembreController::assertMembreEquipe` (exclut les candidats) ; ADR-15 (élimination ≠ blocage de compte) | Endpoint `role:administrateur` sur un compte candidat + point d'entrée UI (écran de supervision des candidatures ?) + audit |
| **Édition de l'identité d'un membre** (corriger prénom / nom / poste d'un compte d'équipe) | 🟠 Ouvert | Hors des 4 fonctions du Lot 11b (D-11b-4). Aujourd'hui : en base | `MembreController` (pas de route PUT d'identité) ; maquette `utilisateurs.html` (bouton « Gérer ») | 1 route `PATCH` + champs éditables + audit + un formulaire |
| **Écran « Rapports & statistiques »** (restitution CoPil : candidatures par filière, F/H, distribution des scores, top villes, décisions, présence) | 🟢 **Traité (Lot 11c — ADR-30)** | Onglet inerte jusque-là. Agrégats `role:administrateur` strict, garde-fou k-anonymat (masquage < 5, aucune cross-tab, aucune ligne individuelle), graphiques SVG maison (pas de Chart.js) | **ADR-30** ; `ServiceRapports` + `/api/admin/rapports` ; `Rapports.jsx` + `Charts.jsx` ; `RapportsStatistiquesTest` ; maquette `pages/admin/rapports.html` | — |
| **Export Excel / PDF mis en page** des rapports | 🟠 Ouvert | Le Lot 11c livre un **export CSV réel** des agrégats (même garde-fou k-anonymat que l'écran). Un vrai `.xlsx` / un PDF mis en page demandent une lib (PhpSpreadsheet / DomPDF) + une maquette de document. Les boutons sont affichés désactivés « à venir » | **ADR-30** (D-11c-4) ; `RapportController::exportCsv` ; `Rapports.jsx` (boutons Excel/PDF `disabled`) | Lib + gabarit de document + le même passage par `ServiceRapports` |
| **Indicateur « Profils vulnérables / NEET »** (comptage agrégé) | 🟠 Ouvert | KPI de la maquette retiré en v1 (D-11c-2) : c'est `vulnerabiliteScore()`, de la logique de départage/scoring — l'importer côté serveur pour un chiffre secondaire n'était pas justifié | **ADR-30** (D-11c-2) ; maquette `rapports.html` ; `ServiceClassement::departage` (logique existante) | Un comptage agrégé serveur pur dans `ServiceRapports`, si le CoPil le réclame |

## Notifications

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Infra d'envoi d'e-mails** (SMTP paramétrable + file + worker) | 🟢 **Traité (Lot 12a — ADR-31)** | ADR-27 posait « infra mail hors CASA ». Désormais : `MAIL_*` 100 % `env()` (valeurs vides dans l'exemple, garde CI) ; envoi asynchrone (`QUEUE_CONNECTION=database`) ; service Docker `worker` (`restart` + `--max-time` + healthcheck + `failed_jobs` + `queue:monitor`) ; `casa:test-email` | **ADR-31** ; `docker-compose.prod.yml` (`worker`) ; `TestEmail` (Mailable + commande) ; `DEPLOIEMENT.md` § 9 ; `TestEmailCommandTest` | — |
| **Mails métier** (accusé d'inscription, confirmation de soumission, convocation entretien, invitation à consulter les résultats) | 🟢 **Traité (Lot 12b — ADR-33)** | 4 notifications `ShouldQueue` déclenchées directement dans `RegisterController`/`SoumissionController`/`EntretienController`/`PublicationController`. Indiscernabilité PROUVÉE par test (soumission éligible/non-éligible, publication sur les 4 décisions) ; publication en volume non bloquante et sans cascade d'échec (1 job indépendant/canal/destinataire, cf. Lot 12c) ; résidu de risque du mail Entretien (corrélation structurelle à l'éligibilité) documenté et assumé | **ADR-33** ; `App\Notifications\{InscriptionConfirmee,CandidatureSoumise,EntretienPlanifie,ResultatsPublies}` | — |
| **Overlay Mailpit** (prévisualisation des templates en dev) | 🟠 Ouvert | Annoncé au Lot 12a, reporté (dev reste sur `MAIL_MAILER=log`, lecture des mails via les logs backend, cf. E2E Lot 13) | ADR-31 | `docker-compose.mail.yml` (service Mailpit, dev uniquement) |
| **SMS** (passerelle) | 🟠 Ouvert | Hors périmètre Lot 12 (e-mail seul). La maquette évoque des notifications, pas de canal SMS explicite | ADR — Points ouverts | Fournisseur SMS + un canal de notification dédié |
| **Historique in-app des notifications — candidat** (écran « Notifications ») | 🟢 **Traité (Lot 12c — extension ADR-33)** | Canal `database` natif ajouté aux 4 notifications du Lot 12b (`via() => ['mail', 'database']`), table `notifications` créée (migration `uuidMorphs`, PK `User` en UUID). `GET /candidat/notifications` (paginé) + `GET .../compteur` + `PATCH .../{id}/lue` + `POST .../marquer-tout-lu`, scope strict `role:candidat` + `$request->user()`. Indiscernabilité du contenu STOCKÉ (pas seulement du mail) prouvée pour `ResultatsPublies`. Badge non-lues sur la sidebar | **ADR-33** (addendum Lot 12c) ; `Api\Candidat\NotificationController` ; `NotificationTest` ; `Notifications.jsx` + `useNotifications.js` + `NotificationsBadge.jsx` | — |
| **Historique in-app des notifications — évaluateur/admin** | 🟠 Ouvert | Hors périmètre du Lot 12c (candidat seul, `navConfig.evaluateur`/`administrateur` n'ont d'ailleurs pas d'entrée « Notifications »). Aucune notification métier n'existe aujourd'hui pour l'équipe (affectation, etc.) | Kickoff Lot 12c (périmètre candidat) | Décider d'abord QUELS événements équipe méritent une notification, avant tout écran |
| **Modèles d'e-mail éditables** (admin — onglet « Modèles de notification » de `parametres.html` dans la maquette) | 🟠 Ouvert — **ne pas ouvrir sans garde-fou** | Absent (aucune route, aucun écran). **ADR-33 garantit l'indiscernabilité des 4 mails métier PARCE QUE leur contenu est codé en dur et testé** (comparaison byte-à-byte, Lot 12b/12c) — un template éditable en base permettrait à un admin de réintroduire une variable de décision (ex. `{decision}`) dans le mail de résultats, recréant exactement la fuite qu'ADR-33 élimine | Revue admin (2026-09-11) ; **ADR-33** ; maquette `parametres.html` (onglet « Modèles de notification ») | Si un jour nécessaire : **jamais un champ libre** — une liste de variables autorisées validées côté serveur (jamais `decision`/`score`/`motif`/`rang`), et rejouer les tests d'indiscernabilité sur le contenu ÉDITÉ, pas seulement sur le code |

## Sécurité / conformité

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **HSTS `preload`** | 🟡 Décidé — opt-in | Soumettre le domaine à hstspreload.org est engageant et lent à défaire ; on garde `max-age=1 an + includeSubDomains` par défaut, `preload` activable consciemment une fois la prod stable | **ADR-27** (point ouvert) → **ADR-28** (décision) ; commentaire dans `docker/nginx/casa.prod.conf` | Ajouter ` preload` à l'en-tête + reload nginx + soumission sur hstspreload.org |
| ~~**Matrice complète policies Laravel × endpoints** (rôle × action)~~ | 🟢 Traité (Lot 10) | `Security/MatriceAutorisationTest` introspecte la table de routage et vérifie **chaque** route `role:`-gardée × chaque mauvais rôle (403/404) + invité (401) + 403 exact sur les actes sensibles | **AUDIT-SECURITE.md** § Autorisation | — |
| ~~**Rate limiting / anti-bruteforce** au-delà du login~~ | 🟢 Traité (Lot 10) | 4 limiteurs : `casa-public` 60/min/IP, `casa-api` 120/min/user (filet global), `casa-uploads` 40/min, `casa-candidatures` 12/min. Calibrés pour ne gêner aucun usage légitime (`RateLimitingTest`) | **AUDIT-SECURITE.md** n°2 | — |
| **HSTS `preload`** — voir ci-dessus (opt-in) | 🟡 | — | — | — |
| **Politique de rétention des pièces justificatives** après clôture de campagne | 🟠 Ouvert | Les pièces restent indéfiniment dans `documents_data` ; pas de purge ni de durée légale définie | ADR — Points ouverts ; AUDIT-SECURITE.md | Décision juridique (durée) + commande de purge + sauvegarde préalable |
| **Comptes de démonstration sur la page de connexion** | 🟢 Traité (Lot 10) | Retirés du code ; absence prouvée dans `src/` (`noDemoCreds.test.js`) et dans `dist/` (CI) | **AUDIT-SECURITE.md** n°1 | — |
| **En-têtes révélant les versions PHP/nginx** | 🟢 Traité (Lot 10) | `expose_php = Off`, `server_tokens off` | **AUDIT-SECURITE.md** n°4 | — |
| **Trigger d'immuabilité du journal d'audit non testé** | 🟢 Traité (Lot 10) | `Security/JournalAuditImmuableTest` (UPDATE/DELETE/TRUNCATE rejetés) | **AUDIT-SECURITE.md** n°3 | — |
| **Vérification d'e-mail à l'inscription** | 🟠 Ouvert | — voir « Fonctionnalités candidat » ci-dessus | **AUDIT-SECURITE.md R3** | — |
| **`config:cache` et multi-réplicas** | 🟡 Décidé — mono-nœud | L'entrypoint refait les caches à chaque `up`/`--force-recreate` (ADR-28) : correct pour **un** nœud backend. Plusieurs réplicas derrière une LB demanderaient une orchestration du recreate | ADR-27 (renvoi runbook) → ADR-28 ; `docker/backend/entrypoint.sh` | Orchestrateur (Swarm/K8s) + rolling update ; hors périmètre actuel |
| ~~Cause exacte du `419 CSRF` en enchaînement `curl`~~ | 🟢 Traité (Lot 9a) | Artefact `curl` (décodage bash du token), **pas un bug d'auth** — prouvé par bisection | **ADR-26** ; `scratchpad/csrf419.sh` | — |

## Intégration continue / déploiement

| Sujet | Statut | Pourquoi ouvert | Où c'est tracé | Prérequis / effort |
|---|---|---|---|---|
| **Activer la CI** (`.github/workflows/ci.yml`, `nightly.yml`) | 🟠 Ouvert | Le dépôt n'a **pas de remote**. Les workflows sont écrits et chaque étape a été prouvée en local (Lot 9c) | **ADR-28** ; en-tête de `ci.yml` | `git remote add origin <url>` + `git push` ; GitHub exécute alors les workflows tels quels |
| **E2E d'intégration dans la CI de PR** | 🟡 Décidé — nightly | Trop long (~15 min) pour bloquer chaque PR ; tourne en `nightly.yml` (03:00 UTC) + `workflow_dispatch` | ADR-28 ; `nightly.yml` | — |

## Divers (traçabilité de conception)

| Sujet | Statut | Détail | Où c'est tracé |
|---|---|---|---|
| ~~Résidence CI (`residence_ci`)~~ | 🟢 Traité (Lot 7) | Portée à l'inscription | ADR-16 ; `docs/mld.md` |
| Règle « 1 expérience = 1 justificatif » | 🟢 Traité (Lot 3c) | Validée **à la soumission**, pas au niveau colonne (`experience_professionnelle.piece_justificative_id` nullable) | ADR — Points ouverts ; `docs/mld.md` |
