import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-hassan-hayek.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
CRON = (ROOT / 'api' / 'official-links-batch-owner-cron-v1.php').read_text(encoding='utf-8')


class HassanHayekLiveRadarTests(unittest.TestCase):
    def test_profile_loader_tiktok(self):
        self.assertIn("const PROFILE_ID='hassan'", PROFILE)
        self.assertIn('https://www.tiktok.com/@hassanhayekofficiel', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertIn("birthDate:null", PROFILE)
        self.assertIn("birthYear:null", PROFILE)
        self.assertIn("occupation:'Homme d’affaires et acteur social ivoirien'", PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)
        self.assertNotIn('influenceur', PROFILE.lower())
        apply = PROFILE.split('function applyProfile', 1)[1]
        self.assertNotIn('eligible:false', apply)
        self.assertNotIn('classable:false', apply)

    def test_owner_instagram_is_not_replaced(self):
        self.assertIn("'hassanhayek'=>['Instagram'=>'https://www.instagram.com/hassanhayek/','TikTok'=>'https://www.tiktok.com/@hassanhayek']", CRON)
        self.assertIn('https://www.instagram.com/hassanhayek/', PROFILE)

    def test_radar_p0_and_forced_probe(self):
        self.assertIn("'hassan'", SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0])
        self.assertIn("'hassan|tiktok'=>'https://www.tiktok.com/@hassanhayekofficiel'", SOURCE)
        self.assertIn("'handle'=>'hassanhayekofficiel'", SOURCE)
        self.assertIn("'hassanhayekofficiel'=>'hassan'", SOURCE)
        self.assertIn("'hassan'", CORE)
        self.assertIn('hassanhayekofficiel', CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-hassan-hayek.js?v=1.0', CONFIG)
        self.assertIn('./profile-hassan-hayek.js?v=1.0', SW)

    def test_github_webcast_includes_handle(self):
        handles = {row['handle'].lower(): row['profileId'] for row in load_p0_tiktok_sources(SOURCE)}
        self.assertEqual(handles.get('hassanhayekofficiel'), 'hassan')


if __name__ == '__main__':
    unittest.main()
