import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import load_p0_tiktok_sources, load_p0_youtube_sources  # noqa: E402

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
QUICK = (ROOT / '.github' / 'workflows' / 'live-radar-quick.yml').read_text(encoding='utf-8')
SCRIPT = (ROOT / 'scripts' / 'live_radar_p0_webcast.py').read_text(encoding='utf-8')


class LiveRadarP0WebcastTests(unittest.TestCase):
    def test_parses_unique_p0_handles_from_php(self):
        sources = load_p0_tiktok_sources(SOURCE)
        self.assertGreaterEqual(len(sources), 20)
        by_handle = {}
        for row in sources:
            handle = row['handle'].lower()
            self.assertNotIn(handle, by_handle)
            by_handle[handle] = row['profileId']
            self.assertEqual(row['platform'], 'TikTok')
            self.assertTrue(row['profileId'])
            self.assertTrue(row['handle'])
        self.assertEqual(by_handle['cahiekunta'], 'census-cahie-kunta')
        self.assertEqual(by_handle['nolimit_vousdv'], 'census-no-limit')
        self.assertEqual(by_handle['dezcocrane.225'], 'dez-cocrane225')
        self.assertEqual(by_handle['oustazdianeofficiel1'], 'oustaz-diane')
        self.assertEqual(by_handle['samuellakouassiofficiel'], 'census-samuella-kouassi')
        self.assertEqual(by_handle['angemorel4'], 'census-ange-morel')
        self.assertEqual(sum(1 for row in sources if row['handle'].lower() == 'coachhamond'), 1)
        youtube = load_p0_youtube_sources(SOURCE)
        yt_ids = {row['profileId'] for row in youtube}
        self.assertIn('oustaz-diane', yt_ids)
        self.assertIn('census-observateur-ebene', yt_ids)
        self.assertTrue(all(row['platform'] == 'YouTube' and row.get('liveUrl') for row in youtube))

    def test_quick_workflow_runs_webcast_before_ionos(self):
        webcast_at = QUICK.find('Publier tous les lives P0 vus par GitHub')
        ionos_at = QUICK.find('Sonder prioritairement Oustaz Diané')
        self.assertGreater(webcast_at, 0)
        self.assertGreater(ionos_at, webcast_at)
        self.assertIn('python3 scripts/live_radar_p0_webcast.py', QUICK)
        self.assertIn('continue-on-error: true', QUICK)
        self.assertIn("cron: '*/2 * * * *'", QUICK)
        self.assertIn('webcast.tiktok.com/webcast/room/info_by_user', SCRIPT)
        self.assertIn('live-radar-unknown-audit.php', SCRIPT)
        self.assertIn('POST lot tentative', SCRIPT)
        self.assertIn('p50_live_v4_should_end_from_probe', (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8'))
        self.assertIn('tiktok_no_live_signal_while_live', (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8'))


if __name__ == '__main__':
    unittest.main()
