import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-rosemark-marcel.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')


class RosemarkMarcelLiveRadarTests(unittest.TestCase):
    def test_profile_loader_networks(self):
        self.assertIn("const PROFILE_ID='census-rosemark-marcel'", PROFILE)
        self.assertIn('https://www.tiktok.com/@rosemarkmarcel', PROFILE)
        self.assertIn('https://www.instagram.com/marcel_rosemark_officiel/', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-rosemark-marcel'", SOURCE)
        self.assertIn("'census-rosemark-marcel|tiktok'=>'https://www.tiktok.com/@rosemarkmarcel'", SOURCE)
        self.assertIn("'handle'=>'rosemarkmarcel'", SOURCE)
        self.assertIn("'census-rosemark-marcel'", CORE)
        self.assertIn('rosemarkmarcel', CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-rosemark-marcel.js?v=1.0', CONFIG)


if __name__ == '__main__':
    unittest.main()
