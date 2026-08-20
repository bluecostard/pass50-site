import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-samuella-kouassi.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')


class SamuellaKouassiLiveRadarTests(unittest.TestCase):
    def test_profile_loader_networks(self):
        self.assertIn("const PROFILE_ID='census-samuella-kouassi'", PROFILE)
        self.assertIn('https://www.tiktok.com/@samuellakouassiofficiel', PROFILE)
        self.assertIn('https://www.instagram.com/samuellakouassiofficiel/', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertIn("birthDate:null", PROFILE)
        self.assertIn("birthYear:null", PROFILE)
        self.assertIn("ageStatus:'unconfirmed'", PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)
        self.assertNotIn('facebook.com', PROFILE.lower())
        self.assertIn('matchesSamuella', PROFILE)
        self.assertIn("live.profileId=PROFILE_ID", PROFILE)
        self.assertIn("value==='SA'", PROFILE)

    def test_radar_p0_and_forced_probe(self):
        p0_tiktok = SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0]
        self.assertIn("'census-samuella-kouassi'", p0_tiktok)
        self.assertIn("'census-samuella-kouassi|tiktok'=>'https://www.tiktok.com/@samuellakouassiofficiel'", SOURCE)
        self.assertIn("'census-samuella-kouassi|instagram'=>'https://www.instagram.com/samuellakouassiofficiel/'", SOURCE)
        self.assertIn("'handle'=>'samuellakouassiofficiel'", SOURCE)
        self.assertIn("'census-samuella-kouassi'", CORE)
        self.assertIn('samuellakouassiofficiel', CORE)
        self.assertIn("census-nadiani", CORE)
        self.assertNotIn("$unknownTikTok=['profile_id'=>'census-samuella-kouassi'", CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-samuella-kouassi.js?v=1.1', CONFIG)


if __name__ == '__main__':
    unittest.main()
