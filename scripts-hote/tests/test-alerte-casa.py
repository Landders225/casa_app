#!/usr/bin/env python3
# =============================================================================
#  Tests de scripts-hote/alerte-casa.py — AUCUNE donnée réelle, AUCUN accès à
#  la prod. Jetons fictifs uniquement (adresses, mots de passe, empreintes).
#
#  Ne tourne PAS correctement sous Git Bash Windows (fcntl n'existe pas sur
#  Windows, les conteneurs Docker réels sont nécessaires pour les contrôles) :
#  à lancer DANS un conteneur Linux ayant accès au démon Docker de l'hôte
#  (même méthode que scripts-hote/tests/test-backup-db.sh) :
#
#    REPO="$(pwd)"
#    docker run --rm \
#      -v /var/run/docker.sock:/var/run/docker.sock \
#      -v "$REPO":"$REPO" -w "$REPO" \
#      docker:27-cli sh -c '
#        apk add --no-cache python3 openssl bash >/dev/null &&
#        python3 scripts-hote/tests/test-alerte-casa.py'
#
#  ⚠️ Même piège Docker-outside-of-Docker que test-backup-db.sh : le
#  bind-mount doit utiliser le MÊME chemin des deux côtés.
#
#  Ce que ces tests COUVRENT : parseur d'environnement (guillemets/#/$/
#  échappement), résolution du schéma SMTP (logique Laravel exacte),
#  normalisation d'empreinte, contrôle disque (fonction pure), témoin de
#  sauvegarde, conteneurs réels (jetables), docker inaccessible, anti-bruit
#  complet (nouveau -> silence -> rappel 24h -> résolu) avec temps simulé,
#  échec d'envoi -> aucun état marqué, rapport hebdomadaire, verrou,
#  non-fuite du secret dans toute sortie, et le PINNING de certificat (empreinte
#  correcte/incorrecte) contre un vrai petit serveur TLS de test local
#  (certificat auto-signé généré par ce test, openssl).
# =============================================================================
from __future__ import annotations

import email
import email.policy
import importlib.util
import json
import os
import shutil
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time

ICI = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(ICI, "..", "alerte-casa.py")
PYTHON = sys.executable

REUSSIS = 0
ECHECS = 0


def ok(libelle: str) -> None:
    global REUSSIS
    REUSSIS += 1
    print(f"  OK   {libelle}")


def ko(libelle: str) -> None:
    global ECHECS
    ECHECS += 1
    print(f"  FAIL {libelle}")


def assert_eq(attendu, obtenu, libelle: str) -> None:
    if attendu == obtenu:
        ok(f"{libelle} (= {obtenu!r})")
    else:
        ko(f"{libelle} (attendu={attendu!r} obtenu={obtenu!r})")


def assert_true(cond: bool, libelle: str) -> None:
    ok(libelle) if cond else ko(libelle)


def assert_false(cond: bool, libelle: str) -> None:
    ok(libelle) if not cond else ko(libelle)


# --- Chargement du module (fonctions pures testées directement) ------------
spec = importlib.util.spec_from_file_location("alerte_casa", SCRIPT)
alerte_casa = importlib.util.module_from_spec(spec)
spec.loader.exec_module(alerte_casa)


def sujets_des_messages(chemin_stub: str) -> list[str]:
    """Sujets DÉCODÉS (RFC 2047) de chaque message accumulé dans le fichier
    stub — nécessaire car un sujet accentué (« RÉSOLU ») est encodé en
    mot-encodé ASCII par email.policy.default lors de as_string(), donc
    absent tel quel du texte brut."""
    if not os.path.exists(chemin_stub):
        return []
    brut = open(chemin_stub, encoding="utf-8").read()
    blocs = [b for b in brut.split("----FIN-MESSAGE----") if b.strip()]
    sujets = []
    for b in blocs:
        msg = email.message_from_string(b.lstrip("\r\n"), policy=email.policy.default)
        sujets.append(str(msg.get("Subject", "")))
    return sujets


def executer(env_extra: dict, args: list[str] | None = None) -> subprocess.CompletedProcess:
    """Lance alerte-casa.py en SOUS-PROCESSUS (comme en prod/cron), avec un
    environnement minimal + les surcharges de test."""
    env = {
        "PATH": os.environ.get("PATH", ""),
        "HOME": os.environ.get("HOME", "/root"),
    }
    env.update(env_extra)
    return subprocess.run(
        [PYTHON, SCRIPT, *(args or [])],
        capture_output=True, text=True, timeout=30, env=env,
    )


# =============================================================================
print("=== Test 1 : parseur d'environnement (guillemets, #, $, échappement) ===")
with tempfile.TemporaryDirectory() as tmp:
    env_fictif = os.path.join(tmp, ".env.fictif")
    with open(env_fictif, "w", encoding="utf-8") as f:
        f.write(
            "SIMPLE=abc\n"
            'QUOTED_DQ="abc def"\n'
            "QUOTED_SQ='abc def'\n"
            'MAIL_PASSWORD="p@ss#w0rd!$pecial"\n'
            'HASH_INSIDE="abc#def"\n'
            "UNQUOTED_HASH_NOSPACE=abc#def\n"
            "UNQUOTED_HASH_SPACE=abc #def\n"
            "# commentaire pleine ligne, ignoré\n"
            "  # commentaire indenté, ignoré aussi\n"
            "LIGNE_SANS_EGAL_IGNOREE\n"
            "EMPTY_VALUE=\n"
            'TRAILING_AFTER_QUOTE="abc"xyz\n'
            'ESCAPED_DQ_INSIDE="ab\\"cd"\n'
            "MULTI_EQUALS=a=b=c\n"
            "TRAILING_SPACE=abc   \n"
            "DOLLAR_LITERAL=pa$word\n"
        )
    valeurs = alerte_casa.lire_env_production(env_fictif)
    assert_eq("abc", valeurs.get("SIMPLE"), "valeur simple non quotée")
    assert_eq("abc def", valeurs.get("QUOTED_DQ"), "guillemets doubles retirés")
    assert_eq("abc def", valeurs.get("QUOTED_SQ"), "guillemets simples retirés")
    assert_eq("p@ss#w0rd!$pecial", valeurs.get("MAIL_PASSWORD"),
              "mot de passe fictif avec #/$ préservé intact (quoté)")
    assert_eq("abc#def", valeurs.get("HASH_INSIDE"), "# protégé par guillemets")
    assert_eq("abc#def", valeurs.get("UNQUOTED_HASH_NOSPACE"), "# collé = littéral (non quoté)")
    assert_eq("abc", valeurs.get("UNQUOTED_HASH_SPACE"), "# précédé d'un espace = commentaire inline")
    assert_true("LIGNE_SANS_EGAL_IGNOREE" not in valeurs, "ligne sans '=' ignorée")
    assert_eq("", valeurs.get("EMPTY_VALUE"), "valeur vide acceptée")
    assert_eq("abc", valeurs.get("TRAILING_AFTER_QUOTE"), "contenu après guillemet fermant ignoré")
    assert_eq('ab"cd', valeurs.get("ESCAPED_DQ_INSIDE"), '\\" interne correctement dé-échappé')
    assert_eq("a=b=c", valeurs.get("MULTI_EQUALS"), "coupure sur le premier '=' seulement")
    assert_eq("abc", valeurs.get("TRAILING_SPACE"), "espaces de fin retirés (non quoté)")
    assert_eq("pa$word", valeurs.get("DOLLAR_LITERAL"), "$ littéral préservé, aucune interpolation")
print()

# =============================================================================
print("=== Test 2 : détermination du schéma SMTP (logique Laravel exacte) ===")
assert_eq("smtp", alerte_casa.determiner_schema("", 587), "vide + port 587 -> smtp")
assert_eq("smtps", alerte_casa.determiner_schema("", 465), "vide + port 465 -> smtps")
assert_eq("smtp", alerte_casa.determiner_schema("", 2525), "vide + port quelconque -> smtp")
assert_eq("smtp", alerte_casa.determiner_schema("smtp", 465), "smtp explicite respecté même sur 465")
assert_eq("smtps", alerte_casa.determiner_schema("smtps", 587), "smtps explicite respecté même sur 587")
try:
    alerte_casa.determiner_schema("tls", 587)
    ko("schéma inconnu 'tls' aurait dû lever une erreur")
except ValueError as e:
    assert_true("tls" in str(e) or "non reconnu" in str(e), "schéma inconnu 'tls' refusé proprement")
print()

# =============================================================================
print("=== Test 3 : normalisation de l'empreinte de certificat ===")
assert_eq(None, alerte_casa.normaliser_empreinte(""), "vide -> None (vérification standard)")
assert_eq(None, alerte_casa.normaliser_empreinte("   "), "espaces seuls -> None")
assert_eq(
    "aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899",
    alerte_casa.normaliser_empreinte("AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99"),
    "majuscules + ':' normalisés",
)
print()

# =============================================================================
print("=== Test 4 : calcul du pourcentage disque (fonction pure) ===")
assert_eq(50.0, alerte_casa.calculer_pourcentage(100, 50), "50/100 = 50%")
assert_eq(0.0, alerte_casa.calculer_pourcentage(0, 0), "total nul -> 0%, pas de division par zéro")
assert_eq(99.9, alerte_casa.calculer_pourcentage(1000, 999), "arrondi à 1 décimale")
print()

# =============================================================================
print("=== Test 5 : témoin de sauvegarde (absent / périmé / récent) ===")
with tempfile.TemporaryDirectory() as tmp:
    ok5, detail5 = alerte_casa.verifier_temoin_sauvegarde(tmp, 26)
    assert_false(ok5, "témoin absent -> en alerte")
    assert_eq("absent", detail5, "détail 'absent'")

    temoin = os.path.join(tmp, ".derniere-sauvegarde-ok")
    with open(temoin, "w") as f:
        f.write("2026-01-01T00:00:00+00:00\n")
    vieux = time.time() - 30 * 3600  # 30h dans le passé
    os.utime(temoin, (vieux, vieux))
    ok5b, detail5b = alerte_casa.verifier_temoin_sauvegarde(tmp, 26)
    assert_false(ok5b, "témoin vieux de 30h (seuil 26h) -> en alerte")

    recent = time.time() - 3600  # 1h dans le passé
    os.utime(temoin, (recent, recent))
    ok5c, detail5c = alerte_casa.verifier_temoin_sauvegarde(tmp, 26)
    assert_true(ok5c, "témoin vieux de 1h (seuil 26h) -> OK")
print()

# =============================================================================
print("=== Fixtures Docker jetables (conteneurs réels) pour les tests 6-11 ===")
SAIN = f"test-alerte-sain-{os.getpid()}"
ARRETE = f"test-alerte-arrete-{os.getpid()}"


def nettoyer_fixtures():
    subprocess.run(["docker", "rm", "-f", SAIN, ARRETE], capture_output=True)


nettoyer_fixtures()
subprocess.run(["docker", "run", "-d", "--name", SAIN, "alpine", "sleep", "600"], capture_output=True, check=True)
subprocess.run(["docker", "run", "--name", ARRETE, "alpine", "true"], capture_output=True, check=True)
print(f"Fixtures prêtes : sain={SAIN} (running, sans healthcheck) arrete={ARRETE} (déjà terminé)")
print()

# =============================================================================
print("=== Test 6 : contrôle de conteneur réel (running sans healthcheck, arrêté, absent) ===")
ok6, detail6 = alerte_casa.verifier_conteneur(SAIN)
assert_true(ok6, f"conteneur running sans healthcheck -> OK ({detail6})")
ok6b, detail6b = alerte_casa.verifier_conteneur(ARRETE)
assert_false(ok6b, f"conteneur arrêté -> en alerte ({detail6b})")
ok6c, detail6c = alerte_casa.verifier_conteneur("conteneur-totalement-inexistant-xyz")
assert_false(ok6c, f"conteneur absent -> en alerte ({detail6c})")
print()

# =============================================================================
print("=== Test 7 : docker inaccessible (DOCKER_HOST invalide) ===")
ancien_dh = os.environ.get("DOCKER_HOST")
os.environ["DOCKER_HOST"] = f"unix:///tmp/inexistant-{os.getpid()}.sock"
try:
    assert_false(alerte_casa.docker_accessible(), "docker_accessible() renvoie False si DOCKER_HOST invalide")
finally:
    if ancien_dh is None:
        os.environ.pop("DOCKER_HOST", None)
    else:
        os.environ["DOCKER_HOST"] = ancien_dh
print()


# =============================================================================
def env_fichier_fictif(tmp: str, mot_de_passe: str = "jeton-fictif-SECRET-123") -> str:
    chemin = os.path.join(tmp, ".env.production")
    with open(chemin, "w", encoding="utf-8") as f:
        f.write(
            "MAIL_MAILER=smtp\n"
            "MAIL_HOST=smtp.exemple.invalid\n"
            "MAIL_PORT=587\n"
            "MAIL_SCHEME=\n"
            "MAIL_USERNAME=robot@exemple.invalid\n"
            f'MAIL_PASSWORD="{mot_de_passe}"\n'
            'MAIL_FROM_ADDRESS="alerte@exemple.invalid"\n'
            "MAIL_PEER_FINGERPRINT=\n"
        )
    return chemin


def env_base(tmp: str, extra: dict | None = None) -> dict:
    """Environnement de base pour un run complet : disque/conteneurs/témoin
    tous forcés OK, pour isoler le scénario testé."""
    e = {
        "ALERTE_ENV_FILE": env_fichier_fictif(tmp),
        "ALERTE_DESTINATAIRES": "destinataire-fictif@exemple.invalid",
        "ALERTE_ETAT_DIR": os.path.join(tmp, "etat"),
        "ALERTE_ENVOI_STUB_FICHIER": os.path.join(tmp, "stub.eml"),
        "ALERTE_LOG": os.path.join(tmp, "alerte.log"),
        "ALERTE_SEUIL_DISQUE": "100000",  # jamais atteint -> disque toujours OK
        "BACKUP_DIR": tmp,  # témoin créé OK juste en dessous
        "ALERTE_TEMOIN_MAX_AGE_H": "26",
        "POSTGRES_CONTAINER": SAIN, "BACKEND_CONTAINER": SAIN, "FRONTEND_CONTAINER": SAIN,
        "NGINX_CONTAINER": SAIN, "WORKER_CONTAINER": SAIN,
        "ALERTE_RAPPEL_HEURES": "24",
        "ALERTE_RAPPORT_HEBDO_JOURS": "7",
    }
    temoin = os.path.join(tmp, ".derniere-sauvegarde-ok")
    with open(temoin, "w") as f:
        f.write("ok\n")
    if extra:
        e.update(extra)
    return e


print("=== Test 8 : scénario complet anti-bruit (nouveau -> silence -> rappel -> résolu) ===")
with tempfile.TemporaryDirectory() as tmp:
    t0 = "2026-01-01T00:00:00+00:00"
    # Disque en ALERTE (seuil à 0, toujours dépassé), tout le reste OK.
    env = env_base(tmp, {"ALERTE_SEUIL_DISQUE": "0", "ALERTE_MAINTENANT_ISO": t0})

    r1 = executer(env)
    assert_eq(0, r1.returncode, "run 1 (1re détection) -> code 0")
    stub_contenu = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_true("[CASA][ALERTE]" in stub_contenu, "run 1 : un e-mail ALERTE a été envoyé")
    assert_true("Occupation disque /" in stub_contenu, "run 1 : contrôle disque nommé dans le corps")
    nb_apres_run1 = stub_contenu.count("----FIN-MESSAGE----")
    assert_eq(1, nb_apres_run1, "run 1 : exactement 1 e-mail (pas un par contrôle en alerte)")

    r2 = executer({**env, "ALERTE_MAINTENANT_ISO": "2026-01-01T02:00:00+00:00"})
    assert_eq(0, r2.returncode, "run 2 (2h plus tard, toujours en alerte) -> code 0")
    stub_contenu2 = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_eq(1, stub_contenu2.count("----FIN-MESSAGE----"), "run 2 : AUCUN nouvel e-mail (anti-bruit, < 24h)")

    r3 = executer({**env, "ALERTE_MAINTENANT_ISO": "2026-01-02T01:00:00+00:00"})  # +25h depuis t0
    assert_eq(0, r3.returncode, "run 3 (25h plus tard) -> code 0")
    stub_contenu3 = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_eq(2, stub_contenu3.count("----FIN-MESSAGE----"), "run 3 : UN rappel envoyé (>= 24h)")

    env_resolu = env_base(tmp, {"ALERTE_SEUIL_DISQUE": "100000",
                                 "ALERTE_MAINTENANT_ISO": "2026-01-02T02:00:00+00:00",
                                 "ALERTE_ENV_FILE": env["ALERTE_ENV_FILE"],
                                 "ALERTE_ETAT_DIR": env["ALERTE_ETAT_DIR"],
                                 "ALERTE_ENVOI_STUB_FICHIER": env["ALERTE_ENVOI_STUB_FICHIER"]})
    r4 = executer(env_resolu)
    assert_eq(0, r4.returncode, "run 4 (retour à la normale) -> code 0")
    stub_contenu4 = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_eq(3, stub_contenu4.count("----FIN-MESSAGE----"), "run 4 : UN e-mail RÉSOLU envoyé")
    sujets4 = sujets_des_messages(env["ALERTE_ENVOI_STUB_FICHIER"])
    assert_eq("[CASA][RÉSOLU]", sujets4[2], "run 4 : sujet RÉSOLU (décodé RFC 2047)")

    r5 = executer({**env_resolu, "ALERTE_MAINTENANT_ISO": "2026-01-02T03:00:00+00:00"})
    stub_contenu5 = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_eq(3, stub_contenu5.count("----FIN-MESSAGE----"), "run 5 (tout normal) : toujours aucun nouvel e-mail")
print()

# =============================================================================
print("=== Test 9 : échec d'envoi -> AUCUN état marqué « fait » (nouvel essai à l'heure suivante) ===")
with tempfile.TemporaryDirectory() as tmp:
    # ALERTE_ENVOI_STUB_FICHIER pointé vers un RÉPERTOIRE (pas un fichier) ->
    # l'écriture échoue systématiquement -> envoyer() renvoie False.
    chemin_impossible = os.path.join(tmp, "stub-est-un-dossier")
    os.makedirs(chemin_impossible)
    env = env_base(tmp, {"ALERTE_SEUIL_DISQUE": "0", "ALERTE_ENVOI_STUB_FICHIER": chemin_impossible,
                          "ALERTE_MAINTENANT_ISO": "2026-02-01T00:00:00+00:00"})
    r = executer(env)
    assert_eq(4, r.returncode, "échec d'envoi -> code de sortie 4")
    etat_path = os.path.join(env["ALERTE_ETAT_DIR"], "disque_racine.json")
    assert_false(os.path.exists(etat_path), "aucun fichier d'état écrit après un échec d'envoi")

    # Un 2e run, avec un envoi qui RÉUSSIT cette fois, doit traiter ceci
    # comme une PREMIÈRE détection (pas de rappel à retardement erroné).
    env2 = env_base(tmp, {"ALERTE_SEUIL_DISQUE": "0",
                           "ALERTE_ENV_FILE": env["ALERTE_ENV_FILE"],
                           "ALERTE_ETAT_DIR": env["ALERTE_ETAT_DIR"],
                           "ALERTE_MAINTENANT_ISO": "2026-02-01T00:05:00+00:00"})
    r2 = executer(env2)
    assert_eq(0, r2.returncode, "nouvel essai (envoi fonctionnel) -> code 0")
    stub2 = open(env2["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_true("[CASA][ALERTE]" in stub2, "le nouvel essai envoie bien l'alerte (traité comme 1re détection)")
print()

# =============================================================================
print("=== Test 10 : secrets absents de TOUTE sortie (stdout, stderr, log, stub) ===")
with tempfile.TemporaryDirectory() as tmp:
    mdp_secret = "MotDePasseFictifUltraSecret9000"
    env = env_base(tmp, {"ALERTE_SEUIL_DISQUE": "0"})
    env["ALERTE_ENV_FILE"] = env_fichier_fictif(tmp, mot_de_passe=mdp_secret)
    r = executer(env)
    hay = r.stdout + r.stderr
    if os.path.exists(env["ALERTE_LOG"]):
        hay += open(env["ALERTE_LOG"], encoding="utf-8").read()
    if os.path.exists(env["ALERTE_ENVOI_STUB_FICHIER"]):
        hay += open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_false(mdp_secret in hay, "le mot de passe fictif n'apparaît dans AUCUNE sortie (stdout/stderr/log/stub)")
print()

# =============================================================================
print("=== Test 11 : rapport hebdomadaire (envoyé 1x, pas avant 7j, pas si l'envoi échoue) ===")
with tempfile.TemporaryDirectory() as tmp:
    env = env_base(tmp, {"ALERTE_MAINTENANT_ISO": "2026-03-01T00:00:00+00:00"})  # tout OK, aucune alerte
    r1 = executer(env)
    assert_eq(0, r1.returncode, "run 1 (tout normal, rapport dû) -> code 0")
    stub1 = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_true("[CASA][OK] rapport hebdomadaire" in stub1, "run 1 : rapport hebdomadaire envoyé")
    assert_eq(1, stub1.count("----FIN-MESSAGE----"), "run 1 : un seul e-mail")

    r2 = executer({**env, "ALERTE_MAINTENANT_ISO": "2026-03-03T00:00:00+00:00"})  # +2j
    stub2 = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_eq(1, stub2.count("----FIN-MESSAGE----"), "run 2 (+2j, < 7j) : aucun nouveau rapport")

    r3 = executer({**env, "ALERTE_MAINTENANT_ISO": "2026-03-09T00:00:00+00:00"})  # +8j
    stub3 = open(env["ALERTE_ENVOI_STUB_FICHIER"], encoding="utf-8").read()
    assert_eq(2, stub3.count("----FIN-MESSAGE----"), "run 3 (+8j, >= 7j) : nouveau rapport envoyé")

# Rapport dû + envoi qui échoue -> pas marqué comme envoyé.
with tempfile.TemporaryDirectory() as tmp:
    chemin_impossible = os.path.join(tmp, "stub-dossier")
    os.makedirs(chemin_impossible)
    env = env_base(tmp, {"ALERTE_ENVOI_STUB_FICHIER": chemin_impossible,
                          "ALERTE_MAINTENANT_ISO": "2026-03-01T00:00:00+00:00"})
    r = executer(env)
    assert_eq(4, r.returncode, "rapport dû mais envoi impossible -> code 4")
    chemin_etat_hebdo = os.path.join(env["ALERTE_ETAT_DIR"], "rapport_hebdo.json")
    assert_false(os.path.exists(chemin_etat_hebdo), "état du rapport hebdomadaire NON marqué si l'envoi échoue")
print()

# =============================================================================
print("=== Test 12 : verrou — double exécution bloquée ===")
with tempfile.TemporaryDirectory() as tmp:
    env = env_base(tmp, {"ALERTE_SEUIL_DISQUE": "100000"})
    etat_dir = env["ALERTE_ETAT_DIR"]
    os.makedirs(etat_dir, exist_ok=True)
    lock_path = os.path.join(etat_dir, ".alerte-casa.lock")
    lock_f = open(lock_path, "w")
    import fcntl as _fcntl
    _fcntl.flock(lock_f, _fcntl.LOCK_EX | _fcntl.LOCK_NB)
    try:
        r = executer(env)
        assert_eq(2, r.returncode, "verrou déjà pris -> code 2")
        assert_true("déjà en cours" in r.stderr, "message de refus explicite")
    finally:
        _fcntl.flock(lock_f, _fcntl.LOCK_UN)
        lock_f.close()
print()

nettoyer_fixtures()


# =============================================================================
# Test 13 : PINNING de certificat — vrai petit serveur TLS de test local.
# =============================================================================
print("=== Test 13 : pinning de certificat (serveur TLS de test local, certificat auto-signé) ===")
print("Couvre : empreinte correcte -> envoi réussi (AUTH envoyé) ; empreinte incorrecte -> connexion")
print("fermée AVANT toute commande AUTH, pour les DEUX schémas (smtp+starttls et smtps).")
print("Ne couvre PAS un vrai fournisseur SMTP externe — serveur minimal, usage de test uniquement.")


def generer_certificat(tmp: str) -> tuple[str, str]:
    cert = os.path.join(tmp, "test.crt")
    cle = os.path.join(tmp, "test.key")
    subprocess.run(
        ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-keyout", cle, "-out", cert,
         "-days", "1", "-nodes", "-subj", "/CN=test.local"],
        capture_output=True, check=True,
    )
    return cert, cle


def empreinte_sha256(chemin_cert: str) -> str:
    der = ssl.PEM_cert_to_DER_cert(open(chemin_cert, encoding="ascii").read())
    import hashlib
    return hashlib.sha256(der).hexdigest()


class ServeurSmtpTest:
    """Serveur SMTP minimal (sous-ensemble du protocole), SEULEMENT pour
    exercer le code de pinning du script sous test — pas un vrai serveur."""

    def __init__(self, certfile: str, keyfile: str, implicite_tls: bool):
        self.certfile = certfile
        self.keyfile = keyfile
        self.implicite_tls = implicite_tls
        self.auth_recu = False
        self._sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._sock.bind(("127.0.0.1", 0))
        self._sock.listen(1)
        self.port = self._sock.getsockname()[1]
        self._thread = threading.Thread(target=self._boucle, daemon=True)

    def start(self) -> None:
        self._thread.start()

    def join(self, timeout: float = 8.0) -> None:
        self._thread.join(timeout)

    def _ctx_serveur(self) -> ssl.SSLContext:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(self.certfile, self.keyfile)
        return ctx

    def _readline(self, conn) -> str:
        data = b""
        try:
            while not data.endswith(b"\r\n"):
                chunk = conn.recv(1)
                if not chunk:
                    break
                data += chunk
        except (OSError, ssl.SSLError):
            pass
        return data.decode("ascii", errors="replace")

    def _boucle(self) -> None:
        conn = None
        try:
            self._sock.settimeout(8)
            raw, _ = self._sock.accept()
            raw.settimeout(5)
            if self.implicite_tls:
                conn = self._ctx_serveur().wrap_socket(raw, server_side=True)
                # SMTPS (TLS implicite) : la salutation 220 arrive APRÈS la
                # poignée de main TLS (contrairement à STARTTLS, où elle est
                # envoyée en clair avant toute négociation TLS) — sans cette
                # ligne, smtplib.SMTP_SSL() se bloque dans son getreply()
                # initial et toute la suite échoue, y compris le cas
                # "empreinte correcte" (faux négatif de test, pas un vrai
                # problème de pinning).
                conn.sendall(b"220 test.local ESMTP\r\n")
                self._parler_post_tls(conn)
            else:
                self._parler_clair(raw)
        except (OSError, ssl.SSLError):
            pass  # attendu dans le cas "empreinte incorrecte" : le client coupe la connexion
        finally:
            try:
                if conn is not None:
                    conn.close()
            except Exception:
                pass

    def _parler_clair(self, conn) -> None:
        conn.sendall(b"220 test.local ESMTP\r\n")
        self._readline(conn)  # EHLO
        conn.sendall(b"250-test.local\r\n250 STARTTLS\r\n")
        self._readline(conn)  # STARTTLS
        conn.sendall(b"220 Ready to start TLS\r\n")
        conn2 = self._ctx_serveur().wrap_socket(conn, server_side=True)
        self._parler_post_tls(conn2)

    def _parler_post_tls(self, conn) -> None:
        ligne = self._readline(conn)  # EHLO (après poignée de main TLS / salutation SMTPS)
        if not ligne:
            return  # client a coupé avant même le 2e EHLO (empreinte rejetée tôt)
        # AUTH doit être annoncé ici, sinon smtplib.login() lève
        # SMTPNotSupportedError AVANT même d'envoyer "AUTH ..." — ce qui
        # ferait échouer le cas "empreinte correcte" pour une raison n'ayant
        # rien à voir avec le pinning testé.
        conn.sendall(b"250-test.local\r\n250 AUTH LOGIN PLAIN\r\n")
        ligne = self._readline(conn)
        if ligne.upper().startswith("AUTH"):
            self.auth_recu = True
            conn.sendall(b"235 2.7.0 Authentication successful\r\n")
            self._readline(conn)  # MAIL FROM
            conn.sendall(b"250 OK\r\n")
            self._readline(conn)  # RCPT TO
            conn.sendall(b"250 OK\r\n")
            self._readline(conn)  # DATA
            conn.sendall(b"354 End data with <CR><LF>.<CR><LF>\r\n")
            while True:
                l = self._readline(conn)
                if l == ".\r\n" or l == "":
                    break
            conn.sendall(b"250 OK\r\n")
            self._readline(conn)  # QUIT
            conn.sendall(b"221 Bye\r\n")

    def stop(self) -> None:
        try:
            self._sock.close()
        except Exception:
            pass


with tempfile.TemporaryDirectory() as tmp:
    cert, cle = generer_certificat(tmp)
    vraie_empreinte = empreinte_sha256(cert)
    fausse_empreinte = "00" * 32  # 64 hex chars, syntaxiquement valide, jamais la bonne

    for schema, implicite in (("smtp", False), ("smtps", True)):
        # --- empreinte CORRECTE : envoi normal, AUTH bien reçu ---
        srv = ServeurSmtpTest(cert, cle, implicite_tls=implicite)
        srv.start()
        env_file = os.path.join(tmp, f".env.{schema}.ok")
        with open(env_file, "w", encoding="utf-8") as f:
            f.write(
                "MAIL_MAILER=smtp\n"
                "MAIL_HOST=127.0.0.1\n"
                f"MAIL_PORT={srv.port}\n"
                f"MAIL_SCHEME={schema}\n"
                "MAIL_USERNAME=robot@exemple.invalid\n"
                'MAIL_PASSWORD="jeton-fictif"\n'
                'MAIL_FROM_ADDRESS="alerte@exemple.invalid"\n'
                f"MAIL_PEER_FINGERPRINT={vraie_empreinte}\n"
            )
        env = {
            "ALERTE_ENV_FILE": env_file,
            "ALERTE_DESTINATAIRES": "destinataire-fictif@exemple.invalid",
            "ALERTE_ETAT_DIR": os.path.join(tmp, f"etat-{schema}-ok"),
            "ALERTE_LOG": os.path.join(tmp, f"log-{schema}-ok.txt"),
        }
        r = executer(env, args=["--tester-envoi"])
        srv.join()
        assert_eq(0, r.returncode, f"[{schema}] empreinte correcte -> code de sortie 0")
        assert_true(srv.auth_recu, f"[{schema}] empreinte correcte -> AUTH bien envoyé (e-mail réellement transmis)")
        srv.stop()

        # --- empreinte INCORRECTE : connexion fermée AVANT AUTH ---
        srv2 = ServeurSmtpTest(cert, cle, implicite_tls=implicite)
        srv2.start()
        env_file2 = os.path.join(tmp, f".env.{schema}.bad")
        with open(env_file2, "w", encoding="utf-8") as f:
            f.write(
                "MAIL_MAILER=smtp\n"
                "MAIL_HOST=127.0.0.1\n"
                f"MAIL_PORT={srv2.port}\n"
                f"MAIL_SCHEME={schema}\n"
                "MAIL_USERNAME=robot@exemple.invalid\n"
                'MAIL_PASSWORD="jeton-fictif"\n'
                'MAIL_FROM_ADDRESS="alerte@exemple.invalid"\n'
                f"MAIL_PEER_FINGERPRINT={fausse_empreinte}\n"
            )
        env2 = {
            "ALERTE_ENV_FILE": env_file2,
            "ALERTE_DESTINATAIRES": "destinataire-fictif@exemple.invalid",
            "ALERTE_ETAT_DIR": os.path.join(tmp, f"etat-{schema}-bad"),
            "ALERTE_LOG": os.path.join(tmp, f"log-{schema}-bad.txt"),
        }
        r2 = executer(env2, args=["--tester-envoi"])
        srv2.join()
        assert_eq(4, r2.returncode, f"[{schema}] empreinte incorrecte -> code de sortie 4 (échec)")
        assert_false(srv2.auth_recu, f"[{schema}] empreinte incorrecte -> AUCUNE commande AUTH envoyée")
        log2 = open(env2["ALERTE_LOG"], encoding="utf-8").read() if os.path.exists(env2["ALERTE_LOG"]) else ""
        assert_true("empreinte" in (r2.stderr + log2).lower(),
                    f"[{schema}] message d'erreur mentionne l'empreinte")
        assert_false("jeton-fictif" in (r2.stdout + r2.stderr + log2),
                     f"[{schema}] mot de passe absent du message d'erreur")
        srv2.stop()
print()

print(f"=== Résultat : {REUSSIS} réussi(s), {ECHECS} échec(s) ===")
sys.exit(0 if ECHECS == 0 else 1)
