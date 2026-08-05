import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/content-freshness-platform-audit-cron-v1.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/content-freshness-platform-audit.yml").read_text(encoding="utf-8")
FRESHNESS = (ROOT / ".github/workflows/content-freshness-5m.yml").read_text(encoding="utf-8")
ORCHESTRATOR = (ROOT / "api/metrics-orchestrator-core.php").read_text(encoding="utf-8")


class ContentFreshnessPlatformAuditTests(unittest.TestCase):
    def test_endpoint_is_strict_hmac_read_only(self):
        self.assertIn("CONTENT-PLATFORM-AUDIT-V1.0", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("['probe','audit']", ENDPOINT)
        self.assertIn("'readOnly'=>true", ENDPOINT)
        self.assertIn("'profilesExposed'=>false", ENDPOINT)
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

    def test_audit_covers_all_four_layers(self):
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
        ):
            self.assertIn(marker, ENDPOINT)
        for platform_table in (
            "p50_social_links",
            "p50_metric_accounts",
            "p50_metric_jobs",
            "p50_metric_runs",
            "p50_metric_contents",
            "p50_metric_captures",
            "p50_news_items",
            "p50_content_trend_current",
        ):
            self.assertIn(platform_table, ENDPOINT)

    def test_displayed_top_replays_public_feed_rules(self):
        self.assertIn("DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)", ENDPOINT)
        self.assertIn("$perProfile[$profileId]", ENDPOINT)
        self.assertIn(">=2", ENDPOINT)
        self.assertIn("strcasecmp($platform,'Facebook')", ENDPOINT)
        self.assertIn("$titleLength<12&&$thumbnail===''", ENDPOINT)
        self.assertIn("if(count($selected)<5)", ENDPOINT)
        self.assertIn("latestCalculatedAt", ENDPOINT)

    def test_five_minute_schedule_and_fifteen_minute_bucket_are_explicit(self):
        self.assertIn("cron: '*/5 * * * *'", FRESHNESS)
        self.assertIn("'p0'=>['key'=>'p0','name'=>'priority','seconds'=>900", ORCHESTRATOR)
        self.assertIn("'scheduleMinutes'=>5", ENDPOINT)
        self.assertIn("'collectionIdempotencyBucketMinutes'=>15", ENDPOINT)
        self.assertIn("'scheduledCyclesPerCollectionBucket'=>3", ENDPOINT)
        self.assertIn("sameProfilePlatformCollectionUsuallyDeduplicatedWithinBucket", ENDPOINT)

    def test_workflow_only_reads_and_archives_diagnostics(self):
        self.assertIn("name: Content Freshness Platform Audit", WORKFLOW)
        self.assertIn("workflow_dispatch:", WORKFLOW)
        self.assertNotIn("schedule:", WORKFLOW)
        self.assertIn("contents: read", WORKFLOW)
        self.assertNotIn("actions: write", WORKFLOW)
        self.assertIn("action:\"probe\"", WORKFLOW)
        self.assertIn("action:\"audit\"", WORKFLOW)
        self.assertNotIn("action:\"refresh\"", WORKFLOW)
        self.assertNotIn("metrics-ranking-publication-apply", WORKFLOW)
        self.assertIn("Profils individuels exposés", WORKFLOW)
        self.assertIn("Écriture app_state : `0`", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
