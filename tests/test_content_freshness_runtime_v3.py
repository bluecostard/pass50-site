import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/content-freshness-cron-v3.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/content-freshness-5m.yml").read_text(encoding="utf-8")


class ContentFreshnessRuntimeV3Tests(unittest.TestCase):
    def test_endpoint_is_strict_signed_and_versioned(self):
        self.assertIn("CONTENT-FRESHNESS-V3.0", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS=300", ENDPOINT)
        self.assertIn("['probe','refresh']", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("$keys!==['action','dispatchId']", ENDPOINT)
        self.assertIn("'publicStateWrites'=>0", ENDPOINT)

    def test_selection_checks_access_before_choosing_eight_profiles(self):
        self.assertIn("p50_cf3_ranked_profiles($pdo,70)", ENDPOINT)
        self.assertIn("p50_cf3_authorized_rows", ENDPOINT)
        self.assertIn("p50_mc_platform_enabled", ENDPOINT)
        self.assertIn("p50_mc_public_access", ENDPOINT)
        self.assertIn("configurationMissing", ENDPOINT)
        self.assertIn("authorizationRequired", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_PROFILE_LIMIT=8", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_JOB_LIMIT=16", ENDPOINT)
        self.assertIn("if(count($selectedProfiles)>=$profileLimit)break", ENDPOINT)
        self.assertIn("if(count($selectedJobs)>=$jobLimit)break", ENDPOINT)

    def test_five_minute_idempotency_is_independent_from_p0_bucket(self):
        self.assertIn("floor($now/P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS)", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_VERSION,$bucket,$profileId,$platform", ENDPOINT)
        self.assertIn("'priority'=>5", ENDPOINT)
        self.assertIn("'cadence'=>'p0'", ENDPOINT)
        self.assertIn("'reason'=>'content_freshness_v3'", ENDPOINT)
        self.assertNotIn("p50_mo_enqueue_profile($pdo", ENDPOINT)

    def test_platform_diagnostics_cover_access_selection_and_processing(self):
        for marker in (
            "accessSummary",
            "accessByPlatform",
            "selectedByPlatform",
            "enqueueByPlatform",
            "processedByPlatform",
            "authorizedLinks",
            "profilesScanned",
            "profilesSelected",
        ):
            self.assertIn(marker, ENDPOINT)
        self.assertIn("p50_cf3_platform_counter", ENDPOINT)
        self.assertIn("contentIntelligence", ENDPOINT)

    def test_errors_expose_a_sanitized_stage_without_public_write(self):
        self.assertIn("$stage='bootstrap'", ENDPOINT)
        for stage in ("selection", "enqueue", "work", "content_intelligence"):
            self.assertIn(f"$stage='{stage}'", ENDPOINT)
        self.assertIn("p50_metrics_safe_error", ENDPOINT)
        self.assertIn("'errorCode'=>'content_freshness_'.$stage", ENDPOINT)
        self.assertIn("'detail'=>$detail", ENDPOINT)
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "metrics-ranking-publication-apply",
        ):
            self.assertNotIn(forbidden, ENDPOINT)

    def test_workflow_uses_v3_on_the_five_minute_schedule(self):
        self.assertIn("cron: '*/5 * * * *'", WORKFLOW)
        self.assertIn("content-freshness-cron-v3.php", WORKFLOW)
        self.assertIn("CONTENT-FRESHNESS-V3.0", WORKFLOW)
        self.assertIn("bucketSeconds == 300", WORKFLOW)
        self.assertNotIn("CONTENT-FRESHNESS-V2.0", WORKFLOW)
        self.assertNotIn("content-freshness-cron.php\"", WORKFLOW)
        self.assertIn("for attempt in $(seq 1 5)", WORKFLOW)
        self.assertIn("stage=", WORKFLOW)
        self.assertIn("selectedByPlatform", WORKFLOW)
        self.assertIn("processedByPlatform", WORKFLOW)
        self.assertIn("Écriture directe app_state : `0`", WORKFLOW)

    def test_workflow_waits_for_the_versioned_endpoint_on_push(self):
        self.assertIn("Wait for deployed Content Freshness V3 contract", WORKFLOW)
        self.assertIn("github.event_name == 'push'", WORKFLOW)
        self.assertIn("for attempt in $(seq 1 36)", WORKFLOW)
        self.assertIn("action:\"probe\"", WORKFLOW)
        self.assertIn("bucketSeconds == 300", WORKFLOW)
        self.assertIn("timeout-minutes: 15", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
