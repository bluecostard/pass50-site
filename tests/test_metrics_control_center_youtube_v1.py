from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
COLLECTORS = (ROOT / 'api' / 'metrics-collectors-core.php').read_text(encoding='utf-8')
SOCIAL = (ROOT / 'api' / 'metrics-social-collectors-core.php').read_text(encoding='utf-8')
ORCHESTRATOR = (ROOT / 'api' / 'metrics-orchestrator-core.php').read_text(encoding='utf-8')
BRIDGE = (ROOT / 'api' / 'youtube-metrics-bridge-core.php').read_text(encoding='utf-8')
CONTROL = (ROOT / 'api' / 'metrics-control-center-core.php').read_text(encoding='utf-8')
MAPPING = (ROOT / 'api' / 'youtube-metrics-map.php').read_text(encoding='utf-8')
DIAGNOSTIC = (ROOT / 'api' / 'metrics-diagnostic.php').read_text(encoding='utf-8')
UI = (ROOT / 'data-engine-ui.js').read_text(encoding='utf-8')
TOOLS = (ROOT / 'v9-tools.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')


class MetricsControlCenterYoutubeV1Tests(unittest.TestCase):
    def test_effective_threshold_is_shared_and_not_hardcoded_to_90(self):
        self.assertIn('function p50_mc_threshold', COLLECTORS)
        self.assertGreaterEqual(COLLECTORS.count('p50_mc_threshold()'), 2)
        self.assertIn('p50_mc_threshold()', ORCHESTRATOR)
        self.assertNotIn('max(90,min(100', COLLECTORS)
        self.assertNotIn('max(90,min(100', ORCHESTRATOR)

    def test_youtube_oauth_can_feed_canonical_metrics_only_after_mapping(self):
        self.assertIn('function p50ym_connection_for_profile', BRIDGE)
        self.assertIn('function p50ym_collect', BRIDGE)
        self.assertIn("'youtube_oauth_api'", BRIDGE)
        self.assertIn("'youtube_analytics_oauth_28d'", BRIDGE)
        self.assertIn("'excludedFromCumulativeDelta'=>true", BRIDGE)
        self.assertIn('p50ym_connection_for_profile', COLLECTORS)
        self.assertIn('p50ym_collect', COLLECTORS)
        self.assertIn('p50ym_public_access', SOCIAL)

    def test_mapping_is_admin_only_and_does_not_expose_tokens(self):
        self.assertIn("require_role($user,'owner','admin')", MAPPING)
        self.assertIn('p50ym_map_channel', MAPPING)
        joined = BRIDGE + CONTROL + MAPPING
        for forbidden in ('access_token_encrypted', 'refresh_token_encrypted'):
            self.assertNotIn("'" + forbidden + "'=>", joined)
        self.assertNotIn("'userId'=>", joined)
        self.assertIn('uq_p50_youtube_oauth_profile', BRIDGE)

    def test_control_center_reports_platform_coverage_and_youtube_mapping(self):
        for value in ('eligibleProfiles', 'coveredProfiles', 'freshProfiles24h', 'coveragePercent', 'freshnessPercent', 'queue'):
            self.assertIn(value, CONTROL)
        self.assertIn('youtubeOAuth', CONTROL)
        self.assertIn('metrics-control-center-core.php', DIAGNOSTIC)
        self.assertIn("['controlCenter']", DIAGNOSTIC)
        self.assertIn('CENTRE DE CONTRÔLE DE LA COLLECTE', UI)
        self.assertIn('de-youtube-metrics-map', UI)
        self.assertIn('youtube-metrics-map.php', UI)

    def test_no_public_ranking_write(self):
        joined = BRIDGE + CONTROL + MAPPING
        for forbidden in ('UPDATE app_state', 'INSERT INTO app_state', 'data-publish.php', 'p50_mr_calculate', 'rank_position'):
            self.assertNotIn(forbidden, joined)

    def test_ui_cache_is_bumped(self):
        self.assertIn('data-engine-ui.js?v=18.2', TOOLS)
        self.assertIn('data-engine-ui.js?v=18.2', SW)
        self.assertIn('pass50-v45-metrics-control-center', SW)


if __name__ == '__main__':
    unittest.main()
