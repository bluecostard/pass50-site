import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CENSUS = json.loads((ROOT / 'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))
V9 = (ROOT / 'v9-tools.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
APP_CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')


class CanonicalLionelYasmineV24Tests(unittest.TestCase):
    def profile(self, profile_id):
        matches = [item for item in CENSUS if item.get('id') == profile_id]
        self.assertEqual(len(matches), 1, profile_id)
        return matches[0]

    def test_lionel_is_in_canonical_census(self):
        profile = self.profile('census-lionel-pcs')
        self.assertEqual(profile['name'], 'Lionel PCS')
        self.assertFalse(profile['eligible'])
        self.assertFalse(profile['classable'])
        self.assertGreaterEqual(len(profile['official_socials']), 3)

    def test_yasmine_is_in_canonical_census(self):
        profile = self.profile('census-yasmine-fofana')
        self.assertEqual(profile['name'], 'Yasmine Fofana')
        self.assertIn('Afrofoodie', profile['known_alias'])
        self.assertFalse(profile['eligible'])
        self.assertFalse(profile['classable'])
        self.assertGreaterEqual(len(profile['official_socials']), 5)

    def test_browser_fetches_the_new_census_revision(self):
        self.assertIn('pass50_nouveaux_candidats_90_v19.json?v=22.7', V9)
        self.assertIn("const CENSUS_VERSION='92-v24'", V9)
        self.assertIn('pass50_nouveaux_candidats_90_v19.json?v=22.7', SW)
        self.assertIn('pass50-v45-metrics-control-center-census-92-v24', SW)

    def test_public_loader_is_cache_busted(self):
        self.assertIn('public-copy-fixes.js?v=1.1', APP_CONFIG)
        self.assertIn("pass50PublicCopy = '1.1'", APP_CONFIG)
        self.assertIn('public-copy-fixes.js?v=1.1', SW)


if __name__ == '__main__':
    unittest.main()
