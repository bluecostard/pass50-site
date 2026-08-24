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
OVERLAY = (ROOT / "profile-willway-jordan-officiel.js").read_text(encoding="utf-8")

PROFILE_ID = "census-willway-jordan-officiel"
NAME = "Willway Jordan officiel"
HANDLE = "@jack.carter39"
TIKTOK = "https://www.tiktok.com/@jack.carter39"

FORBIDDEN = (
    "influenceur",
    "ensuremanuallive",
    "whatsapp",
    "tel:",
    "jack carter",
)


class WillwayJordanOfficielCensusTests(unittest.TestCase):
    def census_row(self):
        matches = [item for item in CENSUS if item.get("id") == PROFILE_ID]
        self.assertEqual(len(matches), 1)
        return matches[0]

    def test_unique_and_not_jordan_evraa(self):
        profile = self.census_row()
        self.assertEqual(profile["name"], NAME)
        by_handle = [
            item
            for item in CENSUS
            if HANDLE in str(item.get("official_socials", {}))
            or HANDLE in str(item.get("known_alias", ""))
        ]
        self.assertEqual(len(by_handle), 1)
        evraa = next(item for item in CENSUS if item.get("id") == "census-jordan-evraa")
        self.assertNotEqual(evraa["id"], PROFILE_ID)
        self.assertNotIn("jack.carter39", str(evraa.get("official_socials", {})).lower())
        self.assertNotIn("realjordanevraa", str(profile.get("official_socials", {})).lower())

    def test_identity_tiktok_and_unclassable(self):
        profile = self.census_row()
        self.assertEqual(profile["zone"], "CI")
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

    def test_overlay_matches_census(self):
        self.assertIn(f"const PROFILE_ID='{PROFILE_ID}'", OVERLAY)
        self.assertIn(f"const TIKTOK_URL='{TIKTOK}'", OVERLAY)
        self.assertIn(f"handle:'{HANDLE}'", OVERLAY)
        self.assertIn("occupation:'Créateur TikTok'", OVERLAY)
        self.assertIn("birthDate:null", OVERLAY)
        self.assertIn("birthYear:null", OVERLAY)
        self.assertIn("ageStatus:'unconfirmed'", OVERLAY)
        self.assertIn("eligible:false", OVERLAY.split("function applyProfile", 1)[0])
        self.assertIn("classable:false", OVERLAY.split("function applyProfile", 1)[0])
        apply_body = OVERLAY.split("function applyProfile", 1)[1]
        self.assertNotIn("eligible:false", apply_body)
        self.assertNotIn("classable:false", apply_body)
        self.assertNotIn("ensureManualLive", OVERLAY)
        self.assertIn("'pass50:cloud-ready'", OVERLAY)

    def test_loaders_and_persistence(self):
        self.assertIn("./profile-willway-jordan-officiel.js?v=1.0", CONFIG)
        self.assertIn("./profile-willway-jordan-officiel.js?v=1.0", SW)
        self.assertIn(f'"id":"{PROFILE_ID}"', V9)
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.18", V9)
        self.assertIn("CENSUS_VERSION='99-v35'", V9)
        self.assertIn("v9-tools.js?v=15.38", INDEX)
        self.assertIn("v9-tools.js?v=15.38", SW)
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.18", SW)


if __name__ == "__main__":
    unittest.main()
