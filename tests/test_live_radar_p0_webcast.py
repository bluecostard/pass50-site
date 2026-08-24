import unittest
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_p0_webcast import (  # noqa: E402
    load_p0_tiktok_sources,
    load_p0_youtube_sources,
    merge_github_sources,
    source_from_audit_row,
)

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
QUICK = (ROOT / '.github' / 'workflows' / 'live-radar-quick.yml').read_text(encoding='utf-8')
P0 = (ROOT / '.github' / 'workflows' / 'live-radar-p0.yml').read_text(encoding='utf-8')
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
        self.assertEqual(by_handle['_michael_daniel'], 'census-daniel-m')
        self.assertEqual(by_handle['akalajoie'], 'census-akalajoie')
        self.assertEqual(by_handle['ennemidesdjandjou'], 'ennemi-des-djandjou')
        self.assertEqual(by_handle['prince_du_pays'], 'census-isouch')
        self.assertEqual(by_handle['bebe.sans.os.de.m'], 'census-bb-sans-os-de-man')
        self.assertEqual(by_handle['hassanhayekofficiel'], 'hassan')
        self.assertEqual(by_handle['legrandbicongo'], 'census-le-grand-bicongo')
        self.assertEqual(by_handle['chocolat.show.officiel'], 'census-chocolat-show-officiel')
        self.assertEqual(by_handle['lalegende777'], 'census-la-legende')
        self.assertEqual(by_handle['jack.carter39'], 'census-willway-jordan-officiel')
        self.assertNotEqual(by_handle.get('prince_du_pays'), 'ennemi-des-djandjou')
        self.assertEqual(sum(1 for row in sources if row['handle'].lower() == 'coachhamond'), 1)
        youtube = load_p0_youtube_sources(SOURCE)
        yt_ids = {row['profileId'] for row in youtube}
        self.assertIn('oustaz-diane', yt_ids)
        self.assertIn('census-observateur-ebene', yt_ids)
        self.assertIn('census-daniel-m', yt_ids)
        self.assertTrue(all(row['platform'] == 'YouTube' and row.get('liveUrl') for row in youtube))

    def test_quick_workflow_runs_webcast_before_ionos(self):
        webcast_at = QUICK.find('Publier tous les lives P0 vus par GitHub')
        ionos_at = QUICK.find('Reconfirmer YouTube / Instagram / Facebook depuis IONOS')
        self.assertGreater(webcast_at, 0)
        self.assertGreater(ionos_at, webcast_at)
        self.assertNotIn('Sonder prioritairement Oustaz Diané', QUICK)
        self.assertIn('python3 scripts/live_radar_continuous_tick.py', P0)
        self.assertIn("cron: '*/5 * * * *'", P0)
        self.assertIn('cancel-in-progress: true', P0)
        self.assertNotIn('live-status-v4.php', P0)
        self.assertIn('continue-on-error: true', QUICK)
        self.assertIn("cron: '*/5 * * * *'", QUICK)
        self.assertIn('--tick 1', P0)
        self.assertIn('PASS50_LIVE_RADAR_AUTONOMY_V1', P0)
        self.assertIn('webcast.tiktok.com/webcast/room/info_by_user', SCRIPT)
        self.assertIn('www.tiktok.com/api-live/user/room', (ROOT / 'scripts' / 'live_radar_unknown_audit.py').read_text(encoding='utf-8'))
        self.assertIn('live-radar-unknown-audit.php', SCRIPT)
        self.assertIn('POST lot tentative', SCRIPT)
        self.assertIn('ROTATION_LIMIT', SCRIPT)
        self.assertIn('merge_github_sources', SCRIPT)
        self.assertIn('p50_live_v4_should_end_from_probe', (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8'))
        self.assertIn('p50_live_v4_tiktok_probe_is_inconclusive', (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8'))
        self.assertIn('tiktok_no_live_signal_while_live', (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8'))

    def test_rotation_merges_dynamic_p0_and_unknowns(self):
        seed = load_p0_tiktok_sources(SOURCE)[:1]
        listing = {
            'p0': [{'profileId': 'new-host', 'platform': 'TikTok', 'handle': 'new_host_tt'}],
            'unknowns': [
                {'profileId': seed[0]['profileId'], 'platform': 'TikTok', 'handle': seed[0]['handle']},
                {'profileId': 'catalog-tt', 'platform': 'TikTok', 'handle': 'catalog_handle'},
            ],
        }
        merged = merge_github_sources(seed, listing)
        handles = [row['handle'].lower() for row in merged]
        self.assertEqual(handles[0], seed[0]['handle'].lower())
        self.assertIn('new_host_tt', handles)
        self.assertIn('catalog_handle', handles)
        self.assertEqual(len(handles), len(set(handles)))
        self.assertEqual(
            source_from_audit_row({'profileId': 'x', 'platform': 'TikTok', 'handle': '@foo'}),
            {'profileId': 'x', 'platform': 'TikTok', 'handle': 'foo'},
        )


if __name__ == '__main__':
    unittest.main()
