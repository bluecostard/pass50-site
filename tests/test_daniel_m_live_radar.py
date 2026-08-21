import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources, load_p0_youtube_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-daniel-m.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')


class DanielMLiveRadarTests(unittest.TestCase):
    def test_profile_loader_networks(self):
        self.assertIn("const PROFILE_ID='census-daniel-m'", PROFILE)
        self.assertIn('https://www.tiktok.com/@_michael_daniel', PROFILE)
        self.assertIn('https://www.youtube.com/@wisdombydaniel.m', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertIn("birthDate:null", PROFILE)
        self.assertIn("birthYear:null", PROFILE)
        self.assertNotIn('+225', PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-daniel-m'", SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0])
        self.assertIn("'census-daniel-m'", SOURCE.split('P50_LIVE_V4_P0_YOUTUBE', 1)[1].split('];', 1)[0])
        self.assertIn("'census-daniel-m|tiktok'=>'https://www.tiktok.com/@_michael_daniel'", SOURCE)
        self.assertIn("'census-daniel-m|youtube'=>'https://www.youtube.com/@wisdombydaniel.m'", SOURCE)
        self.assertIn("'handle'=>'_michael_daniel'", SOURCE)
        self.assertIn("'census-daniel-m'", CORE)
        self.assertIn('_michael_daniel', CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-daniel-m.js?v=1.0', CONFIG)

    def test_github_webcast_includes_handle(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        self.assertEqual(handles.get('_michael_daniel'), 'census-daniel-m')
        yt_ids = {row['profileId'] for row in load_p0_youtube_sources(SOURCE)}
        self.assertIn('census-daniel-m', yt_ids)


if __name__ == '__main__':
    unittest.main()
