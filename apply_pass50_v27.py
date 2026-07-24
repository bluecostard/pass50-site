#!/usr/bin/env python3
"""Applique PASS50 V27 directement aux fichiers déjà chargés par le site."""
from __future__ import annotations

from pathlib import Path
import argparse
import datetime
import json
import re
import shutil
import sys

BEGIN = "/* BEGIN PASS50 V27 REAL FIX"
END = "/* END PASS50 V27 REAL FIX */"
EXPECTED_IDS = {
    "census-african-ryou",
    "census-samuella-kouassi",
    "census-nadiani",
    "census-investisseur-africain",
    "census-laura-ziehi",
    "census-aya-robert",
}

def backup(path: Path) -> Path:
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    target = path.with_name(path.name + f".backup-{stamp}")
    shutil.copy2(path, target)
    return target

def replace_v27_block(text: str, block: str) -> str:
    if BEGIN in text and END in text:
        pattern = re.compile(
            re.escape(BEGIN) + r".*?" + re.escape(END),
            flags=re.S,
        )
        return pattern.sub(block.strip(), text)
    return text.rstrip() + "\n\n" + block.strip() + "\n"

def merge_candidates(json_path: Path, additions: list[dict]) -> tuple[int, int]:
    if not json_path.exists():
        raise FileNotFoundError(
            f"{json_path.name} est introuvable. Le dépôt n'est pas la version PASS50 attendue."
        )
    data = json.loads(json_path.read_text(encoding="utf-8"))
    if not isinstance(data, list):
        raise ValueError("Le fichier de recensement doit contenir une liste JSON.")

    existing_ids = {str(item.get("id", "")).lower() for item in data if isinstance(item, dict)}
    existing_names = {
        re.sub(r"[^a-z0-9]+", "", str(item.get("name", "")).lower())
        for item in data if isinstance(item, dict)
    }

    added = 0
    for item in additions:
        item_id = str(item.get("id", "")).lower()
        item_name = re.sub(r"[^a-z0-9]+", "", str(item.get("name", "")).lower())
        if item_id in existing_ids or item_name in existing_names:
            continue
        data.append(item)
        existing_ids.add(item_id)
        existing_names.add(item_name)
        added += 1

    json_path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    return added, len(data)

def install(root: Path) -> int:
    package = Path(__file__).resolve().parent
    index = root / "index.html"
    tools = root / "v9-tools.js"
    census = root / "pass50_nouveaux_candidats_90_v19.json"

    missing = [path.name for path in (index, tools, census) if not path.exists()]
    if missing:
        print("ERREUR : fichiers introuvables : " + ", ".join(missing), file=sys.stderr)
        print("Lancez ce script à la racine du dépôt pass50-site.", file=sys.stderr)
        return 1

    additions = json.loads(
        (package / "pass50_candidats_v27.json").read_text(encoding="utf-8")
    )
    block = (package / "PASS50_V27_AJOUTER_FIN_V9_TOOLS.js").read_text(
        encoding="utf-8"
    )

    index_backup = backup(index)
    tools_backup = backup(tools)
    census_backup = backup(census)

    # 1) Patch dans le fichier déjà chargé par PASS50.
    tools_text = tools.read_text(encoding="utf-8")
    tools_text = replace_v27_block(tools_text, block)
    tools_text = re.sub(
        r"const\s+CENSUS_URL\s*=\s*['\"][^'\"]+['\"]\s*;",
        "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=27.0';",
        tools_text,
        count=1,
    )
    tools_text = re.sub(
        r"const\s+CENSUS_VERSION\s*=\s*['\"][^'\"]+['\"]\s*;",
        "const CENSUS_VERSION='96-v27';",
        tools_text,
        count=1,
    )
    tools.write_text(tools_text, encoding="utf-8")

    # 2) Ajout permanent dans le recensement existant.
    added, census_total = merge_candidates(census, additions)

    # 3) Cache-busting : le navigateur doit réellement charger le fichier modifié.
    index_text = index.read_text(encoding="utf-8")
    index_text = re.sub(
        r'(<script\s+src=["\']\./v9-tools\.js\?v=)[^"\']+(["\']\s*></script>)',
        r'\g<1>27.0\g<2>',
        index_text,
        count=1,
        flags=re.I,
    )

    # Retirer les anciens essais annexes, afin qu'ils ne se contredisent pas.
    index_text = re.sub(
        r'\s*<script\s+src=["\']\./(?:pass50-candidats-v24|pass50-hotfix-v25|pass50-import-v26)\.js[^"\']*["\']\s*></script>',
        "",
        index_text,
        flags=re.I,
    )
    index.write_text(index_text, encoding="utf-8")

    # 4) Forcer également un nouveau cache de service worker lorsqu'il existe.
    sw = root / "sw.js"
    sw_backup = None
    if sw.exists():
        sw_backup = backup(sw)
        sw_text = sw.read_text(encoding="utf-8")
        sw_text = re.sub(
            r"(v9-tools\.js\?v=)[0-9A-Za-z._-]+",
            r"\g<1>27.0",
            sw_text,
        )
        patterns = [
            r'(const\s+CACHE_NAME\s*=\s*["\'])([^"\']+)(["\'])',
            r'(const\s+CACHE\s*=\s*["\'])([^"\']+)(["\'])',
            r'(let\s+CACHE_NAME\s*=\s*["\'])([^"\']+)(["\'])',
        ]
        replaced = False
        for pattern in patterns:
            sw_text, count = re.subn(
                pattern,
                lambda match: match.group(1) + "pass50-v27-real-fix" + match.group(3),
                sw_text,
                count=1,
                flags=re.I,
            )
            if count:
                replaced = True
                break
        if not replaced:
            sw_text = sw_text.rstrip() + "\n\n// PASS50 V27 cache-bust\n"
        sw.write_text(sw_text, encoding="utf-8")

    print("✓ Correctif ajouté directement à v9-tools.js")
    print(f"✓ Recensement mis à jour : {added} ajout(s), {census_total} entrées dans le JSON")
    print("✓ index.html charge maintenant v9-tools.js?v=27.0")
    if sw_backup:
        print("✓ Cache du service worker actualisé")
    print("\nSauvegardes créées :")
    print(f"  - {index_backup.name}")
    print(f"  - {tools_backup.name}")
    print(f"  - {census_backup.name}")
    if sw_backup:
        print(f"  - {sw_backup.name}")
    print("\nPubliez les fichiers modifiés sur GitHub/IONOS, puis rechargez le site.")
    return 0

def check(root: Path) -> int:
    errors: list[str] = []
    index = root / "index.html"
    tools = root / "v9-tools.js"
    census = root / "pass50_nouveaux_candidats_90_v19.json"

    if not index.exists():
        errors.append("index.html absent")
    else:
        text = index.read_text(encoding="utf-8")
        if "v9-tools.js?v=27.0" not in text:
            errors.append("index.html ne charge pas v9-tools.js?v=27.0")

    if not tools.exists():
        errors.append("v9-tools.js absent")
    else:
        text = tools.read_text(encoding="utf-8")
        if BEGIN not in text or END not in text:
            errors.append("bloc V27 absent de v9-tools.js")
        if "CENSUS_VERSION='96-v27'" not in text:
            errors.append("version du recensement non actualisée")

    if not census.exists():
        errors.append("fichier de recensement absent")
    else:
        try:
            data = json.loads(census.read_text(encoding="utf-8"))
            ids = {str(item.get("id", "")) for item in data if isinstance(item, dict)}
            absent = EXPECTED_IDS - ids
            if absent:
                errors.append("profils absents du JSON : " + ", ".join(sorted(absent)))
        except Exception as exc:
            errors.append(f"JSON invalide : {exc}")

    if errors:
        print("ÉCHEC :")
        for error in errors:
            print("  - " + error)
        return 1

    print("OK : PASS50 V27 est installé dans les fichiers réellement chargés.")
    print("Résultat attendu sur la base actuelle : 127 → 133 profils.")
    return 0

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("root", nargs="?", default=".", help="racine du dépôt pass50-site")
    parser.add_argument("--check", action="store_true", help="contrôler sans modifier")
    args = parser.parse_args()
    root = Path(args.root).resolve()
    return check(root) if args.check else install(root)

if __name__ == "__main__":
    raise SystemExit(main())
