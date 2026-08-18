import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/content-intelligence-core.php").read_text(encoding="utf-8")
TRIGGER = (ROOT / "api/trigger-sync-core.php").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
CLIENT = (ROOT / "content-intelligence.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class TriggerSyncV1Tests(unittest.TestCase):
    def test_server_syncs_top50_after_content_intelligence_refresh(self):
        self.assertIn("trigger-sync-core.php", CORE)
        self.assertIn("p50_trigger_sync_top50($pdo)", CORE)
        self.assertIn("triggerSync", CORE)

    def test_trigger_sync_core_constants(self):
        self.assertIn("P50_TRIGGER_SYNC_VERSION = 'PASS50-TRIGGER-SYNC-V1.3'", TRIGGER)
        self.assertIn("p50_trigger_ranked_profile_ids($state, 50)", TRIGGER)
        self.assertIn("p50_trigger_reason_for_rank", TRIGGER)
        self.assertIn("p50_trigger_latest_content", TRIGGER)
        self.assertIn("168 * 3600", TRIGGER)

    def test_client_respects_top50_gate_and_labels(self):
        self.assertIn("function p50TriggerIsStale(event)", V9)
        self.assertIn("function p50IsTop50Profile(id)", V9)
        self.assertIn("if(!p50IsTop50Profile(profileId))return false", V9)
        self.assertIn("function p50TriggerKicker(profileId)", V9)
        self.assertIn("POURQUOI DANS LE TOP 10 ?", V9)
        self.assertIn("POURQUOI DANS LE TOP 50 ?", V9)
        self.assertNotIn("POURQUOI DANS LE TOP 5 ?", V9)
        self.assertNotIn("ACTUALITÉ RÉCENTE", V9)
        self.assertIn("v9-tools.js?v=15.28", SW)

    def test_content_intelligence_applies_official_trigger_sync(self):
        self.assertIn("p50SyncTriggerFromOfficialNews", CLIENT)
        self.assertIn("content-intelligence.js?v=1.14", CONFIG)
        self.assertIn("content-intelligence.js?v=1.14", SW)


if __name__ == "__main__":
    unittest.main()
