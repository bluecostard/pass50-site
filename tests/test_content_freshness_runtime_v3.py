import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/content-freshness-cron-v3.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/content-freshness-5m.yml").read_text(encoding="utf-8")


class ContentFreshnessRuntimeV32Tests(unittest.TestCase):
    def test_endpoint_is_strict_signed_and_versioned(self):
        self.assertIn("CONTENT-FRESHNESS-V3.2", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS=300", ENDPOINT)
        self.assertIn("['probe','refresh']", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("$keys!==['action','dispatchId']", ENDPOINT)
        self.assertIn("'publicStateWrites'=>0", ENDPOINT)

    def test_ranked_selection_is_mariadb_safe(self):
        self.assertGreaterEqual(ENDPOINT.count("SELECT ordered.profile_id"), 2)
        self.assertGreaterEqual(ENDPOINT.count("MAX(c.last_seen_at) AS latest_content"), 2)
        self.assertGreaterEqual(ENDPOINT.count("CASE WHEN ordered.latest_content IS NULL THEN 0 ELSE 1 END"), 2)
        self.assertNotIn("ORDER BY latest_content IS NULL", ENDPOINT)
        self.assertIn("ordered.latest_content ASC,ordered.rank_position ASC", ENDPOINT)

    def test_authorized_tiktok_profiles_are_prioritized_but_not_reserved_in_top_five(self):
        self.assertIn("P50_CONTENT_FRESHNESS_V3_TIKTOK_OAUTH_LIMIT=4", ENDPOINT)
        self.assertIn("p50tm_authorized_profile_ids($pdo)", ENDPOINT)
        self.assertIn("p50_cf3_prioritize_tiktok_oauth", ENDPOINT)
        self.assertIn("tiktokOauthProfilesPrioritized", ENDPOINT)
        self.assertLess(ENDPOINT.index("p50_cf3_prioritize_tiktok_oauth"), ENDPOINT.index("p50_cf3_authorized_rows"))
        for forbidden in ("tiktokTopFiveQuota", "reserveTikTok", "minimumTikTokTrend", "platformQuota"):
            self.assertNotIn(forbidden, ENDPOINT)

    def test_selection_checks_access_before_choosing_eight_profiles(self):
        self.assertIn("p50_cf3_ranked_profiles($pdo,70)", ENDPOINT)
        self.assertIn("p50_cf3_authorized_rows", ENDPOINT)
        self.assertIn("p50_mc_platform_enabled", ENDPOINT)
        self.assertIn("p50_mc_public_access", ENDPOINT)
        self.assertIn("configurationMissing", ENDPOINT)
        self.assertIn("authorizationRequired", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_PROFILE_LIMIT=8", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_JOB_LIMIT=16", ENDPOINT)

    def test_five_minute_idempotency_is_independent_from_p0_bucket(self):
        self.assertIn("floor($now/P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS)", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V3_VERSION,$bucket,$profileId,$platform", ENDPOINT)
        self.assertIn("'priority'=>5", ENDPOINT)
        self.assertIn("'reason'=>'content_freshness_v3'", ENDPOINT)
        self.assertNotIn("p50_mo_enqueue_profile($pdo", ENDPOINT)

    def test_platform_diagnostics_cover_access_selection_and_processing(self):
        for marker in ("accessSummary", "accessByPlatform", "selectedByPlatform", "enqueueByPlatform", "processedByPlatform", "authorizedLinks", "profilesScanned", "profilesSelected", "tiktokOauthProfilesPrioritized"):
            self.assertIn(marker, ENDPOINT)
        self.assertIn("p50_cf3_platform_counter", ENDPOINT)
        self.assertIn("contentIntelligence", ENDPOINT)

    def test_errors_are_sanitized_and_no_public_write_exists(self):
        self.assertIn("$stage='bootstrap'", ENDPOINT)
        self.assertIn("p50_metrics_safe_error", ENDPOINT)
        self.assertIn("'errorCode'=>'content_freshness_'.$stage", ENDPOINT)
        for forbidden in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state", "metrics-ranking-publication-apply"):
            self.assertNotIn(forbidden, ENDPOINT)

    def test_production_workflow_retires_v32_for_resilient_v4(self):
        self.assertIn("cron: '17 */3 * * *'", WORKFLOW)
        self.assertIn("cycles=36", WORKFLOW)
        self.assertIn("content-freshness-cron-v4.php", WORKFLOW)
        self.assertIn("CONTENT-FRESHNESS-V4.0", WORKFLOW)
        self.assertNotIn("content-freshness-cron-v3.php", WORKFLOW)
        self.assertNotIn("CONTENT-FRESHNESS-V3.2", WORKFLOW)
        self.assertIn("bucketSeconds==300", WORKFLOW)
        self.assertIn("fresh-v41", WORKFLOW)
        self.assertIn("resilience==\"V4.1\"", WORKFLOW)
        self.assertIn("progress.ndjson", WORKFLOW)

    def test_production_workflow_waits_for_resilient_v4_endpoint_on_push(self):
        self.assertIn("Wait for deployed Content Freshness V4.1 resilience contract", WORKFLOW)
        self.assertIn("github.event_name == 'push'", WORKFLOW)
        self.assertIn("for attempt in $(seq 1 36)", WORKFLOW)
        self.assertIn("action:\"probe\"", WORKFLOW)
        self.assertIn("timeout-minutes: 185", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
