import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')
PROFILE = (ROOT / 'profile-cahie-kunta.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
QUICK = (ROOT / '.github' / 'workflows' / 'live-radar-quick.yml').read_text(encoding='utf-8')
AUDIT = (ROOT / 'api' / 'live-radar-unknown-audit.php').read_text(encoding='utf-8')
SCRIPT = (ROOT / 'scripts' / 'live_radar_p0_webcast.py').read_text(encoding='utf-8')


class CahieKuntaLiveRadarTests(unittest.TestCase):
    def test_profile_loader_tiktok(self):
        self.assertIn("const PROFILE_ID='census-cahie-kunta'", PROFILE)
        self.assertIn('https://www.tiktok.com/@cahiekunta', PROFILE)
        self.assertIn("verificationPriority:'P0'", PROFILE)
        self.assertIn("if(profile.verificationPriority!=='P0')", PROFILE)
        self.assertNotIn("verificationPriority:'P1'", PROFILE)
        self.assertNotIn('ensureManualLive', PROFILE)
        self.assertNotIn("source:'manual'", PROFILE)

    def test_radar_p0_and_forced_probe(self):
        p0_tiktok = SOURCE.split('P50_LIVE_V4_P0_TIKTOK', 1)[1].split('];', 1)[0]
        self.assertIn("'census-cahie-kunta'", p0_tiktok)
        self.assertIn("'census-cahie-kunta|tiktok'=>'https://www.tiktok.com/@cahiekunta'", SOURCE)
        self.assertIn("'handle'=>'cahiekunta'", SOURCE)
        self.assertIn("'census-cahie-kunta'", CORE)
        self.assertIn('cahiekunta', CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-cahie-kunta.js?v=1.1', CONFIG)

    def test_github_webcast_publishes_p0_including_cahie(self):
        sources = load_p0_tiktok_sources(SOURCE)
        handles = {row['handle'].lower(): row['profileId'] for row in sources}
        self.assertEqual(handles.get('cahiekunta'), 'census-cahie-kunta')
        self.assertIn('live_radar_p0_webcast.py', QUICK)
        self.assertIn('PASS50_METRICS_CRON_SECRET', QUICK)
        self.assertIn('actions/checkout@v4', QUICK)
        self.assertIn('unknown_audit_webcast', AUDIT)
        self.assertIn('p50_live_v4_store_live', AUDIT)
        self.assertIn('p50_live_status_cache_invalidate', AUDIT)
        self.assertNotIn('p50_live_status_cache_build()', AUDIT)
        self.assertNotIn('ensureManualLive', SCRIPT)
        self.assertNotIn("source:'manual'", SCRIPT)


if __name__ == '__main__':
    unittest.main()
