import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/content-intelligence-core.php").read_text()
FEED = (ROOT / "api/content-feed.php").read_text()
CRON = (ROOT / "api/content-intelligence-cron.php").read_text()
VALIDATE = (ROOT / "api/news-validate.php").read_text()
DISCOVER = (ROOT / "api/news-discover.php").read_text()
CLIENT = (ROOT / "content-intelligence.js").read_text()
CONFIG = (ROOT / "app-config.js").read_text()
WORKFLOW = (ROOT / ".github/workflows/content-intelligence-15m.yml").read_text()
ALL = CORE + FEED + CRON + VALIDATE + DISCOVER + CLIENT + CONFIG + WORKFLOW


class ContentIntelligenceV1Tests(unittest.TestCase):
    def test_schema_separates_news_and_trends(self):
        self.assertIn("p50_news_items", CORE)
        self.assertIn("p50_content_trend_runs", CORE)
        self.assertIn("p50_content_trend_current", CORE)
        self.assertIn("uq_p50_news_key", CORE)
        self.assertIn("uq_p50_content_trend_period_content", CORE)

    def test_official_content_is_automatic_but_external_news_is_human_validated(self):
        self.assertIn("official_metric_content", CORE)
        self.assertIn("automaticPublication'=>true", CORE)
        self.assertIn("confirmed", VALIDATE)
        self.assertIn("Confirmation humaine obligatoire", VALIDATE)
        self.assertIn("originalLinkValidated", VALIDATE)
        self.assertIn("externalNewsHumanValidation'=>true", FEED)

    def test_five_periods_and_content_level_metrics(self):
        for token in ("'2h'=>7200", "'24h'=>86400", "'48h'=>172800", "'7d'=>604800", "'15d'=>1296000"):
            self.assertIn(token, CORE)
        for metric in ("view_delta", "interaction_delta", "share_delta", "velocity", "acceleration"):
            self.assertIn(metric, CORE)
        self.assertIn("quality_status='usable'", CORE)

    def test_top_five_limits_two_items_per_profile(self):
        self.assertIn("maxPerProfile'=>2", FEED)
        self.assertIn("if(($perProfile[$pid]??0)>=2)continue", FEED)
        self.assertIn("if(count($trends)>=5)break", FEED)

    def test_small_accounts_are_normalized(self):
        self.assertIn("follower_count", CORE)
        self.assertIn("pow(max(1000", CORE)
        self.assertIn("normalizedRate", CORE)

    def test_viral_requires_strong_propagation_signal(self):
        self.assertIn("cluster_platform_count", CORE)
        self.assertIn("$clusterCount>=3", CORE)
        self.assertIn("$shareRate>=8", CORE)
        self.assertIn("'VIRAL'", CORE)

    def test_cron_is_hmac_protected_and_read_only_for_public_ranking(self):
        self.assertIn("HTTP_X_P50_TIMESTAMP", CRON)
        self.assertIn("HTTP_X_P50_SIGNATURE", CRON)
        self.assertIn("p50_mo_verify_cron_signature", CRON)
        self.assertIn("publicStateWrites", CRON)
        for forbidden in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state", "data-publish.php"):
            self.assertNotIn(forbidden, ALL)

    def test_public_interface_has_filters_top_five_and_profile_news(self):
        for period in ("2h", "24h", "48h", "7d", "15d"):
            self.assertIn(period, CLIENT)
        self.assertIn("data-p50ci-period", CLIENT)
        self.assertIn("renderProfileNews", CLIENT)
        self.assertIn("p50ciProfileNews", CLIENT)
        self.assertIn("content-feed.php", CLIENT)
        self.assertIn("content-intelligence.js?v=1.0", CONFIG)

    def test_admin_discovery_uses_verified_handles(self):
        self.assertIn("p50_social_links", DISCOVER)
        self.assertIn("status='verified'", DISCOVER)
        self.assertIn("officialHandles", DISCOVER)
        self.assertIn("news-validate.php", CLIENT)
        self.assertIn("Valider dans la fiche", CLIENT)

    def test_schedule_runs_every_fifteen_minutes(self):
        self.assertIn("3,18,33,48 * * * *", WORKFLOW)
        self.assertIn("content-intelligence-cron.php", WORKFLOW)
        self.assertIn("pass50/content-intelligence", WORKFLOW)
        self.assertIn("CONTENT-INTELLIGENCE-V1.0", WORKFLOW)
        self.assertIn("actions/upload-artifact@v4", WORKFLOW)

    def test_no_secret_is_returned_or_logged(self):
        for forbidden in ("echo $CRON_SECRET", "echo $X_BEARER_TOKEN", "tokenValue", "Authorization: Bearer $CRON_SECRET"):
            self.assertNotIn(forbidden, ALL)
        self.assertNotRegex(FEED, re.compile(r"token|secret|password|cookie", re.I))


if __name__ == "__main__":
    unittest.main()
