import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
ENNEMI = (ROOT / 'profile-ennemi-des-djandjou.js').read_text(encoding='utf-8')
ISOUCH = (ROOT / 'profile-isouch.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')


class EnnemiAndIsouchLiveRadarTests(unittest.TestCase):
    def test_ennemi_keeps_own_tiktok(self):
        self.assertIn("const PROFILE_ID='ennemi-des-djandjou'", ENNEMI)
        self.assertIn('https://www.tiktok.com/@ennemidesdjandjou', ENNEMI)
        self.assertIn("handle:'@ennemidesdjandjou'", ENNEMI)
        self.assertIn("verificationPriority:'P0'", ENNEMI)
        self.assertNotIn("handle:'@prince_du_pays'", ENNEMI)
        self.assertNotIn('Isouch, dit Ennemi', ENNEMI)
        self.assertNotIn('ensureManualLive', ENNEMI)

    def test_isouch_is_a_separate_p0_profile(self):
        self.assertIn("const PROFILE_ID='census-isouch'", ISOUCH)
        self.assertIn('https://www.tiktok.com/@prince_du_pays', ISOUCH)
        self.assertIn("handle:'@prince_du_pays'", ISOUCH)
        self.assertIn("name:'Isouch'", ISOUCH)
        self.assertIn("verificationPriority:'P0'", ISOUCH)
        self.assertNotIn("handle:'@ennemidesdjandjou'", ISOUCH)
        self.assertNotIn("const PROFILE_ID='ennemi-des-djandjou'", ISOUCH)
        self.assertNotIn('ensureManualLive', ISOUCH)

    def test_radar_maps_each_handle_to_its_own_fiche(self):
        self.assertIn("'ennemi-des-djandjou|tiktok'=>'https://www.tiktok.com/@ennemidesdjandjou'", SOURCE)
        self.assertIn("'census-isouch|tiktok'=>'https://www.tiktok.com/@prince_du_pays'", SOURCE)
        self.assertIn("'handle'=>'ennemidesdjandjou'", SOURCE)
        self.assertIn("'handle'=>'prince_du_pays'", SOURCE)
        self.assertNotIn("'ennemi-des-djandjou|tiktok'=>'https://www.tiktok.com/@prince_du_pays'", SOURCE)
        self.assertNotIn("'prince_du_pays'=>'ennemi-des-djandjou'", SOURCE)
        self.assertIn("'prince_du_pays'=>'census-isouch'", SOURCE)
        self.assertIn('ennemi-des-djandjou', CORE)
        self.assertIn('census-isouch', CORE)

    def test_github_webcast_keeps_handles_apart(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        self.assertEqual(handles.get('ennemidesdjandjou'), 'ennemi-des-djandjou')
        self.assertEqual(handles.get('prince_du_pays'), 'census-isouch')

    def test_loaders_are_cache_busted(self):
        self.assertIn('./profile-ennemi-des-djandjou.js?v=1.2', CONFIG)
        self.assertIn('./profile-isouch.js?v=1.0', CONFIG)
        self.assertIn('./profile-ennemi-des-djandjou.js?v=1.2', SW)
        self.assertIn('./profile-isouch.js?v=1.0', SW)


if __name__ == '__main__':
    unittest.main()
