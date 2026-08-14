import json
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
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.13", self.v9)
        self.assertIn("CENSUS_VERSION='99-v30'", self.v9)
        index_version = re.search(r"v9-tools\.js\?v=([0-9.]+)", self.index)
        worker_version = re.search(r"v9-tools\.js\?v=([0-9.]+)", self.sw)
        self.assertIsNotNone(index_version)
        self.assertIsNotNone(worker_version)
        self.assertEqual(index_version.group(1), "15.13")
        self.assertEqual(worker_version.group(1), "15.13")
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.13", self.sw)


if __name__ == "__main__":
    unittest.main()
