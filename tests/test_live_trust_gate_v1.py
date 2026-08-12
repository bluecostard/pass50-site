from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
TRUST = (ROOT / 'api' / 'live-radar-v4-trust.php').read_text(encoding='utf-8')
STORAGE = (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8')
ENDPOINT = (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8')
PARSERS = (ROOT / 'api' / 'live-radar-v4-parsers.php').read_text(encoding='utf-8')
CLIENT = (ROOT / 'live-trust-gate-v1.js').read_text(encoding='utf-8')
RADAR = (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
CONTRACT = (ROOT / 'api' / 'live-radar-contract.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'live-radar-sweep.yml').read_text(encoding='utf-8')


class LiveTrustGateV1Tests(unittest.TestCase):
    def test_trust_module_defines_balanced_public_windows(self):
        self.assertIn("P50_LIVE_V4_TRUST_REVISION = 'LIVE-STRICT-PUBLISH-2026-08-11-1'", TRUST)
        self.assertIn('p50_live_v4_parse_utc', TRUST)
        self.assertIn("'TikTok' => 480", TRUST)
        self.assertIn("'YouTube' => 720", TRUST)
        self.assertIn("'Instagram' => 600", TRUST)
        self.assertIn('p50_live_v4_is_publicly_fresh', TRUST)
        self.assertIn('p50_live_v4_filter_public_streams', TRUST)

    def test_public_rows_require_positive_live_state(self):
        self.assertIn("h.last_state='live'", STORAGE)
        self.assertIn('INTERVAL {$seconds} SECOND', STORAGE)
        self.assertNotIn("h.last_state='unknown'", STORAGE)
        self.assertIn('p50_live_v4_is_publicly_fresh', STORAGE)
        self.assertIn('p50_live_v4_is_publishable_proof', STORAGE)
        self.assertIn('insufficient_publish_proof', STORAGE)

    def test_quick_scan_reconfirms_active_lives_first(self):
        self.assertIn("status='live'", ENDPOINT)
        self.assertIn('$reconfirm', ENDPOINT)
        self.assertIn('p50_live_v4_filter_public_streams', ENDPOINT)
        self.assertIn("'trustSeconds'=>p50_live_v4_trust_seconds_map()", ENDPOINT)

    def test_tiktok_strict_api_only_publishes(self):
        self.assertIn('$candidateConfirmed=$strictCount>0', PARSERS)
        self.assertIn('LIVE-STRICT-PUBLISH-2026-08-11-1', PARSERS)
        self.assertIn('P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS = 3600', PARSERS)
        self.assertIn("liveSignal'=>'isLiveNow'", PARSERS)

    def test_client_opens_first_then_verifies_and_loads_gate(self):
        self.assertIn('PASS50_OPEN_THEN_VERIFY_LIVE', CLIENT)
        self.assertIn('PASS50_VERIFY_THEN_OPEN_LIVE', CLIENT)
        self.assertIn('Ce direct est terminé', CLIENT)
        self.assertIn('TikTok:480', CLIENT + RADAR)
        self.assertNotIn("closest('.live-watch-link", CLIENT)
        self.assertNotIn('event.preventDefault()', CLIENT)
        self.assertIn('ensureLiveTrustGate', RADAR)
        self.assertIn('live-trust-gate-v1.js?v=1.3', RADAR + SW + CONFIG)
        self.assertIn("live-radar-v3.js?v=1.9", CONFIG + SW)

    def test_contract_exposes_trust_gate(self):
        self.assertIn("'trustGate'=>P50_LIVE_V4_TRUST_REVISION", CONTRACT)
        self.assertIn('LIVE-STRICT-PUBLISH-2026-08-11-1', WORKFLOW)


if __name__ == '__main__':
    unittest.main()
