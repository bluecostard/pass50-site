import json
import re
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = ROOT / "pass50_nouveaux_candidats_90_v19.json"
V9 = ROOT / "v9-tools.js"
INDEX = ROOT / "index.html"
SW = ROOT / "sw.js"
TEST = ROOT / "tests/test_bebe_nicapol_canonical_profile.py"
SCRIPT = ROOT / "scripts/one_shot_add_bebe_nicapol.py"

CANDIDATE = {
    "id": "census-bebe-nicapol",
    "name": "Bébé Nicapol",
    "known_alias": "Kadio Mourou Nic-Apol Christian Emmanuel / Christian Kadio / Bébé Nica / Nicapol / @BebeNicaOfficiel",
    "entity_type": "Personne",
    "zone": "BOTH",
    "category": "TikTok / Lives / Divertissement / Musique / Société",
    "census_status": "Recensé confirmé — chaîne YouTube officielle fournie par le propriétaire PASS50",
    "verification_priority": "P0",
    "eligible": False,
    "classable": False,
    "official_socials": {
        "YouTube": "https://www.youtube.com/@BebeNicaOfficiel"
    },
    "source": {
        "publisher": "Chaîne YouTube officielle fournie par le propriétaire PASS50",
        "date": "2026-08-05",
        "url": "https://www.youtube.com/@BebeNicaOfficiel",
    },
    "source_secondary": {
        "publisher": "Digital Mag Côte d’Ivoire",
        "url": "https://digitalmag.ci/reseaux-sociaux-portrait-robot-de-tiktok-en-cote-divoire/",
    },
    "source_tertiary": {
        "publisher": "Afrikahabari",
        "date": "2024-07-05",
        "url": "https://afrikahabari.com/la-biographie-nicapo-4-points-precis/",
    },
    "notes": "Ajout approuvé PASS50 le 5 août 2026. Bébé Nicapol, aussi appelé Bébé Nica ou Nicapol, est un créateur ivoirien suivi en Côte d’Ivoire et dans la diaspora. Seule la chaîne YouTube directe @BebeNicaOfficiel est enregistrée comme réseau officiel à ce stade. Les comptes TikTok, Instagram et Facebook ne sont pas ajoutés tant que leurs URLs actuelles n’ont pas été validées, plusieurs comptes ayant changé ou été suspendus. Profil non classable jusqu’à validation des métriques récentes.",
    "curated_social_sources": {
        "YouTube": {
            "url": "https://www.youtube.com/@BebeNicaOfficiel",
            "source_name": "Chaîne YouTube officielle fournie par le propriétaire PASS50",
            "source_url": "https://www.youtube.com/@BebeNicaOfficiel",
            "confidence": 100,
        }
    },
    "curated_facts": {
        "real_name": {
            "value": "Kadio Mourou Nic-Apol Christian Emmanuel",
            "source_name": "Digital Mag Côte d’Ivoire",
            "source_url": "https://digitalmag.ci/reseaux-sociaux-portrait-robot-de-tiktok-en-cote-divoire/",
            "confidence": 96,
        },
        "occupation": {
            "value": "Créateur de contenus et de directs sur les réseaux sociaux, également associé à une activité musicale.",
            "source_name": "Afrikahabari",
            "source_url": "https://afrikahabari.com/la-biographie-nicapo-4-points-precis/",
            "confidence": 92,
        },
        "diaspora_signal": {
            "value": "Profil ivoirien ayant résidé et étudié à l’étranger, tout en conservant une forte audience en Côte d’Ivoire.",
            "source_name": "Afrikahabari",
            "source_url": "https://afrikahabari.com/la-biographie-nicapo-4-points-precis/",
            "confidence": 90,
        },
    },
    "research_queries": [
        '"Bébé Nicapol" compte TikTok officiel actuel',
        '"Bébé Nica" Instagram Facebook officiel',
        '"BebeNicaOfficiel" vidéos récentes vues abonnés',
        '"nicapol21" compte officiel actuel',
    ],
}


def normalized(value=""):
    text = unicodedata.normalize("NFD", str(value))
    text = "".join(char for char in text if unicodedata.category(char) != "Mn")
    return re.sub(r"[^a-z0-9]+", "", text.lower())


def upsert_candidate():
    census = json.loads(CENSUS.read_text(encoding="utf-8"))
    youtube = CANDIDATE["official_socials"]["YouTube"]
    identity_keys = {
        normalized("Bébé Nicapol"),
        normalized("Bébé Nica"),
        normalized("Nicapol"),
        normalized("Kadio Mourou Nic-Apol Christian Emmanuel"),
        normalized("BebeNicaOfficiel"),
        normalized("nicapol21"),
    }

    matches = []
    for item in census:
        item_keys = {
            normalized(item.get("id")),
            normalized(item.get("name")),
            normalized(item.get("known_alias")),
            normalized((item.get("official_socials") or {}).get("YouTube")),
        }
        if item.get("id") == CANDIDATE["id"] or normalized(youtube) in item_keys:
            matches.append(item)
            continue
        combined = normalized(" ".join(str(item.get(key, "")) for key in ("name", "known_alias")))
        if any(key and key in combined for key in identity_keys):
            matches.append(item)

    unique_matches = []
    for item in matches:
        if item not in unique_matches:
            unique_matches.append(item)
    if len(unique_matches) > 1:
        raise RuntimeError("Plusieurs entrées potentielles Bébé Nicapol ont été détectées.")
    if unique_matches:
        census[census.index(unique_matches[0])] = CANDIDATE
    else:
        census.append(CANDIDATE)

    CENSUS.write_text(json.dumps(census, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def replace_version(path, old, new):
    text = path.read_text(encoding="utf-8")
    if new in text:
        return
    if old not in text:
        raise RuntimeError(f"Marqueur introuvable dans {path}: {old}")
    if text.count(old) != 1:
        raise RuntimeError(f"Marqueur non unique dans {path}: {old}")
    path.write_text(text.replace(old, new), encoding="utf-8")


def update_versions():
    replace_version(
        V9,
        "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.9';",
        "const CENSUS_URL='./pass50_nouveaux_candidats_90_v19.json?v=22.10';",
    )
    replace_version(V9, "const CENSUS_VERSION='95-v26';", "const CENSUS_VERSION='96-v27';")
    replace_version(INDEX, "./v9-tools.js?v=15.7", "./v9-tools.js?v=15.8")
    replace_version(SW, "./v9-tools.js?v=15.7", "./v9-tools.js?v=15.8")
    replace_version(
        SW,
        "./pass50_nouveaux_candidats_90_v19.json?v=22.9",
        "./pass50_nouveaux_candidats_90_v19.json?v=22.10",
    )

    for test_path in (ROOT / "tests").glob("test_*.py"):
        text = test_path.read_text(encoding="utf-8")
        updated = text.replace(
            "pass50_nouveaux_candidats_90_v19.json?v=22.9",
            "pass50_nouveaux_candidats_90_v19.json?v=22.10",
        )
        updated = updated.replace("CENSUS_VERSION='95-v26'", "CENSUS_VERSION='96-v27'")
        updated = updated.replace("v9-tools.js?v=15.7", "v9-tools.js?v=15.8")
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


class BebeNicapolCanonicalProfileTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.census = json.loads(CENSUS_PATH.read_text(encoding="utf-8"))
        cls.v9 = V9_PATH.read_text(encoding="utf-8")
        cls.index = INDEX_PATH.read_text(encoding="utf-8")
        cls.sw = SW_PATH.read_text(encoding="utf-8")

    def profile(self):
        return next(item for item in self.census if item.get("id") == "census-bebe-nicapol")

    def test_profile_is_present_once(self):
        matches = [
            item for item in self.census
            if item.get("id") == "census-bebe-nicapol"
            or item.get("name") == "Bébé Nicapol"
            or "BebeNicaOfficiel" in str(item.get("official_socials", {}))
        ]
        self.assertEqual(len(matches), 1)

    def test_profile_identity_and_zone_are_recorded(self):
        profile = self.profile()
        self.assertEqual(profile["name"], "Bébé Nicapol")
        self.assertEqual(profile["zone"], "BOTH")
        self.assertEqual(profile["verification_priority"], "P0")
        self.assertIn("Bébé Nica", profile["known_alias"])
        self.assertIn("Nicapol", profile["known_alias"])
        self.assertIn("Kadio Mourou Nic-Apol Christian Emmanuel", profile["known_alias"])
        self.assertFalse(profile["eligible"])
        self.assertFalse(profile["classable"])

    def test_only_official_youtube_is_registered(self):
        profile = self.profile()
        self.assertEqual(
            profile["official_socials"],
            {"YouTube": "https://www.youtube.com/@BebeNicaOfficiel"},
        )
        source = profile["curated_social_sources"]["YouTube"]
        self.assertEqual(source["url"], profile["official_socials"]["YouTube"])
        self.assertEqual(source["confidence"], 100)
        self.assertNotIn("TikTok", profile["official_socials"])
        self.assertNotIn("Instagram", profile["official_socials"])
        self.assertNotIn("Facebook", profile["official_socials"])

    def test_concordant_sources_and_real_name_are_recorded(self):
        profile = self.profile()
        self.assertIn("propriétaire PASS50", profile["source"]["publisher"])
        self.assertEqual(profile["source_secondary"]["publisher"], "Digital Mag Côte d’Ivoire")
        self.assertEqual(profile["source_tertiary"]["publisher"], "Afrikahabari")
        self.assertEqual(
            profile["curated_facts"]["real_name"]["value"],
            "Kadio Mourou Nic-Apol Christian Emmanuel",
        )

    def test_browser_loads_census_revision_96_v27(self):
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.10", self.v9)
        self.assertIn("CENSUS_VERSION='96-v27'", self.v9)
        index_version = re.search(r"v9-tools\\.js\\?v=([0-9.]+)", self.index)
        worker_version = re.search(r"v9-tools\\.js\\?v=([0-9.]+)", self.sw)
        self.assertIsNotNone(index_version)
        self.assertIsNotNone(worker_version)
        self.assertEqual(index_version.group(1), "15.8")
        self.assertEqual(worker_version.group(1), "15.8")
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.10", self.sw)


if __name__ == "__main__":
    unittest.main()
''',
        encoding="utf-8",
    )


def remove_script():
    if SCRIPT.exists():
        SCRIPT.unlink()


if __name__ == "__main__":
    upsert_candidate()
    update_versions()
    write_test()
    remove_script()
