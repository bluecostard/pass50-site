from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'meta-oauth-core.php').read_text(encoding='utf-8')
ASSET_DISCOVERY = (ROOT / 'api' / 'meta-oauth-assets.php').read_text(encoding='utf-8')
REFRESH_ASSETS = (ROOT / 'api' / 'meta-oauth-refresh-assets.php').read_text(encoding='utf-8')
MAPPING_OPTIONS = (ROOT / 'api' / 'meta-oauth-mapping-options.php').read_text(encoding='utf-8')
MAP_ASSET = (ROOT / 'api' / 'meta-oauth-map-asset.php').read_text(encoding='utf-8')
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
        for scope in ('pages_show_list', 'pages_read_engagement', 'instagram_basic'):
            self.assertIn(scope, CORE)
        self.assertNotIn('pages_manage_metadata', CORE)
        self.assertNotIn('pages_manage_posts', CORE)
        self.assertNotIn('instagram_content_publish', CORE)

    def test_secure_state_and_encryption(self):
        self.assertIn('aes-256-gcm', CORE)
        self.assertIn('hash_hmac', CORE)
        self.assertIn('httponly', CORE)
        self.assertIn("'samesite'=>'None'", CORE)
        self.assertNotIn("app_secret' => '", CONFIG)

    def test_nonce_cookie_survives_meta_round_trip(self):
        self.assertIn("P50MO_NONCE_COOKIE = 'p50_meta_oauth_nonce_v2'", CORE)
        self.assertIn("P50MO_NONCE_PATH = '/'", CORE)
        self.assertIn("return 'pass50.store'", CORE)
        self.assertIn("$options['domain']=$domain", CORE)
        self.assertIn('P50MO_LEGACY_NONCE_COOKIE', CALLBACK)

    def test_business_login_configuration(self):
        self.assertIn("'configuration_id'", CORE)
        self.assertIn('META_CONFIGURATION_ID', CORE)
        self.assertIn("'config_id'", START)
        self.assertIn("'override_default_response_type'=>'true'", START)
        self.assertNotIn("'scope'=>implode(',',P50MO_REQUIRED_SCOPES)", START)
        self.assertIn('configurationIdConfigured', STATUS)

    def test_authorization_code_exchange_uses_post_grant(self):
        self.assertIn("'grant_type'=>'authorization_code'", CALLBACK)
        self.assertIn("'POST'", CALLBACK)
        self.assertIn("['Accept: application/json']", CALLBACK)
        self.assertNotIn("oauth/access_token','GET',['client_id'", CALLBACK)

    def test_page_discovery_uses_edge_and_granular_targets(self):
        self.assertIn("p50mo_graph('me/accounts'", ASSET_DISCOVERY)
        self.assertIn("'/debug_token'", ASSET_DISCOVERY)
        self.assertIn('granular_scopes', ASSET_DISCOVERY)
        self.assertIn('target_ids', ASSET_DISCOVERY)
        self.assertIn("['pages_show_list','pages_read_engagement']", ASSET_DISCOVERY)
        self.assertIn("p50mo_graph($pageId,$userToken", ASSET_DISCOVERY)
        self.assertIn('p50mo_discover_authorized_assets', CALLBACK)

    def test_page_token_is_requested_before_detailed_fields(self):
        self.assertIn("return 'id,name,access_token,tasks,instagram_business_account'", ASSET_DISCOVERY)
        self.assertIn("['fields'=>'id,name,access_token']", ASSET_DISCOVERY)
        self.assertIn('p50mo_page_detail_fields()', ASSET_DISCOVERY)
        token_request = ASSET_DISCOVERY.index("['fields'=>'id,name,access_token']")
        detail_request = ASSET_DISCOVERY.index("['fields'=>p50mo_page_detail_fields()]")
        self.assertLess(token_request, detail_request)
        self.assertIn('p50mo_safe_graph_error', ASSET_DISCOVERY)
        self.assertIn('Réponse Meta :', ASSET_DISCOVERY)

    def test_page_rediscovery_does_not_require_reauthorization(self):
        self.assertIn('p50mo_decrypt', REFRESH_ASSETS)
        self.assertIn('p50mo_discover_authorized_assets', REFRESH_ASSETS)
        self.assertIn('p50mo_replace_assets_for_user', REFRESH_ASSETS)
        self.assertIn('meta-oauth-refresh-assets.php', UI)
        self.assertIn('Rechercher mes Pages', UI)
        self.assertIn('discoveryWarning', STATUS)

    def test_manual_asset_mapping_is_admin_only_and_profile_validated(self):
        self.assertIn("require_role($user,'owner','admin')", MAPPING_OPTIONS)
        self.assertIn("require_role($user,'owner','admin')", MAP_ASSET)
        self.assertIn('p50_de_registry_profiles', MAPPING_OPTIONS)
        self.assertIn('p50_de_registry_profiles($profileId', MAP_ASSET)
        self.assertIn("UPDATE p50_meta_oauth_assets SET profile_id=?", MAP_ASSET)
        self.assertIn("parent_page_id=?", MAP_ASSET)
        self.assertIn('canManageMappings', STATUS)

    def test_manual_mapping_survives_asset_rediscovery(self):
        self.assertIn("SELECT platform,asset_id,parent_page_id,profile_id", ASSET_DISCOVERY)
        self.assertIn('$existing[$key]=$profileId', ASSET_DISCOVERY)
        self.assertIn('$pageMappings', ASSET_DISCOVERY)
        self.assertIn("meta-oauth-map-asset.php", UI)
        self.assertIn("meta-oauth-mapping-options.php", UI)
        self.assertIn('Associer à une fiche', UI)
        self.assertIn('Retirer l’association', UI)

    def test_callback_discovers_pages_and_linked_instagram(self):
        self.assertIn('instagram_business_account', ASSET_DISCOVERY)
        self.assertIn('p50mo_match_profile', ASSET_DISCOVERY)
        self.assertIn('fb_exchange_token', CALLBACK)

    def test_private_tokens_never_leave_status_endpoint(self):
        self.assertNotIn('access_token_encrypted', STATUS)
        self.assertNotIn("'access_token'", STATUS)
        self.assertIn("'assets'", STATUS)
        self.assertIn("'configuration'", STATUS)
        self.assertIn('appSecretConfigured', STATUS)
        self.assertNotIn('access_token_encrypted', MAPPING_OPTIONS)
        self.assertNotIn('access_token_encrypted', MAP_ASSET)

    def test_official_live_collection(self):
        self.assertIn('/live_videos', COLLECT)
        self.assertIn('media_product_type', COLLECT)
        self.assertIn("'LIVE'", COLLECT)
        self.assertIn("source='meta_authorized'", COLLECT)
        self.assertIn("'meta_authorized'", STORAGE)
        self.assertIn('function p50_meta_health_update', COLLECT)
        self.assertIn('p50_live_v4_health_update', COLLECT)
        self.assertIn("'meta_graph'", COLLECT)
        self.assertIn('healthUpdated', COLLECT)

    def test_ui_is_loaded_and_has_no_publish_action(self):
        self.assertIn('meta-oauth-ui-v1.js?v=1.6', LOADER)
        self.assertIn('meta-live-collect.php', UI)
        self.assertIn('Aucune publication automatique', UI)
        self.assertNotIn('publish', UI.lower())
        self.assertIn('data-p50-meta-auto-map', UI)
        self.assertIn('Associer automatiquement', UI)
        self.assertIn('toutes</strong> les Pages FI', UI)

    def test_auto_map_endpoint_and_match_improvements(self):
        auto_map = (ROOT / 'api' / 'meta-oauth-auto-map.php').read_text(encoding='utf-8')
        self.assertIn('p50mo_auto_map_unmapped_assets', auto_map)
        self.assertIn('HTTP_X_PASS50_CRON_SECRET', auto_map)
        self.assertIn('function p50mo_auto_map_unmapped_assets', ASSET_DISCOVERY)
        self.assertIn('function p50mo_apply_profile_mapping', ASSET_DISCOVERY)
        self.assertIn('autoMapped', REFRESH_ASSETS)
        self.assertIn("facebook.com/'", CORE)
        self.assertIn("\$query['id']", CORE)
        self.assertIn('meta-oauth-auto-map.php', (ROOT / '.github' / 'workflows' / 'meta-live-sweep.yml').read_text(encoding='utf-8'))
        self.assertIn('owner_verified', CORE)

    def test_connect_button_cannot_fail_silently(self):
        self.assertIn('Redirection vers Meta…', UI)
        self.assertIn('p50-meta-message error', UI)
        self.assertIn('Connexion Meta impossible', UI)
        self.assertIn("addEventListener('click'", UI)
        self.assertIn('},true);', UI)
        self.assertIn('AbortController', UI)

    def test_oauth_does_not_use_conflicting_window_open(self):
        self.assertNotIn('window.open(', UI)
        self.assertNotIn('about:blank', UI)
        self.assertIn('window.location.assign(authorizationUrl)', UI)
        self.assertIn('pass50_meta_oauth_return', UI)

    def test_oauth_return_does_not_pollute_application_url(self):
        self.assertIn('P50MO_RESULT_STORAGE_KEY', CORE)
        self.assertIn('sessionStorage.setItem', CORE)
        self.assertIn('pass50_meta_oauth_result_v2', UI)
        self.assertNotIn("$query=['meta_oauth'", CORE)
        self.assertIn("url.searchParams.get('meta_oauth_code')", UI)

    def test_oauth_return_stays_on_callback_origin(self):
        self.assertIn("$target='/'", CORE)
        self.assertIn('window.location.origin', CORE)
        self.assertNotIn("$config['app']['base_url']", CORE)

    def test_connector_sections_are_collapsible_and_future_ready(self):
        self.assertIn('connector-sections-v1.js?v=1.1', LOADER)
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
