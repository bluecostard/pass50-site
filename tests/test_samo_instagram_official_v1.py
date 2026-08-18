from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PROFILE = (ROOT / "profile-samo-samo.js").read_text(encoding="utf-8")
LOCK = (ROOT / "owner-current-links-lock-v1.js").read_text(encoding="utf-8")
PROTECT = (ROOT / "official-links-protection-v4.js").read_text(encoding="utf-8")
SOCIAL = (ROOT / "api/social-links.php").read_text(encoding="utf-8")
BATCH = (ROOT / "api/official-links-batch-owner-cron-v1.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class SamoInstagramOfficialV1Tests(unittest.TestCase):
    def test_official_instagram_is_kommander_samo_samo(self):
        official = "https://www.instagram.com/kommander_samo_samo/"
        self.assertIn(official, PROFILE)
        self.assertIn(official, LOCK)
        self.assertIn(official, PROTECT)
        self.assertIn(official, BATCH)
        self.assertNotIn("instagram.com/kommandersamosamo/", PROFILE)

    def test_owner_can_replace_a_locked_official_url(self):
        self.assertIn("$ownerReplace=", SOCIAL)
        self.assertIn("isDirectOfficial", LOCK)
        self.assertIn("staleInstagram", PROFILE)

    def test_cache_busts_samo_and_protection(self):
        self.assertIn("profile-samo-samo.js?v=1.1", CONFIG)
        self.assertIn("profile-samo-samo.js?v=1.1", SW)
        self.assertIn("PASS50-OFFICIAL-LINKS-PROTECTION-V4.4", PROTECT)


if __name__ == "__main__":
    unittest.main()
