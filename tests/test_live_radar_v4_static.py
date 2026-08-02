from pathlib import Path
import re
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
        runtime = '\n'.join(FILES[name] for name in ('source', 'parsers', 'storage', 'endpoint', 'contract'))
        write_patterns = (
            r'\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+app_state\b',
            r'\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+p50_metric_captures\b',
        )
        for pattern in write_patterns:
            self.assertIsNone(re.search(pattern, runtime, re.I))
        self.assertIn("publicStateWrites'=>0", FILES['contract'])
        self.assertNotIn('p50_metric_captures', runtime)

    def test_endpoint_uses_v4_only(self):
        self.assertIn("live-radar-v4-core.php", FILES['endpoint'])
        self.assertNotIn("live-status-v3.php", FILES['client'])
        self.assertIn("live-status-v4.php", FILES['client'])

    def test_tiktok_unknown_is_not_published(self):
        self.assertIn("continuityPreserved'=>false", FILES['endpoint'])
        self.assertIn("tiktok_blocked_or_challenged", FILES['parsers'])
        self.assertIn("latest_probe_not_live", FILES['storage'])
        self.assertIn("h.last_state<>'live'", FILES['storage'])

    def test_tiktok_fresh_room_beats_stale_html_without_reviving_old_rooms(self):
        parser = FILES['parsers']
        self.assertIn('LIVE-RADAR-FRESH-TIKTOK-ROOMS-2026-08-02-2', parser)
        self.assertIn('P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS = 43200', parser)
        self.assertIn('p50_live_v4_tiktok_room_timestamp', parser)
        self.assertIn('p50_live_v4_tiktok_room_is_fresh', parser)
        self.assertIn('$freshApiActive', parser)
        self.assertIn('$currentApiActive', parser)
        self.assertIn("!$currentApiActive", parser)
        self.assertIn("'freshApiLabels'", parser)
        self.assertIn("$freshApi?96", parser)

    def test_operational_contract_and_complete_sweep(self):
        self.assertIn("'contract'=>P50_LIVE_V4_LOGIC_REVISION", FILES['contract'])
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
