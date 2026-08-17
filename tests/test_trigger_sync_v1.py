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
    def test_server_syncs_all_profiles_after_content_intelligence_refresh(self):
        self.assertIn("trigger-sync-core.php", CORE)
        self.assertIn("p50_trigger_sync_profiles($pdo)", CORE)
        self.assertIn("triggerSync", CORE)

    def test_trigger_sync_core_constants(self):
        self.assertIn("P50_TRIGGER_SYNC_VERSION = 'PASS50-TRIGGER-SYNC-V1.2'", TRIGGER)
        self.assertIn("p50_trigger_syncable_profile_ids", TRIGGER)
        self.assertIn("p50_trigger_latest_content", TRIGGER)
        self.assertIn("p50_trigger_query_metric_content", TRIGGER)
        self.assertIn("168 * 3600", TRIGGER)

    def test_client_respects_stale_trigger_for_all_profiles(self):
        self.assertIn("function p50TriggerIsStale(event)", V9)
        self.assertIn("if(event.autoSynced)return age>168*3600*1000", V9)
        self.assertNotIn("p50IsTop10Profile", V9)
        self.assertIn("function p50TriggerKicker(profileId)", V9)
        self.assertIn("if(event.manualDataValidated)return age>7*24*3600*1000", V9)
        self.assertIn("ev=rawEv&&!p50TriggerIsStale(rawEv)?rawEv:null", V9)
        self.assertIn("function p50SyncTriggerFromOfficialNews", V9)

    def test_content_intelligence_applies_official_trigger_sync(self):
        self.assertIn("p50SyncTriggerFromOfficialNews", CLIENT)
        self.assertIn("content-intelligence.js?v=1.12", CONFIG)
        self.assertIn("content-intelligence.js?v=1.12", SW)


if __name__ == "__main__":
    unittest.main()
