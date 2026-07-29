from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'meta-oauth-core.php').read_text(encoding='utf-8')
START = (ROOT / 'api' / 'meta-oauth-start.php').read_text(encoding='utf-8')
CONFIG = (ROOT / 'api' / 'config.example.php').read_text(encoding='utf-8')


class MetaLoginForBusinessV1Tests(unittest.TestCase):
    def test_business_login_configuration_is_required(self):
        self.assertIn("'configuration_id'", CORE)
        self.assertIn('META_CONFIGURATION_ID', CORE)
        self.assertIn("'config_id'", START)
        self.assertIn("'override_default_response_type'=>'true'", START)
        self.assertNotIn("'scope'=>implode(',',P50MO_REQUIRED_SCOPES)", START)
        self.assertIn("'configuration_id' => getenv('META_CONFIGURATION_ID') ?: ''", CONFIG)

    def test_only_read_permissions_are_required(self):
        self.assertIn("pages_show_list", CORE)
        self.assertIn("pages_read_engagement", CORE)
        self.assertIn("instagram_basic", CORE)
        self.assertNotIn("pages_manage_metadata", CORE)


if __name__ == '__main__':
    unittest.main()
