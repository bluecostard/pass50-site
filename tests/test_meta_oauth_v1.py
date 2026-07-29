from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'meta-oauth-core.php').read_text(encoding='utf-8')
START = (ROOT / 'api' / 'meta-oauth-start.php').read_text(encoding='utf-8')
CALLBACK = (ROOT / 'api' / 'meta-oauth-callback.php').read_text(encoding='utf-8')
STATUS = (ROOT / 'api' / 'meta-oauth-status.php').read_text(encoding='utf-8')
COLLECT = (ROOT / 'api' / 'meta-live-collect.php').read_text(encoding='utf-8')
UI = (ROOT / 'meta-oauth-ui-v1.js').read_text(encoding='utf-8')
LOADER = (ROOT / 'public-copy-fixes.js').read_text(encoding='utf-8')
CONNECTORS = (ROOT / 'connector-sections-v1.js').read_text(encoding='utf-8')
STORAGE = (ROOT / 'api' / 'live-radar-v4-storage.php').read_text(encoding='utf-8')
CONFIG = (ROOT / 'api' / 'config.example.php').read_text(encoding='utf-8')
MIGRATION = (ROOT / 'migration-meta-oauth-v1.sql').read_text(encoding='utf-8')

class MetaOauthV1Tests(unittest.TestCase):
    def test_required_read_only_scopes(self):
        for scope in ('pages_show_list', 'pages_read_engagement', 'pages_manage_metadata', 'instagram_basic'):
            self.assertIn(scope, CORE)
        self.assertNotIn('pages_manage_posts', CORE)
        self.assertNotIn('instagram_content_publish', CORE)

    def test_secure_state_and_encryption(self):
        self.assertIn('aes-256-gcm', CORE)
        self.assertIn('hash_hmac', CORE)
        self.assertIn('httponly', CORE)
        self.assertIn("'samesite'=>'Lax'", CORE)
        self.assertNotIn("app_secret' => '", CONFIG)

    def test_callback_discovers_pages_and_linked_instagram(self):
        self.assertIn('me/accounts', CALLBACK)
        self.assertIn('instagram_business_account', CALLBACK)
        self.assertIn('p50mo_match_profile', CALLBACK)
        self.assertIn('fb_exchange_token', CALLBACK)

    def test_private_tokens_never_leave_status_endpoint(self):
        self.assertNotIn('access_token_encrypted', STATUS)
        self.assertNotIn("'access_token'", STATUS)
        self.assertIn("'assets'", STATUS)
        self.assertIn("'configuration'", STATUS)
        self.assertIn('appSecretConfigured', STATUS)

    def test_official_live_collection(self):
        self.assertIn('/live_videos', COLLECT)
        self.assertIn('media_product_type', COLLECT)
        self.assertIn("'LIVE'", COLLECT)
        self.assertIn("source='meta_authorized'", COLLECT)
        self.assertIn("'meta_authorized'", STORAGE)

    def test_ui_is_loaded_and_has_no_publish_action(self):
        self.assertIn('meta-oauth-ui-v1.js?v=1.1', LOADER)
        self.assertIn('meta-live-collect.php', UI)
        self.assertIn('Aucune publication automatique', UI)
        self.assertNotIn('publish', UI.lower())

    def test_connect_button_cannot_fail_silently(self):
        self.assertIn('Préparation…', UI)
        self.assertIn('p50-meta-message error', UI)
        self.assertIn('Connexion Meta impossible', UI)
        self.assertIn("addEventListener('click'", UI)
        self.assertIn('},true);', UI)
        self.assertIn('AbortController', UI)

    def test_connector_sections_are_collapsible_and_future_ready(self):
        self.assertIn('connector-sections-v1.js?v=1.0', LOADER)
        self.assertIn('p50YoutubeOauthSection', CONNECTORS)
        self.assertIn('p50MetaOauthSection', CONNECTORS)
        self.assertIn('PASS50_CONNECTOR_SECTIONS', CONNECTORS)
        self.assertIn('register(section,key', CONNECTORS)
        self.assertIn('pass50_connector_sections_v1', CONNECTORS)
        self.assertIn("defaultState='collapsed'", CONNECTORS)
        self.assertIn('role="alert"', CONNECTORS)
        self.assertIn('Déplier', CONNECTORS)
        self.assertIn('Replier', CONNECTORS)

    def test_schema_is_separate_and_user_scoped(self):
        self.assertIn('p50_meta_oauth_connections', MIGRATION)
        self.assertIn('p50_meta_oauth_assets', MIGRATION)
        self.assertIn('UNIQUE KEY uq_p50_meta_asset', MIGRATION)
        self.assertIn('user_id', MIGRATION)

    def test_start_uses_meta_dialog_and_signed_state(self):
        self.assertIn('/dialog/oauth', START)
        self.assertIn('p50mo_create_state', START)
        self.assertIn('p50mo_set_nonce', START)

if __name__ == '__main__':
    unittest.main()
