import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MetricsRankingPublicationApplyV1Tests(unittest.TestCase):
    def test_apply_core_writes_public_state_with_backup(self):
        core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn("PUBAPPLY-V1.0", core)
        self.assertIn("p50_metric_publication_applies", core)
        self.assertIn("UPDATE app_state", core)
        self.assertIn("backup_json", core)
        self.assertIn("p50_mrp_apply_rollback", core)
        self.assertIn("bootstrap", core)
        self.assertIn("p50_mrp_apply_is_skippable_plan", core)
        self.assertIn("publishPlans", core)
        self.assertIn("p50_de_load_public_state_for_update", core)

    def test_admin_and_cron_endpoints_exist(self):
        admin = read("api/metrics-ranking-publication-apply.php")
        cron = read("api/metrics-ranking-publication-apply-cron.php")
        self.assertIn("require_role($user,'owner','admin')", admin)
        self.assertIn("p50_mrp_apply_execute", admin)
        self.assertIn("p50_mo_verify_cron_signature", cron)
        self.assertIn("automaticPublicationEnabled", cron)
        # After sort($keys), confirm comes before dispatchId alphabetically.
        self.assertIn("'action','confirm','dispatchId'", cron)
        self.assertIn("'action','bootstrap','confirm','dispatchId'", cron)
        self.assertIn("'bootstrap'=>$forceBootstrap", cron)
        self.assertNotIn("'action','dispatchId','confirm'", cron)

    def test_force_bootstrap_flows_into_preview(self):
        core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn("bool $forceBootstrap=false", core)
        self.assertIn("p50_mrp_apply_preview($pdo,P50_MRP_APPLY_PERIODS,$now,$forceBootstrap)", core)
        self.assertIn("$forceBootstrap||!p50_mrp_apply_has_prior_success($pdo)", core)
        self.assertIn("function p50_mrp_apply_state_actor", core)
        self.assertIn("p50_mrp_apply_state_actor($appliedBy)", core)

    def test_config_exposes_publication_flags(self):
        example = read("api/config.example.php")
        self.assertIn("ranking_publication_enabled", example)
        self.assertIn("ranking_automatic_publication_enabled", example)
        self.assertIn("PASS50_RANKING_PUBLICATION_ENABLED", example)
        self.assertIn("PASS50_METRICS_ORCHESTRATOR_ENABLED", example)

    def test_workflow_and_admin_ui_wired(self):
        workflow = read(".github/workflows/metrics-ranking-publication-apply.yml")
        ui = read("data-engine-ui.js")
        self.assertIn("metrics-ranking-publication-apply-cron.php", workflow)
        self.assertIn("de-ranking-publish", ui)
        self.assertIn("metrics-ranking-publication-apply.php", ui)
        self.assertIn("dePublishOnlyStale", ui)
        self.assertIn("run_freshness", ui)

    def test_simulation_remains_read_only(self):
        core = read("api/metrics-ranking-publication-core.php")
        self.assertIn("'publicStateWrites'=>0", core)
        self.assertIn("'publicationEnabled'=>false", core)


if __name__ == "__main__":
    unittest.main()
