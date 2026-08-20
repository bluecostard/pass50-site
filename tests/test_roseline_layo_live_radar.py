import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = json.loads((ROOT / 'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')

class RoselineLayoLiveRadarTests(unittest.TestCase):
    def test_census_official_tiktok(self):
        profile = next(item for item in CENSUS if item.get('id') == 'census-roseline-layo')
        self.assertEqual(profile['name'], 'Roseline Layo')
        self.assertEqual(profile['official_socials']['TikTok'], 'https://www.tiktok.com/@roselinelayoofficiel')

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-roseline-layo'", SOURCE)
        self.assertIn("'census-roseline-layo|tiktok'=>'https://www.tiktok.com/@roselinelayoofficiel'", SOURCE)
        self.assertIn("'handle'=>'roselinelayoofficiel'", SOURCE)
        self.assertIn("'census-roseline-layo'", CORE)
        self.assertIn('roselinelayoofficiel', CORE)

if __name__ == '__main__':
    unittest.main()
