import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GATE = (ROOT / "api/metrics-ranking-fresh-capture-v2-core.php").read_text(encoding="utf-8")
CRON = (ROOT / "api/metrics-ranking-cron-v2.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/validate-metrics-ranking-fresh-capture-v1.yml").read_text(encoding="utf-8")


class MetricsRankingFreshCaptureV2Tests(unittest.TestCase):
    def test_gate_preserves_recent_success_without_new_ingestion(self):
        self.assertIn("MR-FRESH-CAPTURE-V2.0", GATE)
        self.assertIn("reason'=>'recent_success'", GATE)
        self.assertIn("$finishedAt<=$now->modify", GATE)
        self.assertIn("if($latestRecordedAt===null)return", GATE)

    def test_only_new_usable_confident_ingestions_override_delay(self):
        self.assertIn("quality_status='usable'", GATE)
        self.assertIn("confidence>=70", GATE)
        self.assertIn("MAX(captured_at)", GATE)
        self.assertIn("captured_at>? AND captured_at<=?", GATE)
        self.assertNotIn("MAX(observed_at)", GATE)
        self.assertIn("p50_mr_v2_latest_usable_capture_recorded_after", GATE)
        self.assertIn("'freshCaptureOverride'=>true", GATE)
        self.assertIn("'latestUsableCaptureRecordedAt'", GATE)
        self.assertIn("p50_mr_calculate($pdo,array_keys(p50_mr_periods())", GATE)

    def test_versioned_endpoint_uses_and_exposes_v2_gate(self):
        self.assertIn("METRICS-RANKING-CRON-V2.0", CRON)
        self.assertIn("metrics-ranking-fresh-capture-v2-core.php", CRON)
        self.assertIn("p50_mr_v2_calculate_if_due", CRON)
        self.assertIn("freshCaptureGateVersion", CRON)
        self.assertIn("freshCaptureOverride", CRON)
        self.assertIn("latestUsableCaptureRecordedAt", CRON)
        self.assertNotIn("metrics-ranking-fresh-capture-core.php", CRON)
        self.assertNotIn("p50_mr_calculate_if_due_with_fresh_captures", CRON)

    def test_no_public_state_write(self):
        for token in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state"):
            self.assertNotIn(token, GATE + CRON)

    def test_validation_runs_static_and_mariadb_contracts(self):
        self.assertIn("mariadb:11.4", WORKFLOW)
        self.assertIn("test_metrics_ranking_fresh_capture_v1", WORKFLOW)
        self.assertIn("metrics_ranking_fresh_capture_integration.php", WORKFLOW)
        self.assertIn("metrics-ranking-fresh-capture-v2-core.php", WORKFLOW)
        self.assertIn("metrics-ranking-cron-v2.php", WORKFLOW)
        for variable in ("P50_TEST_DSN", "P50_TEST_DB_USER", "P50_TEST_DB_PASSWORD"):
            self.assertIn(variable, WORKFLOW)


if __name__ == "__main__":
    unittest.main()
