from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
START = (ROOT / "api" / "youtube-oauth-start.php").read_text(encoding="utf-8")
CALLBACK = (ROOT / "api" / "youtube-oauth-callback.php").read_text(encoding="utf-8")
STATE = (ROOT / "api" / "youtube-oauth-state-v2.php").read_text(encoding="utf-8")
CORE = (ROOT / "api" / "youtube-oauth-core.php").read_text(encoding="utf-8")
MIGRATION = (ROOT / "migration-youtube-oauth-v1.sql").read_text(encoding="utf-8")
CLIENT = (ROOT / "youtube-oauth-click-hotfix-v2.js").read_text(encoding="utf-8")
LOADER = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")


class YoutubeOauthStartReliabilityTests(unittest.TestCase):
    def test_start_has_no_database_read_or_write_before_google(self):
        self.assertIn("require_method('POST')", START)
        self.assertIn("set_time_limit(10)", START)
        self.assertIn("$sessionToken = bearer_token()", START)
        self.assertIn("hash('sha256', $sessionToken)", START)
        self.assertIn("p50yo_set_nonce_cookie($nonce)", START)
        self.assertIn("'flowVersion' => 3", START)
        self.assertNotIn("auth_user(", START)
        for forbidden in (
            "p50yo_ensure_schema",
            "db()",
            "beginTransaction",
            "p50_youtube_oauth_states",
            "CREATE TABLE",
            "DELETE FROM",
            "INSERT INTO",
            "lock_wait_timeout",
            "SELECT ",
        ):
            self.assertNotIn(forbidden, START)

    def test_state_is_signed_short_lived_and_bound_to_session_and_browser(self):
        self.assertIn("P50YO_STATE_TTL_SECONDS = 600", STATE)
        self.assertIn("'v' => 3", STATE)
        self.assertIn("'sid' => strtolower($sessionTokenHash)", STATE)
        self.assertIn("'nh' => hash('sha256', $nonce)", STATE)
        self.assertIn("hash_hmac(", STATE)
        self.assertIn("hash_equals($expected, $signature)", STATE)
        self.assertIn("hash_equals($nonceHash, hash('sha256', $nonce))", STATE)
        self.assertIn("'secure' => true", STATE)
        self.assertIn("'httponly' => true", STATE)
        self.assertIn("'samesite' => 'Lax'", STATE)
        self.assertIn("P50YO_NONCE_COOKIE_PATH", STATE)
        self.assertIn("preg_replace('/^www\\./i'", STATE)

    def test_callback_verifies_browser_state_and_resolves_live_session(self):
        verify = CALLBACK.index("p50yo_verify_state($state, $nonce)")
        session_query = CALLBACK.index("SELECT u.id,u.email FROM sessions")
        token = CALLBACK.index("https://oauth2.googleapis.com/token")
        channel = CALLBACK.index("https://www.googleapis.com/youtube/v3/channels")
        schema = CALLBACK.index("p50yo_ensure_schema()")
        connection = CALLBACK.index("p50yo_connection_for_user($userId)")
        self.assertLess(verify, session_query)
        self.assertLess(session_query, token)
        self.assertLess(token, channel)
        self.assertLess(channel, schema)
        self.assertLess(schema, connection)
        self.assertIn("s.token_hash=?", CALLBACK)
        self.assertIn("s.expires_at>UTC_TIMESTAMP()", CALLBACK)
        self.assertIn("p50yo_clear_nonce_cookie()", CALLBACK)
        self.assertNotIn("SELECT user_id,expires_at,consumed_at", CALLBACK)
        self.assertNotIn("DELETE FROM p50_youtube_oauth_states", CALLBACK)

    def test_existing_mysql_migration_remains_idempotent_and_compatible(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS p50_youtube_oauth_states", MIGRATION)
        self.assertIn("CREATE TABLE IF NOT EXISTS p50_youtube_oauth_connections", MIGRATION)
        self.assertIn("ENGINE=InnoDB", MIGRATION)
        self.assertIn("REFERENCES users(id) ON DELETE CASCADE", MIGRATION)
        self.assertIn("INDEX idx_p50_youtube_oauth_channel", MIGRATION)
        self.assertIn("INDEX idx_p50_youtube_oauth_status", MIGRATION)
        self.assertIn("preg_split('/;\\s*(?:\\r?\\n|$)/'", CORE)

    def test_browser_opens_popup_enforces_timeout_and_polls_status(self):
        popup = CLIENT.index("const popup = openWaitingPopup()")
        request = CLIENT.index("await fetchWithTimeout", popup)
        polling = CLIENT.index("startStatusPolling(token)", request)
        self.assertLess(popup, request)
        self.assertLess(request, polling)
        self.assertIn("const REQUEST_TIMEOUT_MS = 15000", CLIENT)
        self.assertIn("new AbortController()", CLIENT)
        self.assertIn("controller.abort()", CLIENT)
        self.assertIn("youtube-oauth-status.php", CLIENT)
        self.assertIn("window.postMessage({ source: 'PASS50_YOUTUBE_OAUTH'", CLIENT)
        self.assertIn("popup.location.href = data.authorizationUrl", CLIENT)
        self.assertIn("else window.location.href = data.authorizationUrl", CLIENT)
        self.assertIn("/^https:\\/\\/accounts\\.google\\.com\\//", CLIENT)
        self.assertIn("if (popup && !popup.closed) popup.close()", CLIENT)
        self.assertIn("youtube-oauth-click-hotfix-v2.js?v=3.0", LOADER)

    def test_public_errors_do_not_include_credentials(self):
        self.assertNotIn("client_secret", START)
        self.assertNotIn("token_encryption_key", START)
        self.assertNotIn("'error' => $e->getMessage()", START)
        self.assertNotIn("$sessionToken,", START)


if __name__ == "__main__":
    unittest.main()
