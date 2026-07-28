from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
START = (ROOT / "api" / "youtube-oauth-start.php").read_text(encoding="utf-8")
CALLBACK = (ROOT / "api" / "youtube-oauth-callback.php").read_text(encoding="utf-8")
STATE = (ROOT / "api" / "youtube-oauth-state-v2.php").read_text(encoding="utf-8")
CORE = (ROOT / "api" / "youtube-oauth-core.php").read_text(encoding="utf-8")
MIGRATION = (ROOT / "migration-youtube-oauth-v1.sql").read_text(encoding="utf-8")
CLIENT = (ROOT / "youtube-oauth-click-hotfix-v2.js").read_text(encoding="utf-8")


class YoutubeOauthStartReliabilityTests(unittest.TestCase):
    def test_start_is_post_only_bounded_and_has_no_database_work(self):
        self.assertIn("require_method('POST')", START)
        self.assertIn("set_time_limit(10)", START)
        self.assertIn("p50yo_create_state((string)$user['id'])", START)
        for forbidden in (
            "p50yo_ensure_schema",
            "db()",
            "beginTransaction",
            "p50_youtube_oauth_states",
            "CREATE TABLE",
            "DELETE FROM",
            "INSERT INTO",
            "lock_wait_timeout",
        ):
            self.assertNotIn(forbidden, START)

    def test_state_is_signed_short_lived_and_bound_to_the_user(self):
        self.assertIn("P50YO_STATE_TTL_SECONDS = 600", STATE)
        self.assertIn("'uid' => $userId", STATE)
        self.assertIn("'nonce' =>", STATE)
        self.assertIn("hash_hmac(", STATE)
        self.assertIn("hash_equals($expected, $signature)", STATE)
        self.assertIn("$expiresAt < $now", STATE)
        self.assertIn("P50YO_STATE_MAX_CLOCK_SKEW_SECONDS", STATE)
        self.assertIn("[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}", STATE)

    def test_callback_verifies_state_before_google_and_delays_schema_work(self):
        verify = CALLBACK.index("p50yo_verify_state($state)")
        token = CALLBACK.index("https://oauth2.googleapis.com/token")
        channel = CALLBACK.index("https://www.googleapis.com/youtube/v3/channels")
        schema = CALLBACK.index("p50yo_ensure_schema()")
        user_check = CALLBACK.index("SELECT id FROM users")
        connection = CALLBACK.index("p50yo_connection_for_user($userId)")
        self.assertLess(verify, token)
        self.assertLess(channel, schema)
        self.assertLess(schema, user_check)
        self.assertLess(user_check, connection)
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

    def test_browser_opens_popup_before_request_and_enforces_timeout(self):
        popup = CLIENT.index("const popup = openWaitingPopup()")
        request = CLIENT.index("await fetchWithTimeout", popup)
        self.assertLess(popup, request)
        self.assertIn("const REQUEST_TIMEOUT_MS = 15000", CLIENT)
        self.assertIn("new AbortController()", CLIENT)
        self.assertIn("controller.abort()", CLIENT)
        self.assertIn("popup.location.href = data.authorizationUrl", CLIENT)
        self.assertIn("else window.location.href = data.authorizationUrl", CLIENT)
        self.assertIn("/^https:\\/\\/accounts\\.google\\.com\\//", CLIENT)
        self.assertIn("if (popup && !popup.closed) popup.close()", CLIENT)

    def test_public_errors_do_not_include_credentials(self):
        self.assertNotIn("client_secret", START)
        self.assertNotIn("token_encryption_key", START)
        self.assertNotIn("Authorization", START)
        self.assertNotIn("'error' => $e->getMessage()", START)


if __name__ == "__main__":
    unittest.main()
