import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/content-freshness-platform-audit-cron-v2.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/content-freshness-platform-audit.yml").read_text(encoding="utf-8")
FRESHNESS = (ROOT / ".github/workflows/content-freshness-5m.yml").read_text(encoding="utf-8")


class ContentFreshnessPlatformAuditV21Tests(unittest.TestCase):
    def test_endpoint_is_strict_hmac_read_only_and_versioned(self):
        self.assertIn("CONTENT-PLATFORM-AUDIT-V2.1", ENDPOINT)
        self.assertIn("CONTENT-FRESHNESS-V4.0", ENDPOINT)
        self.assertNotIn("CONTENT-FRESHNESS-V3.2", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("['probe','audit']", ENDPOINT)
        self.assertIn("'readOnly'=>true", ENDPOINT)
        self.assertIn("'profilesExposed'=>false", ENDPOINT)
        self.assertIn("'secretsExposed'=>false", ENDPOINT)
        self.assertIn("'publicStateWrites'=>0", ENDPOINT)
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "p50_metrics_process_next_job",
            "p50_mo_enqueue_profile",
            "p50_ci_refresh(",
        ):
            self.assertNotIn(forbidden, ENDPOINT)

    def test_runtime_reports_the_real_five_minute_bucket(self):
        self.assertIn("P50_CONTENT_FRESHNESS_BUCKET_MINUTES=5", ENDPOINT)
        self.assertIn("'scheduleMinutes'=>5", ENDPOINT)
        self.assertIn("'collectionBucketMinutes'=>P50_CONTENT_FRESHNESS_BUCKET_MINUTES", ENDPOINT)
        self.assertIn("'scheduledCyclesPerBucket'=>1", ENDPOINT)
        self.assertNotIn("collectionIdempotencyBucketMinutes'=>15", ENDPOINT)
        self.assertIn("cron: '*/5 * * * *'", FRESHNESS)
        self.assertIn("content-freshness-cron-v4.php", FRESHNESS)

    def test_x_health_uses_the_real_social_link_primary_key(self):
        self.assertIn("function p50_cpa2_x_health", ENDPOINT)
        self.assertIn("ORDER BY s.confidence DESC,s.profile_id ASC LIMIT 1", ENDPOINT)
        self.assertNotIn("ORDER BY s.confidence DESC,s.id", ENDPOINT)
        self.assertNotIn("s.id LIMIT 1", ENDPOINT)
        self.assertIn("array_replace($base,['category'=>'verified_source_missing'])", ENDPOINT)

    def test_x_health_is_sanitized_and_exposes_only_http_category(self):
        self.assertIn("p50_mc_config('X')", ENDPOINT)
        self.assertIn("p50_mc_x_handle", ENDPOINT)
        self.assertIn("https://api.x.com/2/users/by/username/", ENDPOINT)
        self.assertIn("p50_cpa2_http_category", ENDPOINT)
        for category in (
            "payment_required",
            "unauthorized",
            "forbidden",
            "rate_limited",
            "server_error",
            "configuration_missing",
            "verified_source_missing",
        ):
            self.assertIn(category, ENDPOINT)
        x_function = ENDPOINT[
            ENDPOINT.index("function p50_cpa2_x_health") : ENDPOINT.index("function p50_cpa2_tiktok_health")
        ]
        for exposed in ("normalized_url'=>", "profileId", "handle'=>", "body'=>", "secret'=>", "token'=>"):
            self.assertNotIn(exposed, x_function)

    def test_tiktok_health_counts_only_authorized_profiles(self):
        self.assertIn("function p50_cpa2_tiktok_health", ENDPOINT)
        self.assertIn("p50tm_authorized_profile_ids($pdo)", ENDPOINT)
        self.assertIn("authorizedOauthProfiles", ENDPOINT)
        self.assertIn("rapidCycleEligible", ENDPOINT)

    def test_audit_covers_collection_fi_and_all_top_five_periods(self):
        for marker in (
            "verifiedLinks",
            "activeMetricAccounts",
            "fiveMinuteCollection",
            "contentInventory",
            "fiNews",
            "trends",
            "top5PlatformCounts",
            "eligibleCandidatePlatformCounts",
            "youtubeSharePercent",
            "nonYoutubeCandidatesAvailable",
            "sourceHealth",
        ):
            self.assertIn(marker, ENDPOINT + WORKFLOW)
        for period in ('"2h"', '"24h"', '"48h"', '"7d"', '"15d"'):
            self.assertIn(period, WORKFLOW)

    def test_workflow_uses_v21_with_v4_runtime_and_only_reads_diagnostics(self):
        self.assertIn("content-freshness-platform-audit-cron-v2.php", WORKFLOW)
        self.assertIn("CONTENT-PLATFORM-AUDIT-V2.1", WORKFLOW)
        self.assertNotIn("CONTENT-PLATFORM-AUDIT-V2.0", WORKFLOW)
        self.assertIn("CONTENT-FRESHNESS-V4.0", WORKFLOW)
        self.assertNotIn("CONTENT-FRESHNESS-V3.2", WORKFLOW)
        self.assertIn("collectionBucketMinutes==5", WORKFLOW)
        self.assertIn("Santé X", WORKFLOW)
        self.assertIn("Profils TikTok OAuth autorisés", WORKFLOW)
        self.assertNotIn("actions: write", WORKFLOW)
        self.assertNotIn("action:\"refresh\"", WORKFLOW)
        self.assertNotIn("metrics-ranking-publication-apply", WORKFLOW)
        self.assertIn("Profils et secrets exposés", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
