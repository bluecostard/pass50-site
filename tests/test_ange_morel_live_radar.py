import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-ange-morel.js').read_text(encoding='utf-8')


class AngeMorelLiveRadarTests(unittest.TestCase):
    def test_profile_loader_tiktok(self):
        self.assertIn("const PROFILE_ID='census-ange-morel'", PROFILE)
        self.assertIn('https://www.tiktok.com/@angemorel4', PROFILE)
        self.assertIn('closeStuckManualLive', PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-ange-morel'", SOURCE)
        self.assertIn("'census-ange-morel|tiktok'=>'https://www.tiktok.com/@angemorel4'", SOURCE)
        self.assertIn("'handle'=>'angemorel4'", SOURCE)
        self.assertIn("'census-ange-morel'", CORE)
        self.assertIn('angemorel4', CORE)


if __name__ == '__main__':
    unittest.main()
