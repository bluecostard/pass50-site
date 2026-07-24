#!/usr/bin/env python3
"""Applique PASS50 V24 dans un dépôt pass50-site existant."""
from pathlib import Path
import argparse, datetime, re, shutil, sys

MARKER_START = "# BEGIN PASS50 V24 SECURITY"
MARKER_END = "# END PASS50 V24 SECURITY"
SCRIPT_TAG = '<script src="./pass50-candidats-v24.js?v=24"></script>'

def backup(path: Path) -> Path:
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    target = path.with_name(path.name + f".backup-{stamp}")
    shutil.copy2(path, target)
    return target

def apply(root: Path, no_htaccess: bool = False):
    package = Path(__file__).resolve().parent
    index = root / "index.html"
    if not index.exists():
        raise SystemExit(f"index.html introuvable dans {root}")

    js_source = package / "pass50-candidats-v24.js"
    js_target = root / js_source.name
    shutil.copy2(js_source, js_target)

    index_text = index.read_text(encoding="utf-8")
    if SCRIPT_TAG not in index_text:
        index_backup = backup(index)
        pattern = re.compile(r'(<script\s+src=["\']\./v9-tools\.js[^"\']*["\']\s*></script>)', re.I)
        match = pattern.search(index_text)
        if not match:
            raise SystemExit("Balise v9-tools.js introuvable : insertion automatique annulée.")
        index_text = index_text[:match.end()] + "\n" + SCRIPT_TAG + index_text[match.end():]
        index.write_text(index_text, encoding="utf-8")
        print(f"✓ index.html modifié — sauvegarde : {index_backup.name}")
    else:
        print("✓ index.html déjà à jour")

    if not no_htaccess:
        block = (package / ".htaccess.pass50-v24").read_text(encoding="utf-8").strip()
        htaccess = root / ".htaccess"
        existing = htaccess.read_text(encoding="utf-8") if htaccess.exists() else ""
        if htaccess.exists():
            ht_backup = backup(htaccess)
            print(f"✓ sauvegarde .htaccess : {ht_backup.name}")
        if MARKER_START in existing and MARKER_END in existing:
            existing = re.sub(
                re.escape(MARKER_START) + r".*?" + re.escape(MARKER_END),
                block,
                existing,
                flags=re.S,
            )
        else:
            existing = existing.rstrip() + ("\n\n" if existing.strip() else "") + block + "\n"
        htaccess.write_text(existing, encoding="utf-8")
        print("✓ bloc de sécurité ajouté à .htaccess")
    else:
        print("– .htaccess non modifié (--no-htaccess)")

    print("✓ pass50-candidats-v24.js copié")
    print("\nDéploiement terminé côté fichiers.")
    print("1. Activez d'abord le certificat SSL dans IONOS.")
    print("2. Téléversez/commitez les fichiers modifiés.")
    print("3. Rechargez PASS50 en étant connecté comme propriétaire.")
    print("4. Vérifiez les 6 profils dans Administration → Influenceurs.")

def check(root: Path):
    errors = []
    index = root / "index.html"
    js = root / "pass50-candidats-v24.js"
    htaccess = root / ".htaccess"
    if not index.exists() or SCRIPT_TAG not in index.read_text(encoding="utf-8"):
        errors.append("balise V24 absente de index.html")
    if not js.exists():
        errors.append("pass50-candidats-v24.js absent")
    if htaccess.exists() and MARKER_START not in htaccess.read_text(encoding="utf-8"):
        errors.append("bloc de sécurité absent de .htaccess")
    if errors:
        print("ÉCHEC : " + " ; ".join(errors))
        return 1
    print("OK : correctif PASS50 V24 détecté.")
    return 0

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("root", nargs="?", default=".", help="racine du dépôt PASS50")
    parser.add_argument("--no-htaccess", action="store_true")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    root = Path(args.root).resolve()
    if args.check:
        raise SystemExit(check(root))
    apply(root, args.no_htaccess)

if __name__ == "__main__":
    main()
