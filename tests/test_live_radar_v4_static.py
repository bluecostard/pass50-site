from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = {
    'source': (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8'),
    'parsers': (ROOT / 'api' / 'live-radar-v4-parsers.php').read_text(encoding='utf-8'),
    'storage': (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8'),
    'endpoint': (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8'),
    'dismiss': (ROOT / 'api' / 'live-dismiss.php').read_text(encoding='utf-8'),
    'contract': (ROOT / 'api' / 'live-radar-contract.php').read_text(encoding='utf-8'),
    'workflow': (ROOT / '.github' / 'workflows' / 'live-radar-sweep.yml').read_text(encoding='utf-8'),
    'client': (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8'),
}


class LiveRadarV41StaticTests(unittest.TestCase):
    def test_no_public_ranking_write(self):
        runtime = '\n'.join(FILES[name] for name in ('source', 'parsers', 'storage', 'endpoint', 'dismiss', 'contract'))
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

    def test_reported_youtube_false_positive_is_scoped_to_one_video(self):
        parser = FILES['parsers']
        self.assertIn("P50_LIVE_V4_FALSE_POSITIVE_VIDEO_IDS = ['TOa6dTjz7V0']", parser)
        self.assertIn("'error'=>'known_false_positive'", parser)
        self.assertIn('p50_live_v4_known_false_positive($stream)', FILES['endpoint'])
        self.assertNotIn("profileId']==='kevine'", parser)

    def test_unknown_block_hides_public_live(self):
        self.assertIn("'continuityPreserved'=>false", FILES['endpoint'])
        self.assertIn("tiktok_blocked_or_challenged", FILES['parsers'])
        self.assertIn("latest_probe_offline", FILES['storage'])
        self.assertIn("h.last_state IN ('offline','replay')", FILES['storage'])
        self.assertIn("h.last_state='live'", FILES['storage'])
        self.assertNotIn("h.last_state='unknown'", FILES['storage'])

    def test_tiktok_trust_gate_allows_fresh_api_confirmation(self):
        parser = FILES['parsers']
        self.assertIn('LIVE-STRICT-PUBLISH-2026-08-11-1', parser)
        self.assertIn('p50_live_v4_parse_utc', (ROOT / 'api' / 'live-radar-v4-trust.php').read_text(encoding='utf-8'))
        self.assertIn('P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS = 3600', parser)
        self.assertIn('p50_live_v4_tiktok_room_timestamp', parser)
        self.assertIn('p50_live_v4_tiktok_room_is_fresh', parser)
        self.assertIn('$apiLiveStructure', parser)
        self.assertIn('$freshApiActive', parser)
        self.assertIn('$currentApiActive', parser)
        self.assertIn('!$strictApiActive', parser)
        self.assertIn("'apiLiveStructureLabels'", parser)
        self.assertIn('$candidateConfirmed=$strictCount>0', parser)
        self.assertIn('p50_live_v4_is_publishable_proof', FILES['storage'])
        self.assertIn('p50_live_v4_platform_referer', FILES['source'])
        self.assertIn('Chrome/126', FILES['source'])
        self.assertIn("status='live'", FILES['source'])
        self.assertIn("return ['state'=>'offline','error'=>'instagram_no_public_live_signal'", parser)
        self.assertIn("return ['state'=>'offline','error'=>'tiktok_no_live_signal'", parser)
        self.assertIn('tiktok_embed_uninformative', parser)
        self.assertIn('tiktok_api_failed_html_ended', parser)
        self.assertIn('p50_live_v4_tiktok_api_unreachable', parser)
        self.assertIn('p50_live_v4_tiktok_bodies_inconclusive', parser)
        self.assertIn("liveSignal'=>'isLiveNow'", parser)
        self.assertIn('youtube_vod_not_live_now', parser)

    def test_each_live_event_has_its_own_stream_key(self):
        storage = FILES['storage']
        self.assertIn('function p50_live_v4_event_identity', storage)
        self.assertIn("['roomId','videoId','broadcastId','broadcast_id']", storage)
        self.assertIn("'event:'.$eventId", storage)
        self.assertIn("'url:'.rtrim", storage)
        dismiss = FILES['dismiss']
        self.assertIn('SELECT stream_key,url FROM p50_live_streams', dismiss)
        self.assertIn("ORDER BY (url=?) DESC,last_seen_at DESC LIMIT 1", dismiss)
        self.assertIn("(string)$row['stream_key']", dismiss)

    def test_operational_contract_and_complete_sweep(self):
        self.assertIn("'contract'=>P50_LIVE_V4_LOGIC_REVISION", FILES['contract'])
        self.assertIn("publicStateWrites'=>0", FILES['contract'])
        workflow = FILES['workflow']
        self.assertIn('pass50/live-radar', workflow)
        self.assertIn('live-radar-audit.json', workflow)
        self.assertIn('len(latest)>=total', workflow)
        self.assertIn('classified>0', workflow)
        self.assertIn("batch=14", workflow)

    def test_authorized_meta_live_is_not_filtered_by_manual_links(self):
        endpoint = FILES['endpoint']
        self.assertIn("==='meta_authorized')return true", endpoint)
        meta_check = endpoint.index("==='meta_authorized')return true")
        manual_key_check = endpoint.index("return isset($officialKeys[$key])")
        self.assertLess(meta_check, manual_key_check)

    def test_public_rows_use_trust_gate(self):
        storage = FILES['storage']
        source = FILES['source']
        self.assertIn("h.last_state='live'", storage)
        self.assertIn('INTERVAL {$seconds} SECOND', storage)
        self.assertIn("confirmation_grace_expired", storage)
        self.assertIn("'TikTok'=>12", source)
        self.assertNotIn("$platform==='TikTok'?2", storage)
        self.assertIn("latest_probe_offline", storage)

    def test_facebook_uses_specific_video_and_independent_probes(self):
        parser = FILES['parsers']
        source = FILES['source']
        self.assertIn('videoVotes', parser)
        self.assertIn('public_multi_probe', parser)
        self.assertIn("$votes>=2", parser)
        self.assertIn('facebook_active_without_specific_video', parser)
        self.assertIn('watch/?v=', parser)
        self.assertIn('live_videos', source)
        self.assertIn('mbasic.facebook.com', source)
        self.assertIn('web_profile_info', source)


if __name__ == '__main__':
    unittest.main()
