import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-laguepe.js').read_text(encoding='utf-8')


class LaguepeLiveRadarTests(unittest.TestCase):
    def test_profile_loader_tiktok(self):
        self.assertIn("const PROFILE_ID='census-laguepe'", PROFILE)
        self.assertIn('https://www.tiktok.com/@laguepe03', PROFILE)

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-laguepe'", SOURCE)
        self.assertIn("'census-laguepe|tiktok'=>'https://www.tiktok.com/@laguepe03'", SOURCE)
        self.assertIn("'handle'=>'laguepe03'", SOURCE)
        self.assertIn("'census-laguepe'", CORE)
        self.assertIn('laguepe03', CORE)


if __name__ == '__main__':
    unittest.main()
