from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = {
    'source': (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8'),
    'parsers': (ROOT / 'api' / 'live-radar-v4-parsers.php').read_text(encoding='utf-8'),
    'storage': (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8'),
    'endpoint': (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8'),
    'client': (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8'),
}


class LiveRadarV4StaticTests(unittest.TestCase):
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

    def test_tiktok_unknown_preserves_continuity(self):
        self.assertIn("continuityPreserved", FILES['endpoint'])
        self.assertIn("tiktok_blocked_or_challenged", FILES['parsers'])
        self.assertIn("P50_LIVE_V4_GRACE_MINUTES", FILES['source'])


if __name__ == '__main__':
    unittest.main()
