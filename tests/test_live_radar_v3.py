from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api' / 'live-radar-v4-source.php').read_text(encoding='utf-8')
PARSERS = (ROOT / 'api' / 'live-radar-v4-parsers.php').read_text(encoding='utf-8')
STORAGE = (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8')
ENDPOINT = (ROOT / 'api' / 'live-status-v4.php').read_text(encoding='utf-8')
CLIENT = (ROOT / 'live-radar-v3.js').read_text(encoding='utf-8')
EXPERIENCE = (ROOT / 'live-experience-v4-1.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
CONFIG_EXAMPLE = (ROOT / 'api' / 'config.example.php').read_text(encoding='utf-8')
DATA_CORE = (ROOT / 'api' / 'data-engine-core.php').read_text(encoding='utf-8')
SWEEP = (ROOT / '.github' / 'workflows' / 'live-radar-sweep.yml').read_text(encoding='utf-8')
CORE_TESTS = (ROOT / 'tests' / 'live_radar_v4_core.php').read_text(encoding='utf-8')


class LiveRadarV41Tests(unittest.TestCase):
    def test_multiplatform_scope(self):
        for platform in ('TikTok', 'Facebook', 'YouTube', 'Instagram'):
            self.assertIn(platform, SOURCE + PARSERS)
        self.assertIn('p50_live_v4_parse_tiktok', PARSERS)
        self.assertIn('p50_live_v4_parse_youtube', PARSERS)

    def test_tiktok_requires_current_or_cross_family_confirmation(self):
        parser = re.search(r'function p50_live_v4_parse_tiktok\(.*?\n}', PARSERS, re.S).group(0)
        self.assertIn("$confirmed?'live':'probable'", parser)
        self.assertIn("['api','api_basic']", PARSERS)
        self.assertIn("'live','mobile_live','embed'", PARSERS)
        self.assertIn('proofFamilies', parser)
        self.assertIn('strictApiLabels', parser)
        self.assertIn('freshApiLabels', parser)
        self.assertIn('apiLiveStructureLabels', parser)
        self.assertIn('tiktok_confirmation_incomplete', parser)
        self.assertIn('roomEvidence', parser)
        self.assertIn('$strictApiActive', parser)
        self.assertIn('$freshApiActive', parser)
        self.assertIn('$apiLiveStructure', parser)

    def test_tiktok_end_only_beats_unconfirmed_or_stale_signals(self):
        parser = re.search(r'function p50_live_v4_parse_tiktok\(.*?\n}', PARSERS, re.S).group(0)
        self.assertIn("if(!$confirmed&&$endedLabels)return ['state'=>'offline'", parser)
        self.assertIn("if($endedLabels)return ['state'=>'offline'", parser)
        self.assertIn('p50_live_v4_tiktok_owner_match', PARSERS)
        self.assertIn('p50_live_v4_tiktok_room_is_fresh', PARSERS)
        self.assertIn("!$currentApiActive", parser)
        self.assertIn('Le LIVE est terminé', CORE_TESTS)
        self.assertIn('ancienne trace HTML de fin', CORE_TESTS)
        self.assertIn('Trust Gate', CORE_TESTS)
        self.assertIn("['state']==='live'", CORE_TESTS)

    def test_unknown_block_hides_public_live(self):
        self.assertIn("'continuityPreserved'=>false", ENDPOINT)
        self.assertIn("status='unconfirmed'", STORAGE)
        self.assertIn('latest_probe_offline', STORAGE)
        self.assertIn("h.last_state IN ('offline','replay')", STORAGE)
        self.assertIn("h.last_state='live'", STORAGE)
        self.assertNotIn("h.last_state='unknown'", STORAGE)

    def test_public_live_uses_trust_gate_windows(self):
        active = re.search(r'function p50_live_v4_active_rows\(.*?\n}', STORAGE, re.S).group(0)
        self.assertNotIn("$platform==='TikTok'?2", active)
        self.assertIn("h.last_state='live'", active)
        self.assertIn('INTERVAL {$seconds} SECOND', active)
        self.assertIn("'lastConfirmedAt'=>p50_live_v4_iso($row['last_seen_at']", active)
        self.assertIn('confirmation_grace_expired', active)
        self.assertIn("'trustSeconds'=>p50_live_v4_trust_seconds_map()", ENDPOINT)

    def test_dismissed_stream_never_returns(self):
        self.assertIn('p50_live_dismissals', STORAGE)
        self.assertIn('p50_live_v4_is_dismissed', STORAGE)
        self.assertIn('p50_live_v4_event_identity', STORAGE)
        self.assertIn('LEFT JOIN p50_live_dismissals', STORAGE)
        self.assertIn('d.stream_key IS NULL', STORAGE)

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

    def test_collection_policy_is_broader_but_probable_remains_separate(self):
        self.assertIn('P50_DATA_CONFIDENCE_THRESHOLD = 80', DATA_CORE)
        self.assertIn('min(P50_DATA_CONFIDENCE_THRESHOLD', DATA_CORE)
        self.assertIn('setting_value=VALUES(setting_value)', DATA_CORE)
        self.assertIn("'confidence_threshold' => 80", CONFIG_EXAMPLE)
        self.assertIn("elseif($stateValue==='probable'", ENDPOINT)
        self.assertNotIn("$stateValue==='probable'&&!empty($result['live'])){\n                p50_live_v4_store_live", ENDPOINT)

    def test_quick_scan_reserves_discovery_capacity(self):
        self.assertIn('$discoveryQuota=min(4,max(0,$batch-count($reconfirm)))', ENDPOINT)
        self.assertIn('$reconfirm', ENDPOINT)
        self.assertIn("'discoveryQuota'=>$discoveryQuota", ENDPOINT)
        self.assertIn("['TikTok'=>0,'Facebook'=>1,'YouTube'=>2,'Instagram'=>3]", ENDPOINT)
        self.assertIn("$_GET['batch']??14", ENDPOINT)

    def test_client_is_compatibly_loaded_but_uses_v4(self):
        self.assertIn("live-radar-v3.js?v=1.6", CONFIG)
        self.assertIn("const ENDPOINT='./api/live-status-v4.php'", CLIENT)
        self.assertIn('RADAR LIVE V4', CLIENT)
        self.assertIn('TikTok:720', CLIENT)
        self.assertIn('PASS50_LIVE_EXPERIENCE_VERSION', EXPERIENCE)
        self.assertIn('live-trust-gate-v1.js?v=1.2', (ROOT / 'public-copy-fixes.js').read_text(encoding='utf-8'))
        self.assertIn('live-experience-v4-1.js?v=1.3', (ROOT / 'public-copy-fixes.js').read_text(encoding='utf-8'))

    def test_server_sweep_uses_v4(self):
        self.assertIn('*/5 * * * *', SWEEP)
        self.assertIn('api/live-status-v4.php', SWEEP)
        self.assertIn('mode=full', SWEEP)
        self.assertIn('live-radar-audit.json', SWEEP)
        self.assertIn('publishedStreams', SWEEP)
        self.assertIn('pass50/live-radar', SWEEP)
        quick = (ROOT / '.github' / 'workflows' / 'live-radar-quick.yml').read_text(encoding='utf-8')
        self.assertIn('*/2 * * * *', quick)
        self.assertIn('mode=quick', quick)


if __name__ == '__main__':
    unittest.main()
