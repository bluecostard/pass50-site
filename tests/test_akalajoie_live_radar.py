import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-akalajoie.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')


class AkaLaJoieLiveRadarTests(unittest.TestCase):
    def test_profile_loader_tiktok(self):
        self.assertIn("const PROFILE_ID='census-akalajoie'", PROFILE)
        self.assertIn('https://www.tiktok.com/@akalajoie', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertIn("birthDate:null", PROFILE)
        self.assertIn("birthYear:null", PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-akalajoie'", SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0])
        self.assertIn("'census-akalajoie|tiktok'=>'https://www.tiktok.com/@akalajoie'", SOURCE)
        self.assertIn("'handle'=>'akalajoie'", SOURCE)
        self.assertIn("'census-akalajoie'", CORE)
        self.assertIn('akalajoie', CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-akalajoie.js?v=1.0', CONFIG)

    def test_github_webcast_includes_handle(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        self.assertEqual(handles.get('akalajoie'), 'census-akalajoie')


if __name__ == '__main__':
    unittest.main()
