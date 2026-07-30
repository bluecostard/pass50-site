import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
BRIDGE=(ROOT/'api'/'meta-metrics-bridge-core.php').read_text(encoding='utf-8')
COLLECTORS=(ROOT/'api'/'metrics-collectors-core.php').read_text(encoding='utf-8')
SOCIAL=(ROOT/'api'/'metrics-social-collectors-core.php').read_text(encoding='utf-8')
ORCHESTRATOR=(ROOT/'api'/'metrics-orchestrator-core.php').read_text(encoding='utf-8')
MAP=(ROOT/'api'/'meta-oauth-map-asset.php').read_text(encoding='utf-8')
FACEBOOK=(ROOT/'api'/'metrics-collector-facebook.php').read_text(encoding='utf-8')
INSTAGRAM=(ROOT/'api'/'metrics-collector-instagram.php').read_text(encoding='utf-8')
CONTROL=(ROOT/'api'/'metrics-control-center-core.php').read_text(encoding='utf-8')
UI=(ROOT/'data-engine-ui.js').read_text(encoding='utf-8')

class MetaOauthAutomaticMetricsTests(unittest.TestCase):
    def test_bridge_is_safe_and_mapping_based(self):
        self.assertIn("function p50mm_asset_for_profile",BRIDGE)
        self.assertIn("function p50mm_official_profile",BRIDGE)
        self.assertIn("function p50mm_credentials",BRIDGE)
        self.assertIn("function p50mm_orchestrator_rows",BRIDGE)
        self.assertIn("p50mo_decrypt",BRIDGE)
        safe=BRIDGE[BRIDGE.index('function p50mm_safe_status'):]
        self.assertNotIn("access_token_encrypted'",safe)
        self.assertNotIn("'userId'",safe)

    def test_collectors_prefer_meta_oauth(self):
        self.assertIn("meta-metrics-bridge-core.php",COLLECTORS)
        self.assertIn("p50mm_official_profile",COLLECTORS)
        self.assertIn("p50mm_credentials",SOCIAL)
        self.assertIn("p50_msc_graph_root",SOCIAL)
        self.assertIn("insightsAuthorized",FACEBOOK)
        self.assertIn("insightsAuthorized",INSTAGRAM)

    def test_orchestrator_and_mapping_enqueue(self):
        self.assertIn("p50mm_authorized_profile_ids",ORCHESTRATOR)
        self.assertIn("p50mm_orchestrator_rows",ORCHESTRATOR)
        self.assertIn("p50_mo_enqueue_profile",MAP)
        self.assertIn("meta_oauth_mapping",MAP)

    def test_control_center_exposes_safe_meta_status(self):
        self.assertIn("p50mm_safe_status",CONTROL)
        self.assertIn("metaOAuth",CONTROL)
        self.assertIn("COMPTES META AUTORISÉS",UI)

if __name__=='__main__':
    unittest.main()
