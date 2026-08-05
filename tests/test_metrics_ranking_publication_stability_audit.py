import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/metrics-ranking-publication-stability-audit-cron-v1.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/metrics-ranking-publication-stability-audit.yml").read_text(encoding="utf-8")
VALIDATE = (ROOT / ".github/workflows/validate-metrics-ranking-publication-stability-audit.yml").read_text(encoding="utf-8")


class MetricsRankingPublicationStabilityAuditTests(unittest.TestCase):
    def test_endpoint_is_strict_signed_and_advisory_only(self):
        self.assertIn("MR-STABILITY-AUDIT-V1.0", ENDPOINT)
        self.assertIn("$_SERVER['REQUEST_METHOD']!=='POST'", ENDPOINT)
        self.assertIn("^application/json", ENDPOINT)
        self.assertIn("HTTP_X_P50_TIMESTAMP", ENDPOINT)
        self.assertIn("HTTP_X_P50_SIGNATURE", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("in_array($action,['probe','audit'],true)", ENDPOINT)
        self.assertIn("'readOnly'=>true", ENDPOINT)
        self.assertIn("'advisoryOnly'=>true", ENDPOINT)
        self.assertIn("'authorizesPublication'=>false", ENDPOINT)
        self.assertIn("'publicStateWrites'=>0", ENDPOINT)

    def test_endpoint_reads_existing_history_without_mutation(self):
        self.assertIn("FROM p50_metric_publication_simulations", ENDPOINT)
        self.assertIn("p50_mrsa_distinct_recent", ENDPOINT)
        self.assertIn("P50_MR_STABILITY_AUDIT_SAMPLE_SIZE=6", ENDPOINT)
        self.assertNotIn("p50_mrph_ensure_schema(", ENDPOINT)
        self.assertNotIn("p50_mrph_store(", ENDPOINT)
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "INSERT INTO p50_metric_publication_simulations",
            "UPDATE p50_metric_publication_simulations",
            "DELETE FROM p50_metric_publication_simulations",
            "CREATE TABLE",
        ):
            self.assertNotIn(forbidden, ENDPOINT)

    def test_endpoint_exposes_only_aggregated_stability_series(self):
        for marker in (
            "candidateSeries",
            "entrySeries",
            "exitSeries",
            "statusCounts",
            "publicBaseline",
            "latestCandidateDelta",
            "volatilityPercent",
            "overallState",
            "periodStates",
            "writeAnomalies",
        ):
            self.assertIn(marker, ENDPOINT)
        self.assertNotIn("'publicFingerprint'=>", ENDPOINT)
        self.assertNotIn("'candidateFingerprint'=>", ENDPOINT)
        self.assertNotIn("'experimentalRunUuid'=>", ENDPOINT)
        self.assertNotIn("profileId", ENDPOINT)
        self.assertNotIn("public_name", ENDPOINT)
        self.assertNotIn("canonicalUrl", ENDPOINT)

    def test_diagnostic_thresholds_do_not_authorize_publication(self):
        self.assertIn("P50_MR_STABILITY_AUDIT_WARN_VOLATILITY=20.0", ENDPOINT)
        self.assertIn("P50_MR_STABILITY_AUDIT_BLOCK_VOLATILITY=35.0", ENDPOINT)
        self.assertIn("P50_MR_STABILITY_AUDIT_MAX_AGE_HOURS=6.0", ENDPOINT)
        self.assertIn("$overall=$blocked?'blocked':($review?'review':'stable')", ENDPOINT)
        self.assertNotIn("automaticPublicationEligible", ENDPOINT)
        self.assertNotIn("publicationEligible", ENDPOINT)
        self.assertNotIn("p50_mrp_apply_execute(", ENDPOINT)

    def test_workflow_is_probe_and_audit_only(self):
        self.assertIn("action:\"probe\"", WORKFLOW)
        self.assertIn("action:\"audit\"", WORKFLOW)
        self.assertNotIn("action:\"apply\"", WORKFLOW)
        self.assertNotIn("action:\"rollback\"", WORKFLOW)
        self.assertNotIn("confirm:true", WORKFLOW)
        self.assertNotIn("actions: write", WORKFLOW)
        self.assertIn("authorizesPublication == false", WORKFLOW)
        self.assertIn("Cet audit est consultatif et n’autorise aucune publication", WORKFLOW)
        self.assertIn("Profils individuels et empreintes exposés : `non`", WORKFLOW)
        self.assertIn("Écriture app_state : `0`", WORKFLOW)

    def test_workflow_uses_safe_output_names_and_transient_retries(self):
        for output in ("p2h_state", "p24h_state", "p48h_state", "p7j_state", "p15j_state"):
            self.assertIn(output, WORKFLOW)
        self.assertNotIn("outputs.24h_state", WORKFLOW)
        for code in ("502", "503", "504"):
            self.assertIn(code, WORKFLOW)
        self.assertIn("pass50/publication-stability-audit", WORKFLOW)

    def test_validation_lints_endpoint_and_contract(self):
        self.assertIn("php -l api/metrics-ranking-publication-stability-audit-cron-v1.php", VALIDATE)
        self.assertIn("test_metrics_ranking_publication_stability_audit", VALIDATE)
        self.assertIn("git diff --check", VALIDATE)


if __name__ == "__main__":
    unittest.main()
