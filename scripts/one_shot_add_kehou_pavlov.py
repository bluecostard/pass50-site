import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = ROOT / "pass50_nouveaux_candidats_90_v19.json"
V9 = ROOT / "v9-tools.js"
INDEX = ROOT / "index.html"
SW = ROOT / "sw.js"
VALIDATION = ROOT / ".github/workflows/validate-canonical-lionel-yasmine-v24.yml"
TEST = ROOT / "tests/test_kehou_pavlov_canonical_profiles.py"
TEMP_PATHS = [
    ROOT / ".github/workflows/one-shot-add-kehou-mousso.yml",
    ROOT / ".github/workflows/one-shot-integrate-profiles.yml",
    ROOT / "scripts/one_shot_add_kehou_pavlov.py",
]

CANDIDATES = [
    {
        "id": "census-kehou-mousso",
        "name": "Kehou Mousso",
        "known_alias": "Tano Nadia Marcel / Reine du coupé-décalé / @reine_du_couper_decaler",
        "entity_type": "Personne",
        "zone": "CI",
        "category": "Danse / Biama / Coupé-décalé / Divertissement / Musique",
        "census_status": "Recensé confirmé — intégration prioritaire",
        "verification_priority": "P0",
        "eligible": False,
        "classable": False,
        "official_socials": {
            "TikTok": "https://www.tiktok.com/@reine_du_couper_decaler"
        },
        "source": {
            "publisher": "The Guardian",
            "date": "2026-08-04",
            "url": "https://www.theguardian.com/world/2026/aug/04/biama-dance-ivorian-pop-culture-cote-d-ivoire-tik-tok",
        },
        "source_secondary": {
            "publisher": "Le Monde",
            "date": "2026-04-29",
            "url": "https://www.lemonde.fr/afrique/article/2026/04/29/en-cote-d-ivoire-le-biama-a-reveille-le-coupe-decale_6684063_3212.html",
        },
        "notes": "Ajout approuvé PASS50 le 5 août 2026. Kehou Mousso est une danseuse de biama et une artiste de la scène coupé-décalé ivoirienne. Seul le compte TikTok direct @reine_du_couper_decaler est enregistré à ce stade. Profil non classable jusqu’à validation des métriques récentes.",
        "curated_social_sources": {
            "TikTok": {
                "url": "https://www.tiktok.com/@reine_du_couper_decaler",
                "source_name": "Hafi — classement TikTok Côte d’Ivoire",
                "source_url": "https://hafi.pro/top/most-followed-tiktok/ivory-coast",
                "confidence": 96,
            }
        },
        "curated_facts": {
            "cultural_position": {
                "value": "Danseuse de biama et figure féminine du coupé-décalé ivoirien.",
                "source_name": "The Guardian",
                "source_url": "https://www.theguardian.com/world/2026/aug/04/biama-dance-ivorian-pop-culture-cote-d-ivoire-tik-tok",
                "confidence": 98,
            },
            "audience_snapshot": {
                "value": "Environ 2,5 à 2,6 millions d’abonnés TikTok relevés en août 2026.",
                "source_name": "The Guardian / Hafi",
                "source_url": "https://hafi.pro/top/most-followed-tiktok/ivory-coast",
                "confidence": 94,
            },
        },
        "research_queries": [
            '"Kehou Mousso" compte officiel Instagram Facebook YouTube',
            '"reine_du_couper_decaler" Kehou Mousso TikTok',
            '"Kehou Mousso" vidéo récente vues abonnés',
        ],
    },
    {
        "id": "census-pavlov-joshua",
        "name": "Pavlov",
        "known_alias": "Joshua Pavlov / @joshua_pavlov_23",
        "entity_type": "Personne",
        "zone": "CI",
        "category": "TikTok / Divertissement",
        "census_status": "Recensé confirmé — compte TikTok fourni par le propriétaire PASS50",
        "verification_priority": "P1",
        "eligible": False,
        "classable": False,
        "official_socials": {
            "TikTok": "https://www.tiktok.com/@joshua_pavlov_23"
        },
        "source": {
            "publisher": "Compte TikTok direct fourni par le propriétaire PASS50",
            "date": "2026-08-05",
            "url": "https://www.tiktok.com/@joshua_pavlov_23",
        },
        "source_secondary": {
            "publisher": "Chartex",
            "date": "2026-08-05",
            "url": "https://chartex.com/song/ivorian-doll-babi-5377580",
        },
        "notes": "Ajout approuvé PASS50 le 5 août 2026. Le compte TikTok direct @joshua_pavlov_23 a été fourni par le propriétaire PASS50 et recoupé par Chartex, qui rattache le compte à la Côte d’Ivoire. Catégorie volontairement large tant que le positionnement éditorial précis n’est pas documenté. Profil non classable jusqu’à validation des métriques récentes.",
        "curated_social_sources": {
            "TikTok": {
                "url": "https://www.tiktok.com/@joshua_pavlov_23",
                "source_name": "Compte TikTok direct fourni par le propriétaire PASS50",
                "source_url": "https://www.tiktok.com/@joshua_pavlov_23",
                "confidence": 100,
            }
        },
        "curated_facts": {
            "country_signal": {
                "value": "Compte rattaché à la Côte d’Ivoire dans un relevé public Chartex.",
                "source_name": "Chartex",
                "source_url": "https://chartex.com/song/ivorian-doll-babi-5377580",
                "confidence": 94,
            },
            "audience_snapshot": {
                "value": "313,8 k abonnés relevés par Chartex au moment de la vérification.",
                "source_name": "Chartex",
                "source_url": "https://chartex.com/song/ivorian-doll-babi-5377580",
                "confidence": 90,
            },
        },
        "research_queries": [
            '"joshua_pavlov_23" Instagram Facebook YouTube officiel',
            '"Joshua Pavlov" influenceur ivoirien',
            '"Pavlov" "joshua_pavlov_23" vidéo récente vues',
        ],
    },
]


def normalized(value=""):
    return re.sub(r"[^a-z0-9]+", "", str(value).lower())


def upsert_candidates():
    census = json.loads(CENSUS.read_text(encoding="utf-8"))
    for candidate in CANDIDATES:
        tiktok = candidate["official_socials"]["TikTok"]
        handle = tiktok.rsplit("@", 1)[-1]
        matches = [
            item
            for item in census
            if item.get("id") == candidate["id"]
            or normalized(item.get("name")) == normalized(candidate["name"])
            or normalized((item.get("official_socials") or {}).get("TikTok")) == normalized(tiktok)
            or normalized(handle) in normalized(item.get("known_alias"))
        ]
        if len(matches) > 1:
            raise RuntimeError(f"Plusieurs entrées détectées pour {candidate['name']}.")
        if matches:
            census[census.index(matches[0])] = candidate
        else:
            census.append(candidate)
    CENSUS.write_text(json.dumps(census, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def replace_version(path, old, new):
    text = path.read_text(encoding="utf-8")
    if new in text:
        return
    if old not in text:
        raise RuntimeError(f"Marqueur introuvable dans {path}: {old}")
    path.write_text(text.replace(old, new), encoding="utf-8")


def update_versions():
    replace_version(
        V9,
        "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.8';",
        "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.9';",
    )
    replace_version(V9, "const CENSUS_VERSION='93-v25';", "const CENSUS_VERSION='95-v26';")
    replace_version(INDEX, "./v9-tools.js?v=15.6", "./v9-tools.js?v=15.7")
    replace_version(SW, "./v9-tools.js?v=15.6", "./v9-tools.js?v=15.7")
    replace_version(
        SW,
        "./pass50_nouveaux_candidats_90_v19.json?v=22.8",
        "./pass50_nouveaux_candidats_90_v19.json?v=22.9",
    )

    for test_path in (ROOT / "tests").glob("test_*.py"):
        text = test_path.read_text(encoding="utf-8")
        updated = text.replace(
            "pass50_nouveaux_candidats_90_v19.json?v=22.8",
            "pass50_nouveaux_candidats_90_v19.json?v=22.9",
        )
        updated = updated.replace("CENSUS_VERSION='93-v25'", "CENSUS_VERSION='95-v26'")
        updated = updated.replace("v9-tools.js?v=15.6", "v9-tools.js?v=15.7")
        if updated != text:
            test_path.write_text(updated, encoding="utf-8")


def write_test():
    TEST.write_text(
        '''import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS_PATH = ROOT / "pass50_nouveaux_candidats_90_v19.json"
V9_PATH = ROOT / "v9-tools.js"
INDEX_PATH = ROOT / "index.html"
SW_PATH = ROOT / "sw.js"


class KehouPavlovCanonicalProfileTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.census = json.loads(CENSUS_PATH.read_text(encoding="utf-8"))
        cls.v9 = V9_PATH.read_text(encoding="utf-8")
        cls.index = INDEX_PATH.read_text(encoding="utf-8")
        cls.sw = SW_PATH.read_text(encoding="utf-8")

    def test_profiles_are_present_once(self):
        for profile_id, name in (("census-kehou-mousso", "Kehou Mousso"), ("census-pavlov-joshua", "Pavlov")):
            matches = [item for item in self.census if item.get("id") == profile_id or item.get("name") == name]
            self.assertEqual(len(matches), 1)

    def test_profiles_are_not_classable_yet(self):
        for profile_id in ("census-kehou-mousso", "census-pavlov-joshua"):
            profile = next(item for item in self.census if item.get("id") == profile_id)
            self.assertFalse(profile["eligible"])
            self.assertFalse(profile["classable"])
            self.assertEqual(profile["zone"], "CI")

    def test_direct_tiktok_accounts_are_registered(self):
        kehou = next(item for item in self.census if item.get("id") == "census-kehou-mousso")
        pavlov = next(item for item in self.census if item.get("id") == "census-pavlov-joshua")
        self.assertEqual(kehou["official_socials"], {"TikTok": "https://www.tiktok.com/@reine_du_couper_decaler"})
        self.assertEqual(pavlov["official_socials"], {"TikTok": "https://www.tiktok.com/@joshua_pavlov_23"})
        self.assertEqual(kehou["verification_priority"], "P0")
        self.assertEqual(pavlov["verification_priority"], "P1")

    def test_sarra_messan_is_not_duplicated(self):
        variants = [item for item in self.census if "messan" in str(item.get("name", "")).lower()]
        self.assertEqual(len(variants), 1)

    def test_browser_loads_census_revision_95_v26(self):
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.9", self.v9)
        self.assertIn("CENSUS_VERSION='95-v26'", self.v9)
        index_version = re.search(r"v9-tools\\.js\\?v=([0-9.]+)", self.index)
        worker_version = re.search(r"v9-tools\\.js\\?v=([0-9.]+)", self.sw)
        self.assertIsNotNone(index_version)
        self.assertIsNotNone(worker_version)
        self.assertEqual(index_version.group(1), "15.7")
        self.assertEqual(worker_version.group(1), "15.7")
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.9", self.sw)


if __name__ == "__main__":
    unittest.main()
''',
        encoding="utf-8",
    )


def update_validation_workflow():
    text = VALIDATION.read_text(encoding="utf-8")
    text = text.replace("name: Validate Canonical Profiles V25", "name: Validate Canonical Profiles V26")
    path_line = "      - 'tests/test_kehou_pavlov_canonical_profiles.py'\n"
    if path_line not in text:
        anchor = "      - 'tests/test_andrea_naomi_canonical_profile.py'\n"
        text = text.replace(anchor, anchor + path_line)
    run_line = "          python3 -m unittest tests.test_kehou_pavlov_canonical_profiles\n"
    if run_line not in text:
        anchor = "          python3 -m unittest tests.test_andrea_naomi_canonical_profile\n"
        text = text.replace(anchor, anchor + run_line)
    VALIDATION.write_text(text, encoding="utf-8")


def remove_temporary_files():
    for path in TEMP_PATHS:
        if path.exists():
            path.unlink()


if __name__ == "__main__":
    upsert_candidates()
    update_versions()
    write_test()
    update_validation_workflow()
    remove_temporary_files()
