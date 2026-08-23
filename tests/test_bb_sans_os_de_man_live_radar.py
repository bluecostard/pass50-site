import json
import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-bb-sans-os-de-man.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
CENSUS = json.loads((ROOT / 'pass50_nouveaux_candidats_90_v19.json').read_text(encoding='utf-8'))


class BbSansOsDeManLiveRadarTests(unittest.TestCase):
    def test_profile_loader_tiktok(self):
        self.assertIn("const PROFILE_ID='census-bb-sans-os-de-man'", PROFILE)
        self.assertIn('https://www.tiktok.com/@bebe.sans.os.de.m', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertIn("birthDate:null", PROFILE)
        self.assertIn("birthYear:null", PROFILE)
        self.assertIn("occupation:'Danseur et artiste ivoirien'", PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)
        self.assertNotIn('influenceur', PROFILE.lower())

    def test_census_tiktok_is_officiel_handle(self):
        profile = next(item for item in CENSUS if item.get('id') == 'census-bb-sans-os-de-man')
        self.assertEqual(profile['name'], 'BB Sans Os de Man')
        self.assertEqual(profile['official_socials']['TikTok'], 'https://www.tiktok.com/@bebe.sans.os.de.m')

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'census-bb-sans-os-de-man'", SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0])
        self.assertIn("'census-bb-sans-os-de-man|tiktok'=>'https://www.tiktok.com/@bebe.sans.os.de.m'", SOURCE)
        self.assertIn("'handle'=>'bebe.sans.os.de.m'", SOURCE)
        self.assertIn("'bebe.sans.os.de.m'=>'census-bb-sans-os-de-man'", SOURCE)
        self.assertIn("'census-bb-sans-os-de-man'", CORE)
        self.assertIn('bebe.sans.os.de.m', CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-bb-sans-os-de-man.js?v=1.0', CONFIG)
        self.assertIn('./profile-bb-sans-os-de-man.js?v=1.0', SW)

    def test_github_webcast_includes_handle(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        self.assertEqual(handles.get('bebe.sans.os.de.m'), 'census-bb-sans-os-de-man')


if __name__ == '__main__':
    unittest.main()
