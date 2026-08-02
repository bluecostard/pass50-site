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
    def test_trust_module_defines_strict_public_windows(self):
        self.assertIn("P50_LIVE_V4_TRUST_REVISION = 'LIVE-TRUST-GATE-2026-08-03-1'", TRUST)
        self.assertIn("'TikTok' => 90", TRUST)
        self.assertIn("'YouTube' => 240", TRUST)
        self.assertIn('p50_live_v4_is_publicly_fresh', TRUST)
        self.assertIn('p50_live_v4_filter_public_streams', TRUST)

    def test_public_rows_require_positive_live_state(self):
        self.assertIn("h.last_state='live'", STORAGE)
        self.assertIn('INTERVAL {$seconds} SECOND', STORAGE)
        self.assertNotIn("h.last_state='unknown'", STORAGE)
        self.assertIn('p50_live_v4_is_publicly_fresh', STORAGE)

    def test_quick_scan_reconfirms_active_lives_first(self):
        self.assertIn("status='live'", ENDPOINT)
        self.assertIn('$reconfirm', ENDPOINT)
        self.assertIn('p50_live_v4_filter_public_streams', ENDPOINT)
        self.assertIn("'trustSeconds'=>p50_live_v4_trust_seconds_map()", ENDPOINT)

    def test_tiktok_fresh_room_alone_is_not_enough(self):
        self.assertIn('$candidateConfirmed=$strictCount>0||$cross', PARSERS)
        self.assertIn('LIVE-TRUST-GATE-2026-08-03-1', PARSERS)
        self.assertIn('P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS = 10800', PARSERS)

    def test_client_verifies_before_open_and_loads_gate(self):
        self.assertIn('PASS50_VERIFY_THEN_OPEN_LIVE', CLIENT)
        self.assertIn('Ce direct est terminé', CLIENT)
        self.assertIn('trustSeconds', CLIENT)
        self.assertIn('ensureLiveTrustGate', RADAR)
        self.assertIn('live-trust-gate-v1.js?v=1.0', RADAR + SW)
        self.assertIn('live-radar-v3.js?v=1.5', CONFIG)

    def test_contract_exposes_trust_gate(self):
        self.assertIn("'trustGate'=>P50_LIVE_V4_TRUST_REVISION", CONTRACT)
        self.assertIn('LIVE-TRUST-GATE-2026-08-03-1', WORKFLOW)


if __name__ == '__main__':
    unittest.main()
