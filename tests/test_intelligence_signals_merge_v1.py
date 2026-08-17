import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api" / "intelligence-signals-core.php").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api" / "intelligence.php").read_text(encoding="utf-8")
UI = (ROOT / "intelligence-signals-ui-v1.js").read_text(encoding="utf-8")
LOADER = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")
REFRESH = (ROOT / "api" / "intelligence-refresh-cron-v2.php").read_text(encoding="utf-8")


class IntelligenceSignalsMergeV1Test(unittest.TestCase):
    def test_server_has_one_persistent_signal_source(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS p50_signal_events", CORE)
        self.assertIn("p50_is_import_state_signals", CORE)
        self.assertIn("p50_is_import_live_streams", CORE)
        self.assertIn("p50_is_public_ranking_index", CORE)
        self.assertIn("p50_activity_events", CORE)

    def test_intelligence_combines_signal_and_metric_scores(self):
        self.assertIn("combinedBuzzIndex", CORE)
        self.assertIn("combinedGrowthIndex", CORE)
        self.assertIn("priorityScore", CORE)
        self.assertIn("$signalScore*.40", CORE)
        self.assertIn("$combinedBuzz*.55", CORE)

    def test_human_review_is_preserved(self):
        self.assertIn("p50_is_review_signal", CORE)
        self.assertIn("['validate','reject']", ENDPOINT)
        self.assertIn("reviewed_at=UTC_TIMESTAMP()", CORE)
        self.assertIn("p50is-review", UI)

    def test_single_admin_experience_replaces_legacy_signals_tab(self):
        self.assertIn("PASS50 INTELLIGENCE & SIGNAUX", UI)
        self.assertIn("[data-admin-tab=\"signals\"]", UI)
        self.assertIn("Intelligence & Signaux", UI)
        self.assertIn("intelligence-signals-ui-v1.js?v=1.2", LOADER)

    def test_api_and_refresh_use_the_fused_engine(self):
        live_refresh = (ROOT / "api" / "intelligence-signals-live-refresh.php").read_text(encoding="utf-8")
        self.assertIn("intelligence-signals-live-refresh.php", ENDPOINT)
        self.assertIn("intelligence-signals-core.php", live_refresh)
        self.assertIn("p50_is_dashboard()", ENDPOINT)
        self.assertIn("liveSignalsImported", live_refresh)
        self.assertIn("intelligence-signals-core.php", REFRESH)
        self.assertIn("PASS50-INTELLIGENCE-SIGNALS-REFRESH-V3.0", REFRESH)

    def test_official_ranking_is_not_published_by_the_merge(self):
        self.assertNotIn("p50_de_save_public_state", CORE)
        self.assertNotIn("data-publish.php", CORE)
        self.assertNotIn("metrics-ranking-publication", CORE)
        self.assertIn("source_type='activity' AND reviewed_at IS NULL", CORE)
        self.assertIn("'status'=>'pending'", CORE)
        self.assertIn("p50_is_import_live_streams", CORE)
        self.assertIn("Classement public", UI)

    def test_unranked_activity_does_not_invent_buzz(self):
        result = subprocess.run(
            ["php", str(ROOT / "tests/intelligence_signals_observed_unit.php")],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.stdout.strip(), "Intelligence observed ranking/radar: OK")


if __name__ == "__main__":
    unittest.main()
