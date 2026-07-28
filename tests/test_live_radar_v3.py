from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
PARSERS = (ROOT / 'api' / 'live-radar-v4-parsers.php').read_text(encoding='utf-8')
STORAGE = (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8')
ENDPOINT = (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8')
CLIENT = (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SWEEP = (ROOT / '.github' / 'workflows' / 'live-radar-sweep.yml').read_text(encoding='utf-8')


class LiveRadarV4Tests(unittest.TestCase):
    def test_multiplatform_scope(self):
        for platform in ('TikTok', 'YouTube', 'Instagram', 'Facebook'):
            self.assertIn(platform, SOURCE + PARSERS)
        self.assertIn('p50_live_v4_parse_tiktok', PARSERS)
        self.assertIn('p50_live_v4_parse_youtube', PARSERS)

    def test_tiktok_accepts_api_or_two_independent_html_probes(self):
        parser = re.search(r'function p50_live_v4_parse_tiktok\(.*?\n}', PARSERS, re.S).group(0)
        self.assertIn("$strongApi||$votes>=2?'live':'probable'", parser)
        self.assertIn("'api','api_basic'", PARSERS)
        self.assertIn("'live','mobile_live','embed'", parser)
        self.assertIn('tiktok_blocked_or_challenged', parser)
        self.assertIn('roomVotes', parser)

    def test_unknown_does_not_immediately_withdraw_a_confirmed_live(self):
        decision_block = ENDPOINT.split('$diagnostics[]', 1)[0].split('foreach(p50_live_v4_scan_batch', 1)[1]
        self.assertNotIn("$stateValue==='unknown'", decision_block)
        self.assertIn('continuityPreserved', ENDPOINT)
        self.assertIn("elseif($stateValue==='probable'", ENDPOINT)

    def test_public_live_has_a_sweep_aligned_grace_window(self):
        self.assertIn("'TikTok'=>20", SOURCE)
        self.assertIn("'YouTube'=>15", SOURCE)
        active = re.search(r'function p50_live_v4_active_rows\(.*?\n}', STORAGE, re.S).group(0)
        self.assertNotIn("h.last_state='live'", active)
        self.assertIn("'lastConfirmedAt'=>p50_live_v4_iso($row['last_seen_at']", active)
        self.assertIn('confirmation_grace_expired', active)

    def test_replay_is_separate_from_live(self):
        self.assertIn("'state'=>'replay'", PARSERS)
        self.assertIn("elseif($stateValue==='replay')", ENDPOINT)
        self.assertIn('p50_live_v4_mark_ended', ENDPOINT)
        self.assertIn("'replay'=>0", STORAGE)

    def test_data_chain_starts_from_verified_links_and_ends_in_public_streams(self):
        self.assertIn("s.status='verified'", SOURCE)
        self.assertIn('p50_live_v4_store_live', ENDPOINT)
        self.assertIn("'liveStreams'=>$streams", ENDPOINT)
        self.assertIn('p50_live_v4_health_update', ENDPOINT)
        self.assertIn('p50_live_streams', STORAGE)

    def test_client_is_compatibly_loaded_but_uses_v4(self):
        self.assertIn("live-radar-v3.js?v=1.2", CONFIG)
        self.assertIn("const ENDPOINT='./api/live-status-v4.php'", CLIENT)
        self.assertIn('RADAR LIVE V4', CLIENT)
        self.assertIn('candidatesFoundInCycle', CLIENT)
        self.assertIn('À confirmer', CLIENT)

    def test_server_sweep_uses_v4(self):
        self.assertIn('*/10 * * * *', SWEEP)
        self.assertIn('api/live-status-v4.php', SWEEP)
        self.assertIn('mode=full', SWEEP)


if __name__ == '__main__':
    unittest.main()
