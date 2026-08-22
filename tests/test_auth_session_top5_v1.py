import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUTH = (ROOT / "pass50-auth-session.js").read_text(encoding="utf-8")
FEED = (ROOT / "api/content-feed.php").read_text(encoding="utf-8")
BOOT = (ROOT / "api/bootstrap.php").read_text(encoding="utf-8")
LOGIN = (ROOT / "api/login.php").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
NAV = (ROOT / "mobile-bottom-nav-v1.js").read_text(encoding="utf-8")
CLIENT = (ROOT / "content-intelligence.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "api/config.example.php").read_text(encoding="utf-8")


class AuthSessionTop5V1Tests(unittest.TestCase):
    def test_session_persists_in_local_storage_not_session_storage(self):
        self.assertIn("pass50_session_user", AUTH)
        self.assertIn("localStorage.setItem(SESSION_USER_KEY", AUTH)
        self.assertIn("migrateLegacySession", AUTH)
        self.assertIn("pass50_device_id", AUTH)
        self.assertIn("P50Auth", INDEX)
        self.assertIn("pass50-auth-session.js", INDEX)
        self.assertIn("authPending()", INDEX)
        self.assertIn("isAuthExpiredError", INDEX)
        self.assertNotIn("sessionStorage.setItem('pass50_session'", INDEX)

    def test_token_only_cleared_on_401_not_network_errors(self):
        self.assertIn("err.status=res.status", INDEX)
        self.assertIn("P50Auth.isAuthExpiredError(err)", INDEX)
        self.assertIn("console.warn('Session restore'", INDEX)

    def test_server_sliding_session_and_device_binding(self):
        self.assertIn("touch_session", BOOT)
        self.assertIn("p50_sessions_ensure_schema", BOOT)
        self.assertIn("device_id", BOOT)
        self.assertIn("deviceId", LOGIN)
        self.assertIn("'session_days' => 365", CONFIG)

    def test_top5_stale_fallback_when_fresh_filter_empty(self):
        self.assertIn("p50_content_feed_collect_trends", FEED)
        self.assertIn("p50_content_feed_trend_period_fallback_order", FEED)
        self.assertIn("trendsServedPeriod", FEED)
        self.assertIn("trendsUsedFallback", FEED)

    def test_auth_boot_restores_session_before_cloud_state(self):
        self.assertIn("async function restoreCloudSession", INDEX)
        self.assertIn("finishCloudBoot()", INDEX)
        self.assertIn("if(CLOUD.token)await restoreCloudSession()", INDEX)
        self.assertIn("function isGuestUser()", INDEX)
        self.assertIn("window.authPending", NAV)

    def test_client_keeps_last_top5_while_refreshing(self):
        self.assertIn("pass50_ci_trends_cache_v1", CLIENT)
        self.assertIn("staleTrendsRemainVisible", CLIENT)
        self.assertIn("writeTrendCache", CLIENT)
        self.assertIn("fetchTrendFeed", CLIENT)
        self.assertIn("period:'48h'", CLIENT)

    def test_mobile_nav_respects_stored_auth(self):
        self.assertIn("P50Auth.hasStoredAuth()", NAV)
        self.assertIn("pass50_session_user", NAV)


if __name__ == "__main__":
    unittest.main()
