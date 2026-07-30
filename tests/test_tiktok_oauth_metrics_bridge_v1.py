from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = (ROOT / 'api' / 'tiktok-metrics-bridge-core.php').read_text(encoding='utf-8')
SOCIAL = (ROOT / 'api' / 'metrics-social-collectors-core.php').read_text(encoding='utf-8')
COLLECTOR = (ROOT / 'api' / 'metrics-collector-tiktok.php').read_text(encoding='utf-8')
META = (ROOT / 'api' / 'meta-metrics-bridge-core.php').read_text(encoding='utf-8')
ORCHESTRATOR = (ROOT / 'api' / 'metrics-orchestrator-core.php').read_text(encoding='utf-8')


class TikTokOauthMetricsBridgeV1Tests(unittest.TestCase):
    def test_mapping_uses_only_verified_official_link_and_exact_username(self):
        self.assertIn("s.platform='TikTok'", BRIDGE)
        self.assertIn("s.status='verified'", BRIDGE)
        self.assertIn('LOWER(username)=?', BRIDGE)
        self.assertIn('count($rows)!==1', BRIDGE)
        self.assertIn('hash_equals($officialUsername,$actualUsername)', COLLECTOR)
        self.assertNotIn('LIKE CONCAT', BRIDGE)
        self.assertNotIn('display_name LIKE', BRIDGE)

    def test_oauth_token_is_refreshed_server_side_and_not_exposed(self):
        self.assertIn('p50tk_refresh_access_token', BRIDGE)
        self.assertIn('p50_mc_tiktok_display', BRIDGE)
        self.assertIn("'mode'=>'authorized_display'", BRIDGE)
        self.assertNotIn("'access_token'=>", BRIDGE)
        self.assertNotIn("'refresh_token'=>", BRIDGE)

    def test_collector_prefers_oauth_but_keeps_existing_fallbacks(self):
        self.assertIn("require_once __DIR__.'/tiktok-metrics-bridge-core.php'", SOCIAL)
        self.assertIn('p50tm_public_access', SOCIAL)
        self.assertIn('p50tm_connection_for_profile', COLLECTOR)
        self.assertIn('p50tm_collect', COLLECTOR)
        self.assertIn('approved_research', SOCIAL + COLLECTOR)
        self.assertIn('PASS50_TIKTOK_ACCESS_TOKEN', SOCIAL)

    def test_identity_is_checked_against_open_id_username_and_official_link(self):
        self.assertIn('identity_mismatch', COLLECTOR)
        self.assertIn('username_mismatch', COLLECTOR)
        self.assertIn('official_link_mismatch', COLLECTOR)
        self.assertIn("'username'", COLLECTOR)

    def test_authorized_profiles_feed_existing_oauth_priority_selection(self):
        self.assertIn('function p50tm_authorized_profile_ids', BRIDGE)
        self.assertIn("status='active'", BRIDGE)
        self.assertIn('access_token_encrypted', BRIDGE)
        self.assertIn('refresh_token_encrypted', BRIDGE)
        self.assertIn("function_exists('p50tm_authorized_profile_ids')", META)
        self.assertIn('p50tm_authorized_profile_ids($pdo)', META)
        self.assertIn('p50mm_authorized_profile_ids($pdo)', ORCHESTRATOR)
        self.assertIn('p50_mo_authorized_oauth_profiles($pdo)', ORCHESTRATOR)

    def test_no_public_ranking_write(self):
        combined = BRIDGE + SOCIAL + COLLECTOR + META
        for forbidden in (
            'UPDATE app_state', 'INSERT INTO app_state', 'DELETE FROM app_state',
            'REPLACE INTO app_state', 'data-publish.php', 'p50_mr_calculate',
            'rank_position',
        ):
            self.assertNotIn(forbidden, combined)


if __name__ == '__main__':
    unittest.main()
