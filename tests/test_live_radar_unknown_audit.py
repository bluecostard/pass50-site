from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_unknown_audit import (  # noqa: E402
    parse_facebook_live_html,
    parse_tiktok_webcast,
    parse_youtube_live_html,
)

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
ENDPOINT = (ROOT / 'api' / 'live-radar-unknown-audit.php').read_text(encoding='utf-8')
STATUS = (ROOT / 'api' / 'live-radar-unknown-audit-status.php').read_text(encoding='utf-8')
LIVE_STATUS = (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'live-radar-unknown-audit.yml').read_text(encoding='utf-8')
SCRIPT = (ROOT / 'scripts' / 'live_radar_unknown_audit.py').read_text(encoding='utf-8')
INDEX = (ROOT / 'index.html').read_text(encoding='utf-8')


class LiveRadarUnknownAuditTests(unittest.TestCase):
    def test_webcast_status_2_is_live(self):
        payload = {
            'status_code': 0,
            'data': {
                'status': 2,
                'id_str': '7675133122324843295',
                'title': 'Goumin tv',
                'user_count': 12,
                'owner': {'display_id': 'realjordanevraa'},
            },
        }
        parsed = parse_tiktok_webcast(payload, 'realjordanevraa')
        self.assertIsNotNone(parsed)
        self.assertEqual(parsed['roomId'], '7675133122324843295')

    def test_webcast_ended_status_4_is_ignored(self):
        payload = {'status_code': 0, 'data': {'status': 4, 'id_str': '7675133122324843295', 'owner': {'display_id': 'x'}}}
        self.assertIsNone(parse_tiktok_webcast(payload, 'x'))

    def test_youtube_requires_islivenow(self):
        live = parse_youtube_live_html('<title>Direct - YouTube</title><script>{"isLiveNow":true,"videoId":"abcDEF123456"}</script>')
        self.assertEqual(live['videoId'], 'abcDEF123456')
        self.assertIsNone(parse_youtube_live_html('<script>{"isLiveNow":false,"videoId":"abcDEF123456"}</script>'))

    def test_facebook_blocked_page_is_ignored(self):
        self.assertIsNone(parse_facebook_live_html('<html>captcha verify to continue</html>', 'https://www.facebook.com/x/live/'))
        parsed = parse_facebook_live_html(
            '{"is_live_streaming":true,"video_id":"123456789"}',
            'https://www.facebook.com/page/live/',
        )
        self.assertEqual(parsed['videoId'], '123456789')

    def test_wiring(self):
        self.assertIn('webcast.tiktok.com/webcast/room/info_by_user', SOURCE)
        self.assertIn('function p50_live_v4_merge_p0_watch', SOURCE)
        self.assertIn('function p50_live_v4_is_p0_source', SOURCE)
        self.assertIn('function p50_live_v4_unknown_audit_public_snapshot', SOURCE)
        self.assertIn('p50_live_v4_needs_p0_rescan', LIVE_STATUS)
        self.assertIn('github_unknown_audit', ENDPOINT)
        self.assertIn('unknown_audit_webcast', ENDPOINT)
        self.assertIn("P50_LIVE_V4_UNKNOWN_AUDIT_LAST_SETTING", ENDPOINT)
        self.assertIn("'lives'=>$publicLives", ENDPOINT)
        self.assertIn("cron: '20 */3 * * *'", WORKFLOW)
        self.assertIn('scripts/live_radar_unknown_audit.py', WORKFLOW)
        self.assertNotIn('pass50/discussions', WORKFLOW)
        self.assertNotIn('contents: write', WORKFLOW)
        self.assertIn('contents: read', WORKFLOW)

    def test_results_go_to_admin_left_column_not_pass50(self):
        self.assertIn('id="liveUnknownAudit"', INDEX)
        self.assertIn('live-admin-layout', INDEX)
        self.assertIn('p50FillUnknownAudit', INDEX)
        self.assertIn('live-radar-unknown-audit-status.php', INDEX)
        self.assertNotIn('pass50/discussions', INDEX)
        self.assertNotIn('pass50/discussions', SCRIPT)
        self.assertIn('unknownCount', SCRIPT)
        self.assertIn('require_method(\'GET\')', STATUS)
        self.assertNotIn('HTTP_X_PASS50_CRON_SECRET', STATUS)
        self.assertNotIn("'unknowns'", STATUS)
        self.assertIn('p50_live_v4_unknown_audit_public_snapshot', STATUS)


if __name__ == '__main__':
    unittest.main()
