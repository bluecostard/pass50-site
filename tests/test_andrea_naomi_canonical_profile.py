import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS_PATH = ROOT / "pass50_nouveaux_candidats_90_v19.json"
V9_PATH = ROOT / "v9-tools.js"
INDEX_PATH = ROOT / "index.html"
SW_PATH = ROOT / "sw.js"


class AndreaNaomiCanonicalProfileTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.census = json.loads(CENSUS_PATH.read_text(encoding="utf-8"))
        cls.v9 = V9_PATH.read_text(encoding="utf-8")
        cls.index = INDEX_PATH.read_text(encoding="utf-8")
        cls.sw = SW_PATH.read_text(encoding="utf-8")

    def test_profile_is_present_once_in_canonical_census(self):
        matches = [
            item
            for item in self.census
            if item.get("id") == "census-andrea-naomi-ng-guessan"
            or item.get("name") == "N’Guessan Andréa Naomi"
        ]
        self.assertEqual(len(matches), 1)

    def test_profile_is_non_classable_until_social_validation(self):
        profile = next(
            item for item in self.census
            if item.get("id") == "census-andrea-naomi-ng-guessan"
        )
        self.assertEqual(profile["name"], "N’Guessan Andréa Naomi")
        self.assertEqual(profile["zone"], "CI")
        self.assertEqual(profile["verification_priority"], "P1")
        self.assertFalse(profile["eligible"])
        self.assertFalse(profile["classable"])
        self.assertEqual(profile["official_socials"], {})
        self.assertIn("Voyage", profile["category"])
        self.assertIn("réseaux à compléter", profile["census_status"])

    def test_source_and_no_unverified_social_are_recorded(self):
        profile = next(
            item for item in self.census
            if item.get("id") == "census-andrea-naomi-ng-guessan"
        )
        self.assertEqual(profile["source"]["publisher"], "Exclusif.net")
        self.assertEqual(profile["source"]["date"], "2024-04-19")
        self.assertIn("exclusif.net/N-guessan-Andrea-Naomi", profile["source"]["url"])
        self.assertIn("Aucun compte social", profile["notes"])

    def test_browser_loads_the_new_census_revision(self):
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.21", self.v9)
        self.assertIn("CENSUS_VERSION='99-v38'", self.v9)
        index_version = re.search(r"v9-tools\.js\?v=([0-9.]+)", self.index)
        worker_version = re.search(r"v9-tools\.js\?v=([0-9.]+)", self.sw)
        self.assertIsNotNone(index_version)
        self.assertIsNotNone(worker_version)
        self.assertEqual(index_version.group(1), worker_version.group(1))
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.21", self.sw)


if __name__ == "__main__":
    unittest.main()
