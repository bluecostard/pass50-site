from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'meta-oauth-core.php').read_text(encoding='utf-8')
START = (ROOT / 'api' / 'meta-oauth-start.php').read_text(encoding='utf-8')
CONFIG = (ROOT / 'api' / 'config.example.php').read_text(encoding='utf-8')
STATUS = (ROOT / 'api' / 'meta-oauth-status.php').read_text(encoding='utf-8')

class MetaLoginForBusinessV1Tests(unittest.TestCase):
    def test_business_configuration_is_required(self):
        self.assertIn("'configuration_id'", CORE)
        self.assertIn('META_CONFIGURATION_ID', CORE)
        self.assertIn("'config_id'", START)
        self.assertIn("'override_default_response_type'=>'true'", START)
        self.assertNotIn("'scope'=>implode(',',P50MO_REQUIRED_SCOPES)", START)
        self.assertIn("'configuration_id' => getenv('META_CONFIGURATION_ID') ?: ''", CONFIG)
        self.assertIn('configurationIdConfigured', STATUS)

    def test_permissions_are_read_only_and_minimal(self):
        for scope in ('pages_show_list', 'pages_read_engagement', 'instagram_basic'):
            self.assertIn(scope, CORE)
        self.assertNotIn('pages_manage_metadata', CORE)
        self.assertNotIn('pages_manage_posts', CORE)
        self.assertNotIn('instagram_content_publish', CORE)

if __name__ == '__main__':
    unittest.main()
