import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = json.loads((ROOT / "pass50_nouveaux_candidats_90_v19.json").read_text(encoding="utf-8"))
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
SOURCE = (ROOT / "api" / "live-radar-v4-source.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
OVERLAY = (ROOT / "profile-sara.js").read_text(encoding="utf-8")


class SaraCanonicalNameTests(unittest.TestCase):
    def test_census_public_name_is_sara_not_sarara(self):
        matches = [item for item in CENSUS if item.get("id") == "census-sarara-messan"]
        self.assertEqual(len(matches), 1)
        profile = matches[0]
        self.assertEqual(profile["name"], "Sara")
        self.assertNotIn("Sarara", profile["name"])
        self.assertIn("@sarra_messan", profile["known_alias"])
        self.assertEqual(
            profile["official_socials"]["TikTok"],
            "https://www.tiktok.com/@sarra_messan",
        )
        self.assertNotIn("influenceur", json.dumps(profile, ensure_ascii=False).lower())

    def test_overlay_forces_public_name_without_touching_ranking(self):
        self.assertIn("const PROFILE_ID='census-sarara-messan'", OVERLAY)
        self.assertIn("const PUBLIC_NAME='Sara'", OVERLAY)
        self.assertIn("if(profile.name!==PUBLIC_NAME){profile.name=PUBLIC_NAME;changed=true;}", OVERLAY)
        apply_body = OVERLAY.split("function applyProfile", 1)[1]
        self.assertNotIn("eligible:false", apply_body)
        self.assertNotIn("classable:false", apply_body)
        self.assertNotIn("ensureManualLive", OVERLAY)
        self.assertIn("birthDate:null", OVERLAY)
        self.assertIn("birthYear:null", OVERLAY)
        self.assertIn("ageStatus:'unconfirmed'", OVERLAY)
        self.assertNotIn("saraidhologne", OVERLAY)

    def test_importer_and_radar_use_sara(self):
        self.assertIn("profileItem.name='Sara'", V9)
        self.assertIn("'sarara'", V9)
        self.assertIn("['id'=>'census-sarara-messan','name'=>'Sara','handle'=>'sarra_messan']", SOURCE)
        self.assertNotIn("'name'=>'Sarara Messan'", SOURCE)

    def test_loader_is_cache_busted(self):
        self.assertIn("./profile-sara.js?v=1.0", CONFIG)
        self.assertIn("./profile-sara.js?v=1.0", SW)
        self.assertIn("pass50_nouveaux_candidats_90_v19.json?v=22.21", V9)
        self.assertIn("CENSUS_VERSION='99-v38'", V9)

    def test_public_ranking_renames_sarara_to_sara(self):
        ranking = (ROOT / "api" / "public-ranking-core.php").read_text(encoding="utf-8")
        self.assertIn("function p50_public_ranking_canonical_name", ranking)
        self.assertIn("return 'Sara'", ranking)
        self.assertIn("'sarara'", ranking)


if __name__ == "__main__":
    unittest.main()
