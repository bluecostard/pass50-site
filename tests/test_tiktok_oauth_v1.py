from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'tiktok-oauth-core.php').read_text(encoding='utf-8')
STATE = (ROOT / 'api' / 'tiktok-oauth-state-v1.php').read_text(encoding='utf-8')
START = (ROOT / 'api' / 'tiktok-oauth-start.php').read_text(encoding='utf-8')
CALLBACK = (ROOT / 'api' / 'tiktok-oauth-callback.php').read_text(encoding='utf-8')
STATUS = (ROOT / 'api' / 'tiktok-oauth-status.php').read_text(encoding='utf-8')
DISCONNECT = (ROOT / 'api' / 'tiktok-oauth-disconnect.php').read_text(encoding='utf-8')
UI = (ROOT / 'tiktok-oauth-ui-v1.js').read_text(encoding='utf-8')
CONFIG = (ROOT / 'api' / 'config.example.php').read_text(encoding='utf-8')
PUBLIC = (ROOT / 'public-copy-fixes.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')


class TikTokOauthV1Tests(unittest.TestCase):
    def test_official_endpoints_and_scopes(self):
        self.assertIn('https://www.tiktok.com/v2/auth/authorize/', START)
        self.assertIn('https://open.tiktokapis.com/v2/oauth/token/', CORE + CALLBACK)
        self.assertIn('https://open.tiktokapis.com/v2/oauth/revoke/', DISCONNECT + CALLBACK)
        self.assertIn('https://open.tiktokapis.com/v2/user/info/', CORE)
        self.assertIn('https://open.tiktokapis.com/v2/video/list/', CORE)
        for scope in ('user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list'):
            self.assertIn(scope, CORE)
        for forbidden_scope in ('video.publish', 'video.upload', 'portability.all'):
            self.assertNotIn(forbidden_scope, CORE + START)

    def test_start_is_database_free_and_state_is_signed(self):
        self.assertNotIn('db()', START)
        self.assertNotIn('auth_user()', START)
        self.assertIn("hash('sha256', $sessionToken)", START)
        self.assertIn("hash_hmac('sha256'", STATE)
        self.assertIn("'httponly' => true", STATE)
        self.assertIn("'secure' => true", STATE)
        self.assertIn("'samesite' => 'Lax'", STATE)

    def test_tokens_are_server_side_and_encrypted(self):
        self.assertIn('aes-256-gcm', CORE)
        self.assertIn('PASS50:tiktok-oauth:v1', CORE)
        self.assertNotIn('client_secret', UI.lower())
        self.assertNotIn('access_token', STATUS.lower())
        self.assertNotIn('refresh_token', STATUS.lower())

    def test_callback_validates_session_and_reads_display_api(self):
        self.assertIn('p50tk_verify_state', CALLBACK)
        self.assertIn('sessions s JOIN users u', CALLBACK)
        self.assertIn('p50tk_fetch_profile', CALLBACK)
        self.assertIn('p50tk_fetch_videos', CALLBACK)
        self.assertIn('p50tk_store_snapshot', CALLBACK)

    def test_ui_is_read_only_and_loaded(self):
        self.assertIn('Connecter TikTok', UI)
        self.assertIn('Aucune publication', UI)
        self.assertIn('tiktok-oauth-start.php', UI)
        self.assertIn('tiktok-oauth-status.php', UI)
        self.assertIn('tiktok-oauth-disconnect.php', UI)
        self.assertIn('tiktok-oauth-ui-v1.js', PUBLIC)
        self.assertIn('tiktok-oauth-ui-v1.js', SW)

    def test_configuration_has_no_real_secret(self):
        self.assertIn("'tiktok_oauth'", CONFIG)
        self.assertIn('TIKTOK_CLIENT_KEY', CONFIG)
        self.assertIn('TIKTOK_CLIENT_SECRET', CONFIG)
        self.assertIn('tiktok-oauth-callback.php', CONFIG)
        self.assertNotRegex(CONFIG, r"tiktok_oauth.*(?:act\\.|rft\\.)")

    def test_public_ranking_is_out_of_scope(self):
        combined = CORE + STATE + START + CALLBACK + STATUS + DISCONNECT + UI
        self.assertNotIn('app_state', combined)
        self.assertNotIn('p50_metric_ranking_current', combined)
        self.assertNotIn('rank_position', combined)


if __name__ == '__main__':
    unittest.main()
