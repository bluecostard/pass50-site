from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
CLIENT = (ROOT / "fi-engagement-v3.js").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api" / "fi-engagement.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class FiEngagementCompleteAdminTests(unittest.TestCase):
    def test_endpoint_returns_profiles_that_have_events(self):
        self.assertIn("FROM p50_fi_engagement GROUP BY profile_id", ENDPOINT)

    def test_client_merges_server_counts_with_every_local_profile(self):
        self.assertIn("function completeEngagementRows", CLIENT)
        self.assertIn("Array.isArray(db.profiles)?db.profiles:[]", CLIENT)
        self.assertIn("const metricsById=new Map", CLIENT)
        self.assertIn("const allRows=completeEngagementRows", CLIENT)

    def test_admin_table_is_not_limited_to_fifty_active_profiles(self):
        self.assertNotIn(".slice(0,50)", CLIENT)
        self.assertIn("les autres apparaissent avec zéro", CLIENT)
        self.assertIn("data-engagement-row", CLIENT)

    def test_profile_ids_are_resolved_to_real_names(self):
        self.assertIn("name:String(profileItem.name||profileItem.handle", CLIENT)
        self.assertIn("handle:String(profileItem.handle||'')", CLIENT)

    def test_search_and_scroll_keep_the_full_list_usable(self):
        self.assertIn("p50EngagementSearch", CLIENT)
        self.assertIn("p50-admin-metrics-table-wrap", CLIENT)
        self.assertIn("max-height:430px", CLIENT)

    def test_new_client_version_is_loaded_and_precached(self):
        self.assertIn("fi-engagement-v3.js?v=1.2", CONFIG)
        self.assertIn("fi-engagement-v3.js?v=1.2", SW)
        self.assertIn("pass50-v38-engagement-all-profiles", SW)


if __name__ == "__main__":
    unittest.main()
