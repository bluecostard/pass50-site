import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS_PATH = ROOT / "pass50_nouveaux_candidats_90_v19.json"
V9_PATH = ROOT / "v9-tools.js"
INDEX_PATH = ROOT / "index.html"
SW_PATH = ROOT / "sw.js"
CONFIG_PATH = ROOT / "app-config.js"

PROFILES = (
    {
        "id": "census-le-grand-bicongo",
        "name": "Le grand Bicongo",
        "handle": "@legrandbicongo",
        "tiktok": "https://www.tiktok.com/@legrandbicongo",
        "overlay": "profile-le-grand-bicongo.js",
        "occupation": "Créateur TikTok ; manager de Chocolat Show",
    },
    {
        "id": "census-chocolat-show-officiel",
        "name": "Chocolat show officiel",
        "handle": "@chocolat.show.officiel",
        "tiktok": "https://www.tiktok.com/@chocolat.show.officiel",
        "overlay": "profile-chocolat-show-officiel.js",
        "occupation": "Comédien / humoriste",
    },
    {
        "id": "census-la-legende",
        "name": "La légende",
        "handle": "@lalegende777",
        "tiktok": "https://www.tiktok.com/@lalegende777",
        "overlay": "profile-la-legende.js",
        "occupation": "Humoriste",
    },
)

FORBIDDEN = (
    "influenceur",
    "traoré abou",
    "traore abou",
    "prison",
    "thierry yaké",
    "thierry yake",
    "ensuremanuallive",
    "whatsapp",
    "tel:",
)


class LeGrandBicongoChocolatLaLegendeCensusTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.census = json.loads(CENSUS_PATH.read_text(encoding="utf-8"))
        cls.v9 = V9_PATH.read_text(encoding="utf-8")
        cls.index = INDEX_PATH.read_text(encoding="utf-8")
        cls.sw = SW_PATH.read_text(encoding="utf-8")
        cls.config = CONFIG_PATH.read_text(encoding="utf-8")
        cls.overlays = {
            spec["id"]: (ROOT / spec["overlay"]).read_text(encoding="utf-8")
            for spec in PROFILES
        }

    def census_row(self, profile_id):
        matches = [item for item in self.census if item.get("id") == profile_id]
        self.assertEqual(len(matches), 1, profile_id)
        return matches[0]

    def test_each_profile_is_unique(self):
        for spec in PROFILES:
            by_id = [item for item in self.census if item.get("id") == spec["id"]]
            by_handle = [
                item
                for item in self.census
                if spec["handle"] in str(item.get("official_socials", {}))
                or spec["handle"] in str(item.get("known_alias", ""))
            ]
            self.assertEqual(len(by_id), 1, spec["id"])
            self.assertEqual(len(by_handle), 1, spec["handle"])
            self.assertEqual(by_id[0]["name"], spec["name"])

    def test_three_distinct_people(self):
        ids = [spec["id"] for spec in PROFILES]
        self.assertEqual(len(set(ids)), 3)
        names = [self.census_row(spec["id"])["name"] for spec in PROFILES]
        self.assertEqual(len(set(names)), 3)

    def test_identity_status_and_tiktok(self):
        for spec in PROFILES:
            profile = self.census_row(spec["id"])
            self.assertEqual(profile["zone"], "CI")
            self.assertEqual(profile["verification_priority"], "P0")
            self.assertFalse(profile["eligible"])
            self.assertFalse(profile["classable"])
            self.assertEqual(profile["official_socials"], {"TikTok": spec["tiktok"]})
            self.assertIn(spec["handle"], profile["known_alias"])
            self.assertNotIn("birth_date", profile)
            self.assertNotIn("birth_year", profile)

    def test_no_invented_or_forbidden_identity(self):
        blobs = [json.dumps(self.census_row(spec["id"]), ensure_ascii=False).lower() for spec in PROFILES]
        blobs.extend(source.lower() for source in self.overlays.values())
        for blob in blobs:
            for needle in FORBIDDEN:
                self.assertNotIn(needle, blob)
            self.assertIsNone(re.search(r"(?:\+|00)\s*225", blob))

    def test_overlays_match_census_and_leave_age_unconfirmed(self):
        for spec in PROFILES:
            source = self.overlays[spec["id"]]
            self.assertIn(f"const PROFILE_ID='{spec['id']}'", source)
            self.assertIn(f"const TIKTOK_URL='{spec['tiktok']}'", source)
            self.assertIn(f"handle:'{spec['handle']}'", source)
            self.assertIn(f"occupation:'{spec['occupation']}'", source)
            self.assertIn("birthDate:null", source)
            self.assertIn("birthYear:null", source)
            self.assertIn("ageStatus:'unconfirmed'", source)
            self.assertIn("eligible:false", source.split("function applyProfile", 1)[0])
            self.assertIn("classable:false", source.split("function applyProfile", 1)[0])
            apply_body = source.split("function applyProfile", 1)[1]
            self.assertNotIn("eligible:false", apply_body)
            self.assertNotIn("classable:false", apply_body)
            self.assertNotIn("ensureManualLive", source)

    def test_loaders_are_cache_busted(self):
        for spec in PROFILES:
            tag = f"./{spec['overlay']}?v=1.1"
            self.assertIn(tag, self.config)
            self.assertIn(tag, self.sw)

    def test_browser_loads_census_revision(self):
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.22", self.v9)
        self.assertIn("CENSUS_VERSION='99-v39'", self.v9)
        self.assertIn("v9-tools.js?v=15.43", self.index)
        self.assertIn("v9-tools.js?v=15.41", self.sw)
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.22", self.sw)

    def test_profiles_survive_cloud_hydrate(self):
        self.assertIn("'pass50:cloud-ready'", self.index)
        self.assertIn("120000", self.v9)
        for spec in PROFILES:
            self.assertIn(f'"id":"{spec["id"]}"', self.v9)
            source = self.overlays[spec["id"]]
            self.assertIn("'pass50:cloud-ready'", source)
            self.assertIn("setTimeout(applyProfile,800)", source)


if __name__ == "__main__":
    unittest.main()
