import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/metrics-ranking-public-state-audit-cron-v1.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/metrics-ranking-public-state-audit.yml").read_text(encoding="utf-8")
VALIDATE = (ROOT / ".github/workflows/validate-metrics-ranking-public-state-audit.yml").read_text(encoding="utf-8")


class MetricsRankingPublicStateAuditTests(unittest.TestCase):
    def test_endpoint_is_strict_signed_read_only(self):
        self.assertIn("MR-PUBLIC-STATE-AUDIT-V1.0", ENDPOINT)
        self.assertIn("$_SERVER['REQUEST_METHOD']!=='POST'", ENDPOINT)
        self.assertIn("^application/json", ENDPOINT)
        self.assertIn("HTTP_X_P50_TIMESTAMP", ENDPOINT)
        self.assertIn("HTTP_X_P50_SIGNATURE", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("in_array($action,['probe','audit'],true)", ENDPOINT)
        self.assertIn("'readOnly'=>true", ENDPOINT)
        self.assertIn("'publicStateWrites'=>0", ENDPOINT)

    def test_endpoint_returns_only_aggregated_profile_information(self):
        self.assertIn("'rankableCount'=>count($publicRows)", ENDPOINT)
        self.assertIn("runReferenceCounts", ENDPOINT)
        self.assertIn("scoreStatuses", ENDPOINT)
        self.assertIn("metadataMatchesLatestAppliedRun", ENDPOINT)
        self.assertIn("profilesReferencingLatestAppliedRun", ENDPOINT)
        self.assertIn("revisionDeltaAfterLatestApply", ENDPOINT)
        self.assertIn("rollbackRevisionStillMatches", ENDPOINT)
        self.assertNotIn("backup_json", ENDPOINT)
        self.assertNotIn("report_json", ENDPOINT)
        self.assertNotIn("canonicalUrl", ENDPOINT)
        self.assertNotIn("public_name", ENDPOINT)
        self.assertNotIn("handle", ENDPOINT)

    def test_endpoint_has_no_mutation_path(self):
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "p50_mrp_apply_execute(",
            "p50_mrp_apply_rollback(",
            "p50_mrp_apply_ensure_schema(",
        ):
            self.assertNotIn(forbidden, ENDPOINT)

    def test_workflow_is_audit_only(self):
        self.assertIn("action:\"probe\"", WORKFLOW)
        self.assertIn("action:\"audit\"", WORKFLOW)
        self.assertNotIn("action:\"apply\"", WORKFLOW)
        self.assertNotIn("action:\"rollback\"", WORKFLOW)
        self.assertNotIn("confirm:true", WORKFLOW)
        self.assertNotIn("bootstrap:true", WORKFLOW)
        self.assertNotIn("actions: write", WORKFLOW)
        self.assertIn("Profils individuels exposés : `non`", WORKFLOW)
        self.assertIn("Écriture app_state : `0`", WORKFLOW)
        self.assertIn("pass50/public-ranking-state-audit", WORKFLOW)

    def test_workflow_handles_transient_ionos_errors(self):
        for code in ("502", "503", "504"):
            self.assertIn(code, WORKFLOW)
        self.assertIn("tentative $attempt/5", WORKFLOW)

    def test_validation_lints_and_checks_contract(self):
        self.assertIn("php -l api/metrics-ranking-public-state-audit-cron-v1.php", VALIDATE)
        self.assertIn("test_metrics_ranking_public_state_audit", VALIDATE)
        self.assertIn("git diff --check", VALIDATE)


if __name__ == "__main__":
    unittest.main()
