import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-jiaan-wu.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')


class JiaanWuLiveRadarTests(unittest.TestCase):
    def test_profile_loader_networks(self):
        self.assertIn("const PROFILE_ID='census-jiaan-wu'", PROFILE)
        self.assertIn('https://www.tiktok.com/@jiaaan.wu', PROFILE)
        self.assertIn('https://www.instagram.com/jiaaan.wu/', PROFILE)
        self.assertIn('https://www.facebook.com/jiaan.wu.203389/', PROFILE)
        self.assertIn('https://www.youtube.com/@jiaaanwu', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertIn("birthDate:null", PROFILE)
        self.assertIn("birthYear:null", PROFILE)
        self.assertNotIn('fbclid', PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-jiaan-wu'", SOURCE)
        self.assertIn("'census-jiaan-wu|tiktok'=>'https://www.tiktok.com/@jiaaan.wu'", SOURCE)
        self.assertIn("'census-jiaan-wu|youtube'=>'https://www.youtube.com/@jiaaanwu'", SOURCE)
        self.assertIn("'census-jiaan-wu|facebook'=>'https://www.facebook.com/jiaan.wu.203389/'", SOURCE)
        self.assertIn("'census-jiaan-wu|instagram'=>'https://www.instagram.com/jiaaan.wu/'", SOURCE)
        self.assertIn("'handle'=>'jiaaan.wu'", SOURCE)
        self.assertIn("'census-jiaan-wu'", SOURCE.split('P50_LIVE_V4_P0_YOUTUBE', 1)[1].split('];', 1)[0])
        self.assertIn("'census-jiaan-wu'", CORE)
        self.assertIn('jiaaan.wu', CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-jiaan-wu.js?v=1.0', CONFIG)


if __name__ == '__main__':
    unittest.main()
