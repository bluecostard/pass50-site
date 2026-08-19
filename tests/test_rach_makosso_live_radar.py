import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = json.loads((ROOT / 'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')

class RachMakossoLiveRadarTests(unittest.TestCase):
    def test_census_official_tiktok(self):
        profile = next(item for item in CENSUS if item.get('id') == 'census-rach-makosso')
        self.assertEqual(profile['name'], 'Rach Makosso')
        self.assertEqual(profile['official_socials']['TikTok'], 'https://www.tiktok.com/@rach_makosso1')

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-rach-makosso'", SOURCE)
        self.assertIn("'census-rach-makosso|tiktok'=>'https://www.tiktok.com/@rach_makosso1'", SOURCE)
        self.assertIn("'handle'=>'rach_makosso1'", SOURCE)
        self.assertIn("'census-rach-makosso'", CORE)
        self.assertIn('rach_makosso1', CORE)

if __name__ == '__main__':
    unittest.main()
