import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = json.loads((ROOT / "pass50_nouveaux_candidats_90_v19.json").read_text(encoding="utf-8"))
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
OVERLAY = (ROOT / "profile-souley-de-paris.js").read_text(encoding="utf-8")

PROFILE_ID = "census-souley-de-paris"
NAME = "Souley de Paris"
HANDLE = "@souleydeparis"
TIKTOK = "https://www.tiktok.com/@souleydeparis"

FORBIDDEN = (
    "influenceur",
    "ensuremanuallive",
    "whatsapp",
    "tel:",
    "+225",
)


class SouleyDeParisCensusTests(unittest.TestCase):
    def census_row(self):
        matches = [item for item in CENSUS if item.get("id") == PROFILE_ID]
        self.assertEqual(len(matches), 1)
        return matches[0]

    def test_unique_handle_and_public_name(self):
        profile = self.census_row()
        self.assertEqual(profile["name"], NAME)
        by_handle = [
            item
            for item in CENSUS
            if HANDLE in str(item.get("official_socials", {}))
            or HANDLE in str(item.get("known_alias", ""))
        ]
        self.assertEqual(len(by_handle), 1)
        self.assertEqual(by_handle[0]["id"], PROFILE_ID)

    def test_identity_tiktok_and_unclassable(self):
        profile = self.census_row()
        self.assertEqual(profile["zone"], "DIASPORA")
        self.assertEqual(profile["verification_priority"], "P0")
        self.assertFalse(profile["eligible"])
        self.assertFalse(profile["classable"])
        self.assertEqual(profile["official_socials"], {"TikTok": TIKTOK})
        self.assertIn(HANDLE, profile["known_alias"])
        self.assertNotIn("birth_date", profile)
        self.assertNotIn("birth_year", profile)

    def test_no_invented_or_forbidden_identity(self):
        blobs = [
            json.dumps(self.census_row(), ensure_ascii=False).lower(),
            OVERLAY.lower(),
        ]
        for blob in blobs:
            for needle in FORBIDDEN:
                self.assertNotIn(needle, blob)
            self.assertIsNone(re.search(r"(?:\+|00)\s*225", blob))
            self.assertNotIn("source:'manual'", blob)

    def test_overlay_matches_census_exactly(self):
        self.assertIn(f"const PROFILE_ID='{PROFILE_ID}'", OVERLAY)
        self.assertIn(f"const TIKTOK_URL='{TIKTOK}'", OVERLAY)
        self.assertIn(f"handle:'{HANDLE}'", OVERLAY)
        self.assertIn("occupation:'Streamer TikTok'", OVERLAY)
        self.assertIn("region:'DIASPORA'", OVERLAY)
        self.assertIn("birthDate:null", OVERLAY)
        self.assertIn("birthYear:null", OVERLAY)
        self.assertIn("ageStatus:'unconfirmed'", OVERLAY)
        self.assertNotIn("name.includes('souley')", OVERLAY)
        self.assertNotIn("name.includes('paris')", OVERLAY)
        self.assertIn("eligible:false", OVERLAY.split("function applyProfile", 1)[0])
        self.assertIn("classable:false", OVERLAY.split("function applyProfile", 1)[0])
        apply_body = OVERLAY.split("function applyProfile", 1)[1]
        self.assertNotIn("eligible:false", apply_body)
        self.assertNotIn("classable:false", apply_body)
        self.assertNotIn("ensureManualLive", OVERLAY)
        self.assertIn("'pass50:cloud-ready'", OVERLAY)

    def test_loaders_and_persistence(self):
        self.assertIn("./profile-souley-de-paris.js?v=1.0", CONFIG)
        self.assertIn("./profile-souley-de-paris.js?v=1.0", SW)
        self.assertIn(f'"id":"{PROFILE_ID}"', V9)
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.20", V9)
        self.assertIn("CENSUS_VERSION='99-v37'", V9)
        self.assertIn("v9-tools.js?v=15.40", INDEX)
        self.assertIn("v9-tools.js?v=15.40", SW)
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.20", SW)


if __name__ == "__main__":
    unittest.main()
