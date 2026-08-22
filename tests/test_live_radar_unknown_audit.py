from pathlib import Path
import json
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from live_radar_unknown_audit import (  # noqa: E402
    format_discussion_entry,
    parse_facebook_live_html,
    parse_tiktok_room_api,
    parse_tiktok_webcast,
    parse_youtube_live_html,
    write_discussion_log,
)

SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
ENDPOINT = (ROOT / 'api' / 'live-radar-unknown-audit.php').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'live-radar-unknown-audit.yml').read_text(encoding='utf-8')
STATUS = (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8')


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

    def test_room_api_status_2_is_live_when_webcast_misses(self):
        payload = {
            'data': {
                'user': {
                    'uniqueId': 'ennemidesdjandjou',
                    'nickname': 'ENNEMI DES DJANDJOU',
                    'roomId': '7676642940831566612',
                    'status': 2,
                },
                'liveRoom': {'status': 2, 'title': '', 'liveRoomStats': {'userCount': 0}},
            }
        }
        parsed = parse_tiktok_room_api(payload, 'ennemidesdjandjou')
        self.assertIsNotNone(parsed)
        self.assertEqual(parsed['roomId'], '7676642940831566612')
        self.assertEqual(parsed['handle'], 'ennemidesdjandjou')
        self.assertIsNone(parse_tiktok_room_api(payload, 'prince_du_pays'))
        self.assertIsNone(parse_tiktok_webcast({'status_code': 4003110}, 'ennemidesdjandjou'))

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
        self.assertIn('www.tiktok.com/api-live/user/room', (ROOT / 'scripts' / 'live_radar_unknown_audit.py').read_text(encoding='utf-8'))
        self.assertIn('function p50_live_v4_merge_p0_watch', SOURCE)
        self.assertIn('function p50_live_v4_is_p0_source', SOURCE)
        self.assertIn('p50_live_v4_needs_p0_rescan', STATUS)
        self.assertIn('github_unknown_audit', ENDPOINT)
        self.assertIn('unknown_audit_webcast', ENDPOINT)
        self.assertIn("cron: '20 */3 * * *'", WORKFLOW)
        self.assertIn('scripts/live_radar_unknown_audit.py', WORKFLOW)
        self.assertIn('pass50/discussions/radar-unknown-audit.md', WORKFLOW)
        self.assertIn('contents: write', WORKFLOW)
        self.assertIn('p50_live_status_cache_invalidate', ENDPOINT)
        self.assertNotIn('p50_live_status_cache_build()', ENDPOINT)
        self.assertIn("array_slice($unknowns,0,$limit)", ENDPOINT)
        self.assertIn('$tiktokCatalog', ENDPOINT)
        self.assertIn("limit']??200", ENDPOINT)

    def test_discussion_journal_lists_real_lives(self):
        entry = format_discussion_entry({
            'unknownCount': 12,
            'lives': [{'platform': 'TikTok', 'profileId': 'census-jordan-evraa', 'handle': 'realjordanevraa', 'title': 'Goumin tv', 'viewers': 141}],
            'posted': {'published': 1, 'added': [], 'stored': [{'profileId': 'census-jordan-evraa', 'platform': 'TikTok'}], 'skipped': []},
            'enabled': True,
        }, when='2026-08-18 00:45 UTC')
        self.assertIn('Vraiment en live : **1**', entry)
        self.assertIn('census-jordan-evraa', entry)
        self.assertIn('@realjordanevraa', entry)

    def test_discussion_file_is_prepended(self):
        import tempfile
        from pathlib import Path
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            discussion = root / 'radar-unknown-audit.md'
            latest = root / 'radar-unknown-audit-latest.json'
            write_discussion_log({'unknownCount': 2, 'lives': [], 'posted': None, 'enabled': True}, discussion, latest)
            write_discussion_log({
                'unknownCount': 2,
                'lives': [{'platform': 'TikTok', 'profileId': 'census-jordan-evraa', 'title': 'Goumin tv'}],
                'posted': {'published': 1, 'added': []},
                'enabled': True,
            }, discussion, latest)
            text = discussion.read_text(encoding='utf-8')
            self.assertLess(text.find('census-jordan-evraa'), text.find('Aucun unknown réellement en live'))
            snapshot = json.loads(latest.read_text(encoding='utf-8'))
            self.assertEqual(snapshot['liveCount'], 1)


if __name__ == '__main__':
    unittest.main()
