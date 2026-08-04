#!/usr/bin/env python3
import json
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = ROOT / "pass50_nouveaux_candidats_90_v19.json"
V9 = ROOT / "v9-tools.js"
INDEX = ROOT / "index.html"
SW = ROOT / "sw.js"

PROFILE = {
    "id": "census-andrea-naomi-ng-guessan",
    "name": "N’Guessan Andréa Naomi",
    "known_alias": "Andrea Naomi Nguessan / N'Guessan Andrea Naomi",
    "entity_type": "Personne",
    "zone": "CI",
    "category": "Voyage / Tourisme / Aventure / Lifestyle / Authenticité",
    "census_status": "Recensé confirmé — réseaux à compléter",
    "verification_priority": "P1",
    "eligible": False,
    "classable": False,
    "official_socials": {},
    "source": {
        "publisher": "Exclusif.net",
        "date": "2024-04-19",
        "url": "https://www.exclusif.net/N-guessan-Andrea-Naomi-Au-dela-des-apparences-l-authenticite-triomphe_a49153.html",
    },
    "notes": (
        "Ajout approuvé PASS50 le 4 août 2026. Influenceuse ivoirienne associée aux découvertes "
        "touristiques, à l’aventure, au lifestyle et aux contenus d’authenticité. Aucun compte social "
        "n’est enregistré tant qu’une URL officielle directe n’a pas été recoupée. Profil non classable "
        "jusqu’à validation des réseaux et des métriques récentes."
    ),
    "research_queries": [
        "\"N’Guessan Andréa Naomi\" compte officiel Instagram TikTok Facebook",
        "\"N'Guessan Andrea Naomi\" voyage aventure influenceuse ivoirienne",
        "\"Andrea Naomi Nguessan\" vidéo récente vues abonnés",
    ],
}


def normalize(value: str) -> str:
    value = unicodedata.normalize("NFD", str(value or ""))
    value = "".join(char for char in value if unicodedata.category(char) != "Mn")
    return "".join(char.lower() for char in value if char.isalnum())


def replace_required(path: Path, old: str, new: str) -> None:
    text = path.read_text(encoding="utf-8")
    if old not in text:
        raise RuntimeError(f"Valeur attendue absente dans {path}: {old}")
    path.write_text(text.replace(old, new), encoding="utf-8")


def main() -> None:
    census = json.loads(CENSUS.read_text(encoding="utf-8"))
    if not isinstance(census, list):
        raise RuntimeError("Le recensement canonique doit être une liste JSON.")

    target_names = {
        normalize(PROFILE["name"]),
        normalize("N'Guessan Andrea Naomi"),
        normalize("Andrea Naomi Nguessan"),
    }
    duplicates = [
        item
        for item in census
        if str(item.get("id", "")).lower() == PROFILE["id"]
        or normalize(item.get("name", "")) in target_names
        or any(normalize(alias) in target_names for alias in str(item.get("known_alias", "")).split("/"))
    ]
    if duplicates:
        raise RuntimeError(f"Un profil Andréa Naomi existe déjà: {duplicates}")

    census.append(PROFILE)
    CENSUS.write_text(
        json.dumps(census, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    replace_required(
        V9,
        "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.7';",
        "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.8';",
    )
    replace_required(V9, "const CENSUS_VERSION='92-v24';", "const CENSUS_VERSION='93-v25';")
    replace_required(INDEX, "v9-tools.js?v=15.4", "v9-tools.js?v=15.5")
    replace_required(SW, "v9-tools.js?v=15.4", "v9-tools.js?v=15.5")
    replace_required(
        SW,
        "pass50_nouveaux_candidats_90_v19.json?v=22.7",
        "pass50_nouveaux_candidats_90_v19.json?v=22.8",
    )

    replacements = {
        "v9-tools.js?v=15.4": "v9-tools.js?v=15.5",
        "pass50_nouveaux_candidats_90_v19.json?v=22.7": "pass50_nouveaux_candidats_90_v19.json?v=22.8",
        "CENSUS_VERSION='92-v24'": "CENSUS_VERSION='93-v25'",
        'CENSUS_VERSION="92-v24"': 'CENSUS_VERSION="93-v25"',
    }
    for path in (ROOT / "tests").rglob("*.py"):
        text = path.read_text(encoding="utf-8")
        updated = text
        for old, new in replacements.items():
            updated = updated.replace(old, new)
        if updated != text:
            path.write_text(updated, encoding="utf-8")

    print(json.dumps({
        "ok": True,
        "profileId": PROFILE["id"],
        "totalProfiles": len(census),
        "officialSocials": 0,
        "censusVersion": "93-v25",
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
