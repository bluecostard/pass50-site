# Contrat anti-régression des profils ajoutés le 5 août 2026.
# Exécuté par le workflow canonique permanent V27.
import json
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

    def test_browser_loads_census_revision_96_v27(self):
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.11", self.v9)
        self.assertIn("CENSUS_VERSION='97-v28'", self.v9)
        index_version = re.search(r"v9-tools\.js\?v=([0-9.]+)", self.index)
        worker_version = re.search(r"v9-tools\.js\?v=([0-9.]+)", self.sw)
        self.assertIsNotNone(index_version)
        self.assertIsNotNone(worker_version)
        self.assertEqual(index_version.group(1), "15.9")
        self.assertEqual(worker_version.group(1), "15.9")
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.11", self.sw)


if __name__ == "__main__":
    unittest.main()
