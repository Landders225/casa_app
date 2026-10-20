#!/usr/bin/env python3
# =============================================================================
#  CASA — Alerte hôte horaire : disque, témoin de sauvegarde, santé des
#  conteneurs. Préviens par e-mail AVANT que les utilisateurs ne s'en
#  aperçoivent (incidents des 17-19/10 : worker mort, disque racine plein
#  36h, personne prévenu).
#
#  Tourne sur l'HÔTE (pas dans un conteneur), comme le compte de déploiement
#  (`casa`). N'a AUCUNE dépendance au conteneur backend ni à Laravel : lit
#  directement le fichier d'environnement de production sur le disque,
#  n'appelle jamais `docker compose` ni `artisan` — fonctionne même si
#  Docker est en panne (c'est justement un des cas qu'il doit détecter).
#
#  Usage :
#    scripts-hote/alerte-casa.py                   passage normal (cron)
#    scripts-hote/alerte-casa.py --tester-envoi     envoie un message de
#                                                     test aux destinataires
#                                                     (validation manuelle)
#
#  Installation (cron, compte de déploiement, SANS sudo) :
#    crontab -e
#    # ajouter (toutes les heures, ALERTE_DESTINATAIRES à vos adresses) :
#    5 * * * * ALERTE_DESTINATAIRES="vous@exemple.org,autre@exemple.org" /opt/casa/scripts-hote/alerte-casa.py >> /home/casa/.alerte-casa/cron.log 2>&1
#
#  Variables d'environnement (optionnelles sauf ALERTE_DESTINATAIRES) :
#    ALERTE_DESTINATAIRES    OBLIGATOIRE — adresses séparées par des virgules,
#                            JAMAIS écrites dans ce dépôt
#    ALERTE_ENV_FILE         fichier contenant MAIL_* en production
#                            (défaut /opt/casa/backend/.env.production —
#                            vérifié sur jupiter : les 10 clés MAIL_* y sont
#                            TOUTES, /opt/casa/.env.production n'en contient
#                            aucune)
#    ALERTE_SEUIL_DISQUE     seuil d'alerte disque, en % (défaut 85)
#    BACKUP_DIR              répertoire du témoin de sauvegarde
#                            (défaut /home/casa/backups — PAS /srv/backups,
#                            qui est le défaut de backup-db.sh : si votre
#                            installation réelle de backup-db.sh écrit
#                            ailleurs, surchargez cette variable dans le
#                            cron, sinon fausse alerte permanente)
#    ALERTE_TEMOIN_MAX_AGE_H âge maximum du témoin, en heures (défaut 26)
#    POSTGRES_CONTAINER      défaut casa-postgres-1
#    BACKEND_CONTAINER       défaut casa-backend-1
#    FRONTEND_CONTAINER      défaut casa-frontend-1
#    NGINX_CONTAINER         défaut casa-nginx-1
#    WORKER_CONTAINER        défaut casa-worker-1
#    ALERTE_ETAT_DIR         défaut /home/casa/.alerte-casa
#    ALERTE_RAPPEL_HEURES    délai avant rappel d'une alerte active (défaut 24)
#    ALERTE_RAPPORT_HEBDO_JOURS  période du rapport de vie (défaut 7)
#    ALERTE_LOG              défaut <ALERTE_ETAT_DIR>/alerte.log
#    ALERTE_LOG_MAX_LIGNES   lignes conservées dans le log (défaut 500)
#    ALERTE_NOM_SERVEUR      nom affiché dans les e-mails (défaut hostname)
#
#  Variables réservées aux TESTS (scripts-hote/tests/test-alerte-casa.py),
#  SANS EFFET en production tant qu'elles ne sont pas positionnées :
#    ALERTE_ENVOI_STUB_FICHIER  si définie, AUCUN envoi SMTP réel n'a lieu —
#                                le message est ajouté (texte brut,
#                                as_string()) à ce fichier
#    ALERTE_MAINTENANT_ISO      horodatage ISO-8601 à utiliser comme « now »
#                                à la place de l'heure réelle (simule le
#                                temps qui passe pour les rappels/rapport
#                                hebdomadaire)
#
#  Codes de sortie :
#    0  OK (alerte envoyée avec succès, ou rien à signaler)
#    1  usage / configuration manquante (ALERTE_DESTINATAIRES, config SMTP
#       incomplète)
#    2  verrou déjà pris (exécution concurrente)
#    3  fichier d'environnement illisible
#    4  échec d'envoi SMTP (aucun état marqué « fait » — nouvel essai à
#       l'heure suivante)
#
#  Ne touche PAS : crontab de jupiter, /srv/backups, backend/.env.production
#  (lecture seule), Postfix local (jamais utilisé — SMTP authentifié direct
#  uniquement, par conception).
# =============================================================================
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import shutil
import smtplib
import socket
import ssl
import subprocess
import sys
from datetime import datetime, timezone
from email.message import EmailMessage


# --- Configuration (variables d'environnement, valeurs par défaut) ---------

def _env(nom: str, defaut: str) -> str:
    return os.environ.get(nom, defaut)


ENV_FILE = _env("ALERTE_ENV_FILE", "/opt/casa/backend/.env.production")
SEUIL_DISQUE = float(_env("ALERTE_SEUIL_DISQUE", "85"))
BACKUP_DIR = _env("BACKUP_DIR", "/home/casa/backups")
TEMOIN_MAX_AGE_H = float(_env("ALERTE_TEMOIN_MAX_AGE_H", "26"))
ETAT_DIR = _env("ALERTE_ETAT_DIR", "/home/casa/.alerte-casa")
DESTINATAIRES_BRUT = _env("ALERTE_DESTINATAIRES", "")
RAPPEL_HEURES = float(_env("ALERTE_RAPPEL_HEURES", "24"))
RAPPORT_HEBDO_JOURS = float(_env("ALERTE_RAPPORT_HEBDO_JOURS", "7"))
LOG_FICHIER = _env("ALERTE_LOG", os.path.join(ETAT_DIR, "alerte.log"))
LOG_MAX_LIGNES = int(_env("ALERTE_LOG_MAX_LIGNES", "500"))
NOM_SERVEUR = _env("ALERTE_NOM_SERVEUR", socket.gethostname())

CONTENEURS = {
    "postgres": _env("POSTGRES_CONTAINER", "casa-postgres-1"),
    "backend": _env("BACKEND_CONTAINER", "casa-backend-1"),
    "frontend": _env("FRONTEND_CONTAINER", "casa-frontend-1"),
    "nginx": _env("NGINX_CONTAINER", "casa-nginx-1"),
    "worker": _env("WORKER_CONTAINER", "casa-worker-1"),
}

ENVOI_STUB_FICHIER = os.environ.get("ALERTE_ENVOI_STUB_FICHIER")


def maintenant() -> datetime:
    """`now()` injectable (ALERTE_MAINTENANT_ISO) pour simuler le temps qui
    passe dans les tests (rappel 24h, rapport hebdomadaire 7j) sans attendre
    réellement."""
    surcharge = os.environ.get("ALERTE_MAINTENANT_ISO")
    if surcharge:
        return datetime.fromisoformat(surcharge)
    return datetime.now(timezone.utc)


# --- Journal (tronqué aux ~LOG_MAX_LIGNES dernières lignes) -----------------

def log(message: str) -> None:
    ligne = f"[{maintenant().isoformat()}] {message}"
    print(ligne, file=sys.stderr)
    try:
        os.makedirs(os.path.dirname(LOG_FICHIER) or ".", exist_ok=True)
        lignes = []
        if os.path.isfile(LOG_FICHIER):
            with open(LOG_FICHIER, "r", encoding="utf-8", errors="replace") as f:
                lignes = f.readlines()
        lignes.append(ligne + "\n")
        lignes = lignes[-LOG_MAX_LIGNES:]
        with open(LOG_FICHIER, "w", encoding="utf-8") as f:
            f.writelines(lignes)
    except OSError:
        pass  # le journal est best-effort — ne doit jamais faire planter le script


# --- Parseur direct de backend/.env.production (SANS docker compose) -------
#
# Règles établies EMPIRIQUEMENT (sondes réelles avec `docker compose config`
# sur des fichiers .env 100% fictifs — jamais une vraie valeur) pour
# reproduire EXACTEMENT ce que Compose résout pour le conteneur backend :
#   - ligne vide ou commençant par # (après espaces de tête) -> ignorée
#   - ligne sans '=' -> ignorée
#   - coupure sur le PREMIER '=' seulement
#   - espaces autour de '=' et en tête de valeur -> retirés
#   - valeur entre guillemets doubles : guillemets retirés, \" -> " à
#     l'intérieur, tout ce qui suit le guillemet fermant sur la ligne est
#     IGNORÉ (ex. "abc"xyz -> abc, "abc"#suite -> abc)
#   - valeur entre guillemets simples : idem, SANS traitement d'échappement
#   - valeur non quotée : un '#' précédé d'un ESPACE démarre un commentaire
#     inline (abc #def -> abc) ; un '#' collé à un caractère est littéral
#     (abc#def -> abc#def) ; espaces de fin retirés
#   - AUCUNE interpolation ${VAR} n'est appliquée aux valeurs d'un env_file
#     (vérifié : $ passe tel quel)

def _extraire_quote(reste: str, quote_char: str, echappement: bool) -> str:
    resultat = []
    i = 1
    n = len(reste)
    while i < n:
        c = reste[i]
        if echappement and c == "\\" and i + 1 < n and reste[i + 1] == quote_char:
            resultat.append(quote_char)
            i += 2
            continue
        if c == quote_char:
            return "".join(resultat)
        resultat.append(c)
        i += 1
    return "".join(resultat)  # pas de guillemet fermant : cas dégénéré, pris tel quel


def _extraire_non_quote(reste: str) -> str:
    idx = reste.find(" #")
    if idx != -1:
        reste = reste[:idx]
    return reste.rstrip()


def parser_ligne_env(ligne: str) -> tuple[str, str] | None:
    """Retourne (clé, valeur) ou None si la ligne est vide/commentaire/invalide."""
    ligne = ligne.rstrip("\n").rstrip("\r")
    stripped = ligne.lstrip()
    if stripped == "" or stripped.startswith("#"):
        return None
    if "=" not in ligne:
        return None
    cle, _, reste = ligne.partition("=")
    cle = cle.strip()
    reste = reste.lstrip()
    if reste.startswith('"'):
        valeur = _extraire_quote(reste, '"', echappement=True)
    elif reste.startswith("'"):
        valeur = _extraire_quote(reste, "'", echappement=False)
    else:
        valeur = _extraire_non_quote(reste)
    return cle, valeur


def lire_env_production(chemin: str) -> dict[str, str]:
    valeurs: dict[str, str] = {}
    with open(chemin, "r", encoding="utf-8") as f:
        for ligne in f:
            paire = parser_ligne_env(ligne)
            if paire is not None:
                valeurs[paire[0]] = paire[1]
    return valeurs


# --- Schéma SMTP — réplique App\Mail (config/mail.php + MailManager) -------

SCHEMAS_RECONNUS = ("smtp", "smtps")


def determiner_schema(mail_scheme: str, mail_port: int) -> str:
    """Même logique EXACTE que Laravel MailManager::createSmtpTransport()
    (backend/vendor/laravel/framework/.../MailManager.php:193-200) : si
    MAIL_SCHEME est vide, smtps SEULEMENT si le port est 465, sinon smtp —
    PAS "STARTTLS par défaut" sans condition."""
    schema = (mail_scheme or "").strip()
    if not schema:
        schema = "smtps" if mail_port == 465 else "smtp"
    if schema not in SCHEMAS_RECONNUS:
        raise ValueError(
            f"schéma SMTP non reconnu ({schema!r}) — attendu 'smtp' ou 'smtps' "
            f"(Symfony Mailer EsmtpTransportFactory n'en accepte pas d'autres)"
        )
    return schema


def normaliser_empreinte(brut: str) -> str | None:
    """Même normalisation EXACTE que App\\Mail\\PeerFingerprint::normalize()."""
    hexa = (brut or "").strip().replace(":", "").lower()
    return hexa or None


class ErreurEmpreinte(Exception):
    pass


def construire_contexte_ssl(empreinte_attendue: str | None) -> ssl.SSLContext:
    if empreinte_attendue:
        # Épinglage actif : vérification de CHAÎNE désactivée — exactement
        # comme Laravel (verify_peer=false UNIQUEMENT dans ce cas, cf.
        # config/mail.php:69-81) — nécessaire pour un certificat auto-signé,
        # qu'aucune CA ne peut valider. La vérification ne disparaît pas :
        # elle est remplacée par la comparaison d'empreinte ci-dessous,
        # TOUJOURS appliquée quand une empreinte est configurée.
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    else:
        ctx = ssl.create_default_context()
    return ctx


def verifier_empreinte(sock_ssl, empreinte_attendue: str | None) -> None:
    """DOIT être appelée après l'établissement TLS et AVANT toute commande
    AUTH, pour les deux schémas (smtp+starttls et smtps)."""
    if not empreinte_attendue:
        return
    der = sock_ssl.getpeercert(binary_form=True)
    if der is None:
        raise ErreurEmpreinte("certificat du serveur SMTP indisponible après la poignée de main TLS")
    empreinte_reelle = hashlib.sha256(der).hexdigest()
    if empreinte_reelle != empreinte_attendue:
        raise ErreurEmpreinte(
            "empreinte du certificat SMTP différente de celle attendue — "
            "connexion abandonnée AVANT authentification"
        )


def extraire_config_smtp(valeurs: dict[str, str]) -> dict:
    host = valeurs.get("MAIL_HOST", "")
    port_str = valeurs.get("MAIL_PORT", "")
    port = int(port_str) if port_str.strip() else 25
    schema = determiner_schema(valeurs.get("MAIL_SCHEME", ""), port)
    username = valeurs.get("MAIL_USERNAME", "")
    password = valeurs.get("MAIL_PASSWORD", "")
    from_addr = valeurs.get("MAIL_FROM_ADDRESS", "")
    empreinte = normaliser_empreinte(valeurs.get("MAIL_PEER_FINGERPRINT", ""))
    if not host or not from_addr:
        raise ValueError("configuration SMTP incomplète (MAIL_HOST ou MAIL_FROM_ADDRESS manquant)")
    return {
        "host": host,
        "port": port,
        "schema": schema,
        "username": username,
        "password": password,
        "from": from_addr,
        "empreinte": empreinte,
    }


# --- Envoi --------------------------------------------------------------

def envoyer(message: EmailMessage, destinataires: list[str], smtp_config: dict) -> bool:
    if ENVOI_STUB_FICHIER:
        try:
            with open(ENVOI_STUB_FICHIER, "a", encoding="utf-8") as f:
                f.write(message.as_string())
                f.write("\n----FIN-MESSAGE----\n")
            return True
        except OSError:
            return False

    ctx = construire_contexte_ssl(smtp_config["empreinte"])
    smtp = None
    try:
        if smtp_config["schema"] == "smtps":
            smtp = smtplib.SMTP_SSL(smtp_config["host"], smtp_config["port"], timeout=30, context=ctx)
            # Empreinte vérifiée AVANT toute authentification, dans les DEUX
            # schémas (smtps : juste après la poignée de main implicite ;
            # smtp+starttls : juste après starttls() ci-dessous) — jamais de
            # commande AUTH envoyée si l'empreinte ne correspond pas.
            verifier_empreinte(smtp.sock, smtp_config["empreinte"])
            smtp.ehlo()
        else:
            smtp = smtplib.SMTP(smtp_config["host"], smtp_config["port"], timeout=30)
            smtp.ehlo()
            smtp.starttls(context=ctx)
            verifier_empreinte(smtp.sock, smtp_config["empreinte"])
            smtp.ehlo()
        if smtp_config["username"]:
            smtp.login(smtp_config["username"], smtp_config["password"])
        smtp.send_message(message, from_addr=smtp_config["from"], to_addrs=destinataires)
        smtp.quit()
        return True
    except ErreurEmpreinte as e:
        log(f"ERREUR : {e}")
        return False
    except Exception as e:  # jamais str(e) brut si une lib tierce y glissait un secret un jour
        log(f"ERREUR envoi SMTP ({type(e).__name__}).")
        return False
    finally:
        # Ferme systématiquement la connexion (y compris sur l'échec
        # d'empreinte, AVANT tout AUTH) — ne laisse jamais un socket TLS
        # pendre en arrière-plan.
        if smtp is not None:
            try:
                smtp.close()
            except Exception:
                pass


# --- Contrôles ------------------------------------------------------------

def calculer_pourcentage(total: int, used: int) -> float:
    if total <= 0:
        return 0.0
    return round(used / total * 100, 1)


def verifier_disque(chemin: str, seuil: float) -> tuple[bool, str]:
    total, used, _free = shutil.disk_usage(chemin)
    pct = calculer_pourcentage(total, used)
    return pct < seuil, f"{pct}%"


def verifier_temoin_sauvegarde(backup_dir: str, max_age_h: float) -> tuple[bool, str]:
    chemin = os.path.join(backup_dir, ".derniere-sauvegarde-ok")
    if not os.path.isfile(chemin):
        return False, "absent"
    age_h = (maintenant().timestamp() - os.path.getmtime(chemin)) / 3600
    return age_h <= max_age_h, f"{age_h:.1f} h"


def docker_accessible() -> bool:
    try:
        r = subprocess.run(["docker", "info"], capture_output=True, timeout=10)
        return r.returncode == 0
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return False


def verifier_conteneur(nom: str) -> tuple[bool, str]:
    try:
        r = subprocess.run(
            [
                "docker", "inspect", nom, "--format",
                "{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}(aucun healthcheck){{end}}",
            ],
            capture_output=True, text=True, timeout=10,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return False, "docker introuvable"
    if r.returncode != 0:
        return False, "absent"
    statut, _, sante = r.stdout.strip().partition("|")
    if statut != "running":
        return False, statut or "arrêté"
    if sante not in ("healthy", "(aucun healthcheck)"):
        return False, sante
    return True, sante


def evaluer_controles() -> list[dict]:
    resultats = []

    ok, detail = verifier_disque("/", SEUIL_DISQUE)
    resultats.append({"cle": "disque_racine", "libelle": "Occupation disque /", "ok": ok, "detail": detail,
                       "action": "Vérifier df -h / et la rotation des sauvegardes (scripts-hote/backup-db.sh)."})

    ok, detail = verifier_disque("/home", SEUIL_DISQUE)
    resultats.append({"cle": "disque_home", "libelle": "Occupation disque /home", "ok": ok, "detail": detail,
                       "action": "Vérifier df -h /home."})

    ok, detail = verifier_temoin_sauvegarde(BACKUP_DIR, TEMOIN_MAX_AGE_H)
    resultats.append({"cle": "temoin_sauvegarde", "libelle": "Témoin de sauvegarde", "ok": ok, "detail": detail,
                       "action": "Vérifier l'exécution de backup-db.sh et son backup.log."})

    d_ok = docker_accessible()
    resultats.append({"cle": "docker", "libelle": "Accès Docker", "ok": d_ok,
                       "detail": "accessible" if d_ok else "inaccessible",
                       "action": "Vérifier le démon Docker et l'appartenance au groupe docker du compte."})

    for role, nom in CONTENEURS.items():
        if d_ok:
            ok, detail = verifier_conteneur(nom)
        else:
            ok, detail = False, "docker inaccessible"
        resultats.append({"cle": f"conteneur_{role}", "libelle": f"Conteneur {nom}", "ok": ok, "detail": detail,
                           "action": f"Vérifier `docker logs {nom}`."})

    return resultats


# --- État (anti-bruit) -----------------------------------------------------

def _chemin_etat(cle: str) -> str:
    return os.path.join(ETAT_DIR, f"{cle}.json")


def lire_etat(cle: str) -> dict | None:
    try:
        with open(_chemin_etat(cle), "r", encoding="utf-8") as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        return None


def ecrire_etat(cle: str, donnees: dict) -> bool:
    try:
        os.makedirs(ETAT_DIR, exist_ok=True)
        tmp = _chemin_etat(cle) + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(donnees, f)
        os.replace(tmp, _chemin_etat(cle))
        return True
    except OSError:
        return False


def supprimer_etat(cle: str) -> None:
    try:
        os.remove(_chemin_etat(cle))
    except OSError:
        pass


def traiter_controles(resultats: list[dict], now: datetime) -> tuple[list[dict], list[tuple]]:
    items: list[dict] = []
    a_marquer: list[tuple] = []

    for r in resultats:
        cle = r["cle"]
        etat = lire_etat(cle)
        if not r["ok"]:
            if etat is None:
                items.append({"type": "nouveau", **r})
                a_marquer.append((cle, "ecrire", {
                    "premiere_detection": now.isoformat(),
                    "dernier_envoi": now.isoformat(),
                }))
            else:
                dernier_envoi = datetime.fromisoformat(etat["dernier_envoi"])
                if (now - dernier_envoi).total_seconds() >= RAPPEL_HEURES * 3600:
                    items.append({"type": "rappel", **r})
                    a_marquer.append((cle, "ecrire", {**etat, "dernier_envoi": now.isoformat()}))
        else:
            if etat is not None:
                items.append({"type": "resolu", **r})
                a_marquer.append((cle, "supprimer", None))

    return items, a_marquer


def verifier_rapport_hebdo(now: datetime, resultats: list[dict]) -> dict | None:
    etat = lire_etat("rapport_hebdo")
    if etat is not None:
        dernier = datetime.fromisoformat(etat["dernier_envoi"])
        if (now - dernier).total_seconds() < RAPPORT_HEBDO_JOURS * 86400:
            return None
    return {"resultats": resultats}


# --- Construction du message ------------------------------------------------

def construire_message(items: list[dict], rapport_hebdo: dict | None, now: datetime,
                        from_addr: str, destinataires: list[str]) -> EmailMessage | None:
    alertes = [i for i in items if i["type"] in ("nouveau", "rappel")]
    resolus = [i for i in items if i["type"] == "resolu"]

    if alertes:
        sujet = f"[CASA][ALERTE] {len(alertes)} problème(s) détecté(s)"
    elif resolus:
        sujet = "[CASA][RÉSOLU]"
    elif rapport_hebdo is not None:
        sujet = "[CASA][OK] rapport hebdomadaire"
    else:
        return None

    lignes = [f"Serveur : {NOM_SERVEUR}", f"Horodatage : {now.isoformat()}", ""]
    for i in alertes:
        prefixe = "NOUVEAU" if i["type"] == "nouveau" else "RAPPEL"
        lignes.append(f"[{prefixe}] {i['libelle']} : {i['detail']} — {i['action']}")
    for i in resolus:
        lignes.append(f"[RÉSOLU] {i['libelle']} : {i['detail']}")
    if rapport_hebdo is not None:
        if alertes or resolus:
            lignes.append("")
        lignes.append("Rapport hebdomadaire (preuve que le canal d'alerte fonctionne) :")
        for r in rapport_hebdo["resultats"]:
            etat_txt = "OK" if r["ok"] else "EN ALERTE"
            lignes.append(f"  - {r['libelle']} : {r['detail']} ({etat_txt})")

    msg = EmailMessage()
    msg["Subject"] = sujet
    msg["From"] = from_addr
    msg["To"] = ", ".join(destinataires)
    msg.set_content("\n".join(lignes) + "\n")
    return msg


# --- Programme principal ----------------------------------------------------

def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Alerte hôte CASA (disque, sauvegarde, conteneurs).")
    parser.add_argument("--tester-envoi", action="store_true",
                         help="Envoie un message de test aux destinataires et termine.")
    args = parser.parse_args(argv)

    os.makedirs(ETAT_DIR, exist_ok=True)
    lock_path = os.path.join(ETAT_DIR, ".alerte-casa.lock")
    try:
        lock_fd = open(lock_path, "w")
    except OSError as e:
        log(f"ERREUR : impossible d'ouvrir le fichier de verrou ({e}).")
        return 2
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        log("ERREUR : une autre exécution est déjà en cours (verrou pris).")
        lock_fd.close()
        return 2

    try:
        if not DESTINATAIRES_BRUT.strip():
            log("ERREUR : ALERTE_DESTINATAIRES n'est pas défini.")
            return 1
        destinataires = [d.strip() for d in DESTINATAIRES_BRUT.split(",") if d.strip()]

        try:
            valeurs = lire_env_production(ENV_FILE)
        except OSError:
            log(f"ERREUR : fichier d'environnement illisible ({ENV_FILE}).")
            return 3

        try:
            smtp_config = extraire_config_smtp(valeurs)
        except ValueError as e:
            log(f"ERREUR : {e}")
            return 1

        if args.tester_envoi:
            msg = EmailMessage()
            msg["Subject"] = "[CASA][TEST] message de test alerte-casa.py"
            msg["From"] = smtp_config["from"]
            msg["To"] = ", ".join(destinataires)
            msg.set_content(
                f"Message de test envoyé le {maintenant().isoformat()} depuis {NOM_SERVEUR}.\n"
                "Si vous recevez ceci, le canal d'alerte CASA fonctionne.\n"
            )
            ok = envoyer(msg, destinataires, smtp_config)
            log("Test d'envoi " + ("réussi." if ok else "ÉCHOUÉ."))
            return 0 if ok else 4

        now = maintenant()
        resultats = evaluer_controles()
        items, a_marquer = traiter_controles(resultats, now)
        rapport_hebdo = verifier_rapport_hebdo(now, resultats)

        message = construire_message(items, rapport_hebdo, now, smtp_config["from"], destinataires)
        if message is None:
            log("Aucune alerte, aucun rappel, aucun rapport dû — rien à envoyer.")
            return 0

        ok = envoyer(message, destinataires, smtp_config)
        if not ok:
            log("ÉCHEC d'envoi — aucun état marqué, nouvel essai à la prochaine exécution.")
            return 4

        for cle, action, donnees in a_marquer:
            if action == "ecrire":
                if not ecrire_etat(cle, donnees):
                    log(f"AVERTISSEMENT : écriture d'état impossible pour {cle} (disque plein ?) — "
                        "l'envoi a quand même eu lieu (doublon possible au prochain passage).")
            else:
                supprimer_etat(cle)
        if rapport_hebdo is not None:
            if not ecrire_etat("rapport_hebdo", {"dernier_envoi": now.isoformat()}):
                log("AVERTISSEMENT : écriture de l'état du rapport hebdomadaire impossible.")

        log(f"Envoi réussi : {message['Subject']}")
        return 0
    finally:
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_UN)
        finally:
            lock_fd.close()


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
