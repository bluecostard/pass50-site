import json
import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
STORAGE = (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
OVERLAY = (ROOT / 'profile-willway-jordan-officiel.js').read_text(encoding='utf-8')
CENSUS = json.loads((ROOT / 'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))

PROFILE_ID = 'census-willway-jordan-officiel'
NAME = 'Willway Jordan officiel'
HANDLE = 'jack.carter39'


class WillwayJordanOfficielLiveRadarTests(unittest.TestCase):
    def test_overlay_keeps_identity_and_skips_manual_live(self):
        p0 = SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0]
        self.assertIn(f"const PROFILE_ID='{PROFILE_ID}'", OVERLAY)
        self.assertIn(f'https://www.tiktok.com/@{HANDLE}', OVERLAY)
        self.assertIn("verificationPriority:'P0'", OVERLAY)
        self.assertIn('birthDate:null', OVERLAY)
        self.assertIn('birthYear:null', OVERLAY)
        self.assertNotIn('ensureManualLive', OVERLAY)
        self.assertNotIn("source:'manual'", OVERLAY)
        self.assertNotIn('influenceur', OVERLAY.lower())
        apply = OVERLAY.split('function applyProfile', 1)[1]
        self.assertNotIn('eligible:false', apply)
        self.assertNotIn('classable:false', apply)
        self.assertIn(f"'{PROFILE_ID}'", p0)

    def test_not_merged_with_jordan_evraa(self):
        self.assertIn("'census-jordan-evraa'", SOURCE)
        self.assertIn("'realjordanevraa'", SOURCE)
        self.assertIn(f"'{HANDLE}'=>'{PROFILE_ID}'", SOURCE)
        self.assertNotIn(f"'{HANDLE}'=>'census-jordan-evraa'", SOURCE)
        profile = next(item for item in CENSUS if item.get('id') == PROFILE_ID)
        self.assertEqual(profile['name'], NAME)
        evraa = next(item for item in CENSUS if item.get('id') == 'census-jordan-evraa')
        self.assertNotEqual(evraa.get('official_socials', {}).get('TikTok'), f'https://www.tiktok.com/@{HANDLE}')

    def test_radar_p0_forced_probe_and_canonicals(self):
        tiktok = f'https://www.tiktok.com/@{HANDLE}'
        self.assertIn(f"'{PROFILE_ID}|tiktok'=>'{tiktok}'", SOURCE)
        self.assertIn(f"'handle'=>'{HANDLE}'", SOURCE)
        self.assertIn(f"'{HANDLE}'=>'{PROFILE_ID}'", SOURCE)
        self.assertIn(f"'{PROFILE_ID}'", CORE)
        self.assertIn(HANDLE, CORE)
        self.assertIn(NAME, STORAGE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-willway-jordan-officiel.js?v=1.0', CONFIG)
        self.assertIn('./profile-willway-jordan-officiel.js?v=1.0', SW)

    def test_github_webcast_includes_handle(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        self.assertEqual(handles.get(HANDLE), PROFILE_ID)
        self.assertNotEqual(handles.get(HANDLE), 'census-jordan-evraa')


if __name__ == '__main__':
    unittest.main()
