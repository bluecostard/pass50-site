import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api/content-freshness-cron-v4.php").read_text(encoding="utf-8")
CORE = (ROOT / "api/content-freshness-core.php").read_text(encoding="utf-8")
ADMIN = (ROOT / "api/content-freshness-admin-refresh.php").read_text(encoding="utf-8")
FACEBOOK = (ROOT / "api/metrics-collector-facebook.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github/workflows/content-freshness-5m.yml").read_text(encoding="utf-8")
INTELLIGENCE = (ROOT / "api/content-intelligence-core.php").read_text(encoding="utf-8")
FEED = (ROOT / "api/content-feed.php").read_text(encoding="utf-8")


class ContentFreshnessRuntimeV4Tests(unittest.TestCase):
    def test_v4_is_strict_signed_and_five_minutes(self):
        self.assertIn("CONTENT-FRESHNESS-V4.0", CORE)
        self.assertIn("P50_CONTENT_FRESHNESS_V4_BUCKET_SECONDS=300", CORE)
        self.assertIn("['probe','refresh']", ENDPOINT)
        self.assertIn("p50_mo_verify_cron_signature", ENDPOINT)
        self.assertIn("cron: '*/5 * * * *'", WORKFLOW)
        self.assertIn("bucketSeconds==300", WORKFLOW)
        self.assertIn("Bucket : `5 minutes`", WORKFLOW)

    def test_facebook_v2_is_invalidated_and_asserted_before_collection(self):
        self.assertIn("FACEBOOK-COLLECTOR-V2.0", FACEBOOK)
        self.assertIn("facebookCollectorVersion", FACEBOOK)
        self.assertNotIn("Facebook post insights unavailable", FACEBOOK)
        self.assertIn("opcache_invalidate", ENDPOINT)
        self.assertIn("clearstatcache", ENDPOINT)
        self.assertIn("P50_CONTENT_FRESHNESS_V4_FACEBOOK_COLLECTOR='FACEBOOK-COLLECTOR-V2.0'", CORE)
        self.assertIn("Collecteur Facebook V2 non chargé", ENDPOINT)
        self.assertLess(ENDPOINT.index("opcache_invalidate"), ENDPOINT.index("require __DIR__.'/bootstrap.php'"))
        self.assertIn('.facebookCollectorVersion=="FACEBOOK-COLLECTOR-V2.0"', WORKFLOW)

    def test_top3_is_prioritized_and_cycle_capacity_increased(self):
        self.assertIn("p50_cf4_prioritize_top_ranked", CORE)
        self.assertIn("p50_cf4_top_profiles_all_periods", CORE)
        self.assertIn("p50_cf4_ranking_period_keys", CORE)
        self.assertIn("p50_cf4_merge_ranked_lists", CORE)
        self.assertIn("P50_CONTENT_FRESHNESS_V4_PROFILE_LIMIT=16", CORE)
        self.assertIn("P50_CONTENT_FRESHNESS_V4_JOB_LIMIT=28", CORE)
        self.assertIn("topRankedPrioritized", CORE)
        self.assertIn("topRankedByPeriod", CORE)
        self.assertIn("'2H','24H','48H','7J','15J'", CORE)
        self.assertIn("period_key='24H'", CORE)
        self.assertIn("p50_cf4_execute", ENDPOINT)

    def test_admin_can_refresh_all_profiles(self):
        self.assertIn("content-freshness-admin-refresh.php", str(ROOT / "api/content-freshness-admin-refresh.php"))
        self.assertIn("require_role($user,'owner','admin')", ADMIN)
        self.assertIn("mode==='all'", CORE)
        self.assertIn("collect_all", ADMIN)
        self.assertIn("collect_work", ADMIN)
        self.assertIn("timeBudgetMs", ADMIN)
        self.assertIn("timeBudgetMs", CORE)
        self.assertIn("'mode'=>'work'", ADMIN)
        self.assertIn("contentLimit'=>6", CORE)

    def test_official_news_window_is_forty_eight_hours(self):
        self.assertIn("INTERVAL 48 HOUR", FEED)
        self.assertIn("'officialNewsMaxAgeHours'=>48", FEED)

    def test_x_payment_required_is_paused_before_enqueue(self):
        self.assertIn("P50_CONTENT_FRESHNESS_V4_X_PAUSE_REASON='payment_required'", CORE)
        self.assertIn("PASS50_X_FAST_CYCLE_ENABLED", CORE)
        self.assertIn("if($platform==='X'&&empty($xPolicy['enabled']))", CORE)
        self.assertIn("paymentRequiredPaused", CORE)
        self.assertIn("confirmedHttpStatus", CORE)
        self.assertIn(".xFastCycle.enabled==false", WORKFLOW)
        self.assertIn(".xFastCycle.reason==\"payment_required\"", WORKFLOW)

    def test_tiktok_oauth_is_prioritized_without_platform_quota(self):
        self.assertIn("P50_CONTENT_FRESHNESS_V4_TIKTOK_OAUTH_LIMIT=4", CORE)
        self.assertIn("p50tm_authorized_profile_ids($pdo)", CORE)
        self.assertIn("p50_cf4_prioritize_tiktok_oauth", CORE)
        self.assertIn("tiktokOauthProfilesPrioritized", CORE)
        for forbidden in (
            "reserveTikTok",
            "minimumTikTokTrend",
            "tiktokTopFiveQuota",
            "platformQuota",
            "forcedTikTok",
        ):
            self.assertNotIn(forbidden, CORE + INTELLIGENCE)

    def test_trends_remain_metric_driven_and_public_state_is_untouched(self):
        self.assertIn("p50_ci_refresh($pdo)", CORE)
        self.assertIn("if($views===0&&$interactions===0)continue", INTELLIGENCE)
        self.assertIn("usort($prepared", INTELLIGENCE)
        self.assertNotIn("UPDATE app_state", CORE)
        self.assertNotIn("INSERT INTO app_state", CORE)
        self.assertNotIn("DELETE FROM app_state", CORE)
        self.assertIn("'publicStateWrites'=>0", CORE)

    def test_workflow_uses_only_the_v4_runtime(self):
        self.assertIn("content-freshness-cron-v4.php", WORKFLOW)
        self.assertIn("CONTENT-FRESHNESS-V4.0", WORKFLOW)
        self.assertNotIn("content-freshness-cron-v3.php", WORKFLOW)
        self.assertIn("Fraîcheur V4", WORKFLOW)
        self.assertIn("PARTIAL", WORKFLOW)
        self.assertIn("app_state : `0 écriture`", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
