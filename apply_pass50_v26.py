#!/usr/bin/env python3
from pathlib import Path
import argparse
import datetime
import re
import shutil
import sys

SCRIPT_TAG = '<script src="./pass50-import-v26.js?v=26.1"></script>'

def backup(path: Path) -> Path:
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    target = path.with_name(path.name + f".backup-{stamp}")
    shutil.copy2(path, target)
    return target

def install(site: Path) -> int:
    package = Path(__file__).resolve().parent
    index = site / "index.html"
    if not index.exists():
        print(f"ERREUR : index.html introuvable dans {site}", file=sys.stderr)
        return 1

    shutil.copy2(package / "pass50-import-v26.js", site / "pass50-import-v26.js")
    shutil.copy2(
        package / "pass50_nouveaux_candidats_6_v26.json",
        site / "pass50_nouveaux_candidats_6_v26.json",
    )

    html = index.read_text(encoding="utf-8")
    html = re.sub(
        r"\s*<script\s+src=[\"']\./pass50-import-v26\.js[^\"']*[\"']\s*></script>",
        "",
        html,
        flags=re.I,
    )

    marker = re.compile(
        r"(<script\s+src=[\"']\./v9-tools\.js[^\"']*[\"']\s*></script>)",
        re.I,
    )
    match = marker.search(html)
    if not match:
        print("ERREUR : la balise v9-tools.js est introuvable.", file=sys.stderr)
        return 1

    saved = backup(index)
    html = html[:match.end()] + "\n" + SCRIPT_TAG + html[match.end():]
    index.write_text(html, encoding="utf-8")

    sw = site / "sw.js"
    if sw.exists():
        sw_text = sw.read_text(encoding="utf-8")
        sw_saved = backup(sw)
        replaced = False
        patterns = [
            r"(const\s+CACHE_NAME\s*=\s*[\"'])([^\"']+)([\"'])",
            r"(const\s+CACHE\s*=\s*[\"'])([^\"']+)([\"'])",
            r"(let\s+CACHE_NAME\s*=\s*[\"'])([^\"']+)([\"'])",
        ]
        for pattern in patterns:
            next_text, count = re.subn(
                pattern,
                lambda m: m.group(1) + "pass50-v26-133" + m.group(3),
                sw_text,
                count=1,
                flags=re.I,
            )
            if count:
                sw_text = next_text
                replaced = True
                break
        if not replaced:
            sw_text = sw_text.rstrip() + "\n\n// PASS50 V26 — cache-bust 127-vers-133\n"
        sw.write_text(sw_text, encoding="utf-8")
        print(f"✓ sw.js actualisé — sauvegarde : {sw_saved.name}")

    print("✓ pass50-import-v26.js copié")
    print("✓ pass50_nouveaux_candidats_6_v26.json copié")
    print(f"✓ index.html modifié — sauvegarde : {saved.name}")
    print("\nAprès publication :")
    print("1. Ouvrir PASS50 en étant connecté comme propriétaire.")
    print("2. Attendre le message « 133 profils recensés · 6/6 présents ».")
    print("3. Administration → Influenceurs doit afficher V26 · 6/6.")
    print("4. Administration → Data Hub → Synchroniser les profils si nécessaire.")
    return 0

def check(site: Path) -> int:
    errors = []
    index = site / "index.html"
    js = site / "pass50-import-v26.js"
    data = site / "pass50_nouveaux_candidats_6_v26.json"

    if not index.exists():
        errors.append("index.html absent")
    elif SCRIPT_TAG not in index.read_text(encoding="utf-8"):
        errors.append("balise pass50-import-v26.js absente de index.html")
    if not js.exists():
        errors.append("pass50-import-v26.js absent")
    if not data.exists():
        errors.append("fichier JSON des 6 profils absent")

    if errors:
        print("ÉCHEC : " + " ; ".join(errors))
        return 1
    print("OK : PASS50 V26 est installé. Résultat attendu après chargement : 133 profils.")
    return 0

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("site", nargs="?", default=".", help="racine du dépôt PASS50")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    site = Path(args.site).resolve()
    return check(site) if args.check else install(site)

if __name__ == "__main__":
    raise SystemExit(main())
