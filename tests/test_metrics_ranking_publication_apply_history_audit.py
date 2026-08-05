import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/metrics-ranking-publication-apply-history-cron-v1.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/metrics-ranking-publication-apply-history-audit.yml").read_text(encoding="utf-8")
VALIDATE = (ROOT / ".github/workflows/validate-metrics-ranking-publication-apply-history-audit.yml").read_text(encoding="utf-8")


class MetricsRankingPublicationApplyHistoryAuditTests(unittest.TestCase):
    def test_endpoint_is_strict_signed_read_only_json(self):
        self.assertIn("PUBAPPLY-HISTORY-V1.0", ENDPOINT)
        self.assertIn("$_SERVER['REQUEST_METHOD']!=='POST'", ENDPOINT)
        self.assertIn("^application/json", ENDPOINT)
        self.assertIn("$length>16384", ENDPOINT)
        self.assertIn("HTTP_X_P50_TIMESTAMP", ENDPOINT)
        self.assertIn("HTTP_X_P50_SIGNATURE", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("in_array($action,['probe','history'],true)", ENDPOINT)
        self.assertIn("'readOnly'=>true", ENDPOINT)
        self.assertIn("'publicStateWrites'=>0", ENDPOINT)

    def test_endpoint_reads_only_sanitized_columns(self):
        self.assertIn("FROM p50_metric_publication_applies ORDER BY id DESC LIMIT 25", ENDPOINT)
        self.assertIn("hasPriorSuccess", ENDPOINT)
        self.assertIn("latestApplied", ENDPOINT)
        self.assertIn("statusCounts", ENDPOINT)
        self.assertIn("'actor'=>$actor", ENDPOINT)
        self.assertNotIn("backup_json", ENDPOINT)
        self.assertNotIn("report_json", ENDPOINT)
        self.assertNotIn("SELECT *", ENDPOINT)
        self.assertNotIn("p50_mrp_apply_ensure_schema", ENDPOINT)

    def test_endpoint_has_no_mutating_sql_or_apply_call(self):
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "UPDATE p50_metric_publication_applies",
            "INSERT INTO p50_metric_publication_applies",
            "DELETE FROM p50_metric_publication_applies",
            "p50_mrp_apply_execute(",
            "p50_mrp_apply_rollback(",
        ):
            self.assertNotIn(forbidden, ENDPOINT)

    def test_workflow_uses_only_probe_and_history(self):
        self.assertIn("name: Metrics Ranking Publication Apply History Audit", WORKFLOW)
        self.assertIn("action:\"probe\"", WORKFLOW)
        self.assertIn("action:\"history\"", WORKFLOW)
        self.assertNotIn("action:\"apply\"", WORKFLOW)
        self.assertNotIn("action:\"rollback\"", WORKFLOW)
        self.assertNotIn("confirm:true", WORKFLOW)
        self.assertNotIn("bootstrap:true", WORKFLOW)
        self.assertNotIn("metrics-ranking-publication-apply.yml/dispatches", WORKFLOW)
        self.assertIn("Backups et rapports internes exposés : `non`", WORKFLOW)
        self.assertIn("Écriture app_state : `0`", WORKFLOW)

    def test_workflow_permissions_and_retries_remain_safe(self):
        permissions = WORKFLOW.split("permissions:", 1)[1].split("concurrency:", 1)[0]
        self.assertIn("contents: read", permissions)
        self.assertIn("statuses: write", permissions)
        self.assertNotIn("actions: write", permissions)
        self.assertIn("HTTP ${http_code:-000}", WORKFLOW)
        self.assertIn("502", WORKFLOW)
        self.assertIn("503", WORKFLOW)
        self.assertIn("504", WORKFLOW)
        self.assertIn("pass50/publication-apply-history-audit", WORKFLOW)

    def test_validation_lints_endpoint_and_contract(self):
        self.assertIn("php -l api/metrics-ranking-publication-apply-history-cron-v1.php", VALIDATE)
        self.assertIn("test_metrics_ranking_publication_apply_history_audit", VALIDATE)
        self.assertIn("git diff --check", VALIDATE)


if __name__ == "__main__":
    unittest.main()
