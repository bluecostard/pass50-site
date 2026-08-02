from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = {
    'source': (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8'),
    'parsers': (ROOT / 'api' / 'live-radar-v4-parsers.php').read_text(encoding='utf-8'),
    'storage': (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8'),
    'endpoint': (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8'),
    'contract': (ROOT / 'api' / 'live-radar-contract.php').read_text(encoding='utf-8'),
    'workflow': (ROOT / '.github' / 'workflows' / 'live-radar-sweep.yml').read_text(encoding='utf-8'),
    'client': (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8'),
}


class LiveRadarV41StaticTests(unittest.TestCase):
    def test_no_public_ranking_write(self):
        joined = '\n'.join(FILES.values())
        self.assertNotIn("app_state", joined)
        self.assertNotIn("p50_metric_captures", joined)
        self.assertNotIn("scores", joined.lower())
        self.assertNotIn("ranks", joined.lower())

    def test_endpoint_uses_v4_only(self):
        self.assertIn("live-radar-v4-core.php", FILES['endpoint'])
        self.assertNotIn("live-status-v3.php", FILES['client'])
        self.assertIn("live-status-v4.php", FILES['client'])

    def test_tiktok_unknown_is_not_published(self):
        self.assertIn("continuityPreserved'=>false", FILES['endpoint'])
        self.assertIn("tiktok_blocked_or_challenged", FILES['parsers'])
        self.assertIn("latest_probe_not_live", FILES['storage'])
        self.assertIn("h.last_state<>'live'", FILES['storage'])

    def test_tiktok_strict_api_beats_stale_ended_html(self):
        parser = FILES['parsers']
        self.assertIn('P50_LIVE_V4_LOGIC_REVISION', parser)
        self.assertIn('p50_live_v4_tiktok_owner_match', parser)
        self.assertIn('$strictApiActive', parser)
        self.assertIn("!$strictApiActive", parser)
        self.assertIn("'strictApiLabels'", parser)
        self.assertIn("$strictApi?99", parser)

    def test_operational_contract_and_complete_sweep(self):
        self.assertIn('LIVE-RADAR-OPERATIONAL-2026-08-02-1', FILES['contract'])
        self.assertIn("publicStateWrites'=>0", FILES['contract'])
        workflow = FILES['workflow']
        self.assertIn('pass50/live-radar', workflow)
        self.assertIn('live-radar-audit.json', workflow)
        self.assertIn('len(latest)>=total', workflow)
        self.assertIn('classified>0', workflow)
        self.assertIn("batch=12", workflow)

    def test_authorized_meta_live_is_not_filtered_by_manual_links(self):
        endpoint = FILES['endpoint']
        self.assertIn("==='meta_authorized')return true", endpoint)
        meta_check = endpoint.index("==='meta_authorized')return true")
        manual_key_check = endpoint.index("return isset($officialKeys[$key])")
        self.assertLess(meta_check, manual_key_check)

    def test_public_rows_require_latest_live_confirmation(self):
        storage = FILES['storage']
        self.assertIn("h.last_state='live'", storage)
        self.assertIn("$platform==='TikTok'?2", storage)
        self.assertIn("latest_probe_not_live", storage)
        self.assertNotIn("h.last_state IN ('unknown','probable')", storage)
        self.assertNotIn("latest_probe_not_confirmed", storage)

    def test_facebook_uses_specific_video_and_independent_probes(self):
        parser = FILES['parsers']
        self.assertIn('videoVotes', parser)
        self.assertIn('public_multi_probe', parser)
        self.assertIn("$votes>=2", parser)
        self.assertIn('facebook_active_without_specific_video', parser)
        self.assertIn('watch/?v=', parser)


if __name__ == '__main__':
    unittest.main()
