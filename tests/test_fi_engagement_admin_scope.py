import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ENGAGEMENT = (ROOT / "fi-engagement-v3.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class FiEngagementAdminScopeTests(unittest.TestCase):
    def test_engagement_tab_guard_targets_profiles(self):
        guard = re.search(
            r"function isEngagementAdminTab\(\)\{(.*?)\n\}",
            ENGAGEMENT,
            re.DOTALL,
        )
        self.assertIsNotNone(guard)
        self.assertIn("ui?.adminTab==='profiles'", guard.group(1))
        self.assertIn("return false", guard.group(1))

    def test_admin_metrics_stops_and_cleans_up_outside_profiles(self):
        metrics = self._admin_metrics()
        guard_position = metrics.index("if(!isEngagementAdminTab())")
        fetch_position = metrics.index("await fetch(API")
        self.assertLess(guard_position, fetch_position)
        self.assertIn(
            "pane.querySelectorAll('.p50-admin-metrics').forEach(element=>element.remove())",
            metrics,
        )
        self.assertIn("delete pane.dataset.p50MetricsLoading", metrics)
        outside_profiles = metrics[
            guard_position : metrics.index(
                "if(pane.querySelector('.p50-admin-metrics')",
                guard_position,
            )
        ]
        self.assertIn(
            "return;",
            outside_profiles,
        )

    def test_late_response_cannot_inject_into_another_admin_page(self):
        metrics = self._admin_metrics()
        fetch_position = metrics.index("await fetch(API")
        late_guard = (
            "if(!isEngagementAdminTab()||!pane.isConnected||"
            "pane!==document.getElementById('adminPane'))return;"
        )
        guard_position = metrics.index(late_guard)
        prepend_position = metrics.index("pane.prepend(box)")
        self.assertGreater(guard_position, fetch_position)
        self.assertLess(guard_position, prepend_position)
        self.assertIn("pane.isConnected", metrics)
        self.assertIn("pane!==document.getElementById('adminPane')", metrics)

    def test_engagement_table_features_are_preserved(self):
        self.assertEqual(ENGAGEMENT.count("box.className='p50-admin-metrics'"), 1)
        self.assertIn("Engagement des fiches", ENGAGEMENT)
        self.assertIn("p50EngagementSearch", ENGAGEMENT)
        self.assertIn("<th>Likes</th>", ENGAGEMENT)
        self.assertIn("<th>Partages FI</th>", ENGAGEMENT)
        self.assertIn("<th>Partages live</th>", ENGAGEMENT)
        self.assertIn("completeEngagementRows", ENGAGEMENT)
        self.assertIn("item.total===0?'p50-metric-zero'", ENGAGEMENT)

    def test_public_like_and_share_enhancements_remain_present(self):
        for function_name in (
            "enhanceProfile",
            "enhanceHomeLikes",
            "enhanceLiveModal",
            "record",
            "share",
        ):
            self.assertIn(f"function {function_name}(", ENGAGEMENT)
        self.assertIn("record('like',id)", ENGAGEMENT)
        self.assertIn("'profile_share'", ENGAGEMENT)
        self.assertIn("'live_share'", ENGAGEMENT)

    def test_cache_versions_are_consistent(self):
        reference = "fi-engagement-v3.js?v=1.3"
        self.assertIn(reference, CONFIG)
        self.assertIn(reference, SW)
        self.assertIn("script.dataset.pass50FiEngagement = '3.3'", CONFIG)
        self.assertIn("const CACHE='pass50-v41-engagement-admin-scope'", SW)
        self.assertNotIn("fi-engagement-v3.js?v=1.2", CONFIG)
        self.assertNotIn("fi-engagement-v3.js?v=1.2", SW)

    @staticmethod
    def _admin_metrics():
        match = re.search(
            r"async function adminMetrics\(\)\{(.*?)\n\}\n\nfunction adminVerifiedToggle",
            ENGAGEMENT,
            re.DOTALL,
        )
        if match is None:
            raise AssertionError("adminMetrics introuvable")
        return match.group(1)


if __name__ == "__main__":
    unittest.main()
