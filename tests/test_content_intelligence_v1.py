import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/content-intelligence-core.php").read_text()
FEED = (ROOT / "api/content-feed.php").read_text()
CRON = (ROOT / "api/content-intelligence-cron.php").read_text()
FRESH = (ROOT / "api/content-freshness-cron-v4.php").read_text() + (ROOT / "api/content-freshness-core.php").read_text()
FACEBOOK = (ROOT / "api/metrics-collector-facebook.php").read_text()
VALIDATE = (ROOT / "api/news-validate.php").read_text()
DISCOVER = (ROOT / "api/news-discover.php").read_text()
CLIENT = (ROOT / "content-intelligence.js").read_text()
PLAYER = (ROOT / "facebook-video-player-v1.js").read_text()
PUBLIC_COPY = (ROOT / "public-copy-fixes.js").read_text()
CONFIG = (ROOT / "app-config.js").read_text()
SW = (ROOT / "sw.js").read_text()
WORKFLOW = (ROOT / ".github/workflows/content-intelligence-15m.yml").read_text()
FAST_WORKFLOW = (ROOT / ".github/workflows/content-freshness-5m.yml").read_text()
DEPLOY = (ROOT / ".github/workflows/deploy-ionos.yml").read_text()
ALL = CORE + FEED + CRON + FRESH + FACEBOOK + VALIDATE + DISCOVER + CLIENT + PLAYER + PUBLIC_COPY + CONFIG + SW + WORKFLOW + FAST_WORKFLOW + DEPLOY


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

    def test_public_content_is_strictly_fresh(self):
        self.assertIn("'24h'=>72", FEED)
        self.assertIn("'48h'=>120", FEED)
        self.assertIn("'7d'=>240", FEED)
        self.assertIn("'15d'=>384", FEED)
        self.assertIn("INTERVAL 48 HOUR", FEED)
        self.assertIn("INTERVAL 7 DAY", FEED)
        self.assertIn("officialNewsMaxAgeHours'=>48", FEED)
        self.assertIn("maxTrendRunAgeMinutes'=>30", FEED)
        self.assertIn("trendAgeMinutes>30", FEED)

    def test_fast_cycle_prioritizes_collectable_stale_ranked_profiles(self):
        self.assertIn("CONTENT-FRESHNESS-V4.0", FRESH)
        self.assertIn("p50_metric_ranking_current", FRESH)
        self.assertIn("SELECT ordered.profile_id", FRESH)
        self.assertIn("CASE WHEN ordered.latest_content IS NULL THEN 0 ELSE 1 END", FRESH)
        self.assertNotIn("ORDER BY latest_content IS NULL", FRESH)
        self.assertIn("p50_cf4_authorized_rows", FRESH)
        self.assertIn("p50_cf4_prioritize_tiktok_oauth", FRESH)
        self.assertIn("p50tm_authorized_profile_ids", FRESH)
        self.assertIn("p50_mc_platform_enabled", FRESH)
        self.assertIn("p50_mc_public_access", FRESH)
        self.assertIn("p50_cf4_prioritize_top_ranked", FRESH)
        self.assertIn("p50_cf4_top_profiles_all_periods", FRESH)
        self.assertIn("p50_cf4_ranking_period_keys", FRESH)
        self.assertIn("p50_cf4_execute", FRESH)
        self.assertIn("content_freshness_v4", FRESH)
        self.assertIn("'priority'=>5", FRESH)
        self.assertIn("p50_metrics_process_next_job", FRESH)
        self.assertIn("p50_ci_refresh", FRESH)
        self.assertIn("'publicStateWrites'=>0", FRESH)

    def test_fast_cycle_runs_every_five_minutes(self):
        self.assertIn("*/5 * * * *", FAST_WORKFLOW)
        self.assertIn("content-freshness-cron-v4.php", FAST_WORKFLOW)
        self.assertIn("CONTENT-FRESHNESS-V4.0", FAST_WORKFLOW)
        self.assertIn("FACEBOOK-COLLECTOR-V2.0", FAST_WORKFLOW)
        self.assertIn("bucketSeconds==300", FAST_WORKFLOW)
        self.assertIn("pass50/content-freshness", FAST_WORKFLOW)
        self.assertIn("actions/upload-artifact@v4", FAST_WORKFLOW)

    def test_facebook_posts_have_readable_pass50_preview_and_optional_insights(self):
        self.assertIn("attachments{media_type,type,title,description,url,target,media}", FACEBOOK)
        self.assertIn("thumbnailUrl", FACEBOOK)
        self.assertIn("facebookPreviewAvailable", FACEBOOK)
        self.assertIn("facebookInsightsAvailable", FACEBOOK)
        self.assertIn("facebookInsightsHttpStatus", FACEBOOK)
        self.assertIn("Page posts+optional insights", FACEBOOK)
        self.assertNotIn("Facebook post insights unavailable", FACEBOOK)
        self.assertIn("facebookPreviewInPass50'=>true", FEED)
        self.assertIn("readableInPass50", FEED)
        self.assertIn("Aperçu lisible dans Pass50", CLIENT)
        self.assertIn("Ouvrir Facebook", CLIENT)
        self.assertIn("$titleLength<12&&$thumbnail===''", FEED)

    def test_all_public_facebook_items_get_a_pass50_viewer(self):
        self.assertIn("p50_content_feed_facebook_playable", FEED)
        self.assertIn("p50_content_feed_facebook_embed_type", FEED)
        self.assertIn("p50_content_feed_facebook_explicit_video_url", FEED)
        self.assertIn("['video','reel','live']", FEED)
        self.assertIn("'playableInPass50'", FEED)
        self.assertIn("'facebookEmbedType'", FEED)
        self.assertIn("facebookVideoPlaybackInPass50'=>true", FEED)
        self.assertIn("facebookEmbedRouting'=>true", FEED)
        self.assertIn("PASS50-FACEBOOK-VIDEO-PLAYER-V1.2", PLAYER)
        self.assertIn("fetchProfileItems", PLAYER)
        self.assertIn("state.items", PLAYER)
        self.assertIn("String(item.platform||'').toLowerCase()!=='facebook'", PLAYER)
        self.assertNotIn("item.playableInPass50!==true", PLAYER)
        self.assertIn("itemIsVideo", PLAYER)
        self.assertIn("facebookEmbedTypeFromUrl", PLAYER)
        self.assertIn("plugins/${plugin}.php", PLAYER)
        self.assertIn("data-p50fb-alternate", PLAYER)
        self.assertIn("Essayer comme publication", PLAYER)
        self.assertIn("Voir dans Pass50", PLAYER)
        self.assertIn("▶ Lire la vidéo", PLAYER)
        self.assertIn("Publication Facebook consultable dans Pass50.", PLAYER)
        self.assertIn("allowfullscreen", PLAYER)
        self.assertIn("host.endsWith('.facebook.com')", PLAYER)
        self.assertIn("Ouvrir Facebook", PLAYER)
        self.assertIn("facebook-video-player-v1.js?v=1.2", PUBLIC_COPY)
        self.assertIn('.deploy/facebook-video-player-v1.js', DEPLOY)
        self.assertIn('.deploy/api/content-feed.php', DEPLOY)
        self.assertIn("PASS50-FACEBOOK-VIDEO-PLAYER-V1.2", DEPLOY)
        self.assertIn("profileId=apoutchou", DEPLOY)
        self.assertIn("Voir dans Pass50", DEPLOY)
        self.assertIn("pass50/apoutchou-facebook-viewer", DEPLOY)

    def test_small_accounts_are_normalized(self):
        self.assertIn("follower_count", CORE)
        self.assertIn("pow(max(1000", CORE)
        self.assertIn("normalizedRate", CORE)

    def test_viral_requires_strong_propagation_signal(self):
        self.assertIn("cluster_platform_count", CORE)
        self.assertIn("$clusterCount>=3", CORE)
        self.assertIn("$shareRate>=8", CORE)
        self.assertIn("'VIRAL'", CORE)

    def test_crons_are_hmac_protected_and_read_only_for_public_ranking(self):
        for text in (CRON, FRESH):
            self.assertIn("HTTP_X_P50_TIMESTAMP", text)
            self.assertIn("HTTP_X_P50_SIGNATURE", text)
            self.assertIn("p50_mo_verify_cron_signature", text)
            self.assertIn("publicStateWrites", text)
        for forbidden in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state", "data-publish.php"):
            self.assertNotIn(forbidden, ALL)

    def test_public_interface_refreshes_without_session_staleness(self):
        for period in ("2h", "24h", "48h", "7d", "15d"):
            self.assertIn(period, CLIENT)
        self.assertIn("NEWS_TTL=30*1000", CLIENT)
        self.assertIn("setInterval(()=>refreshTrends(true),30*1000)", CLIENT)
        self.assertIn("content-freshness-admin-refresh.php", CLIENT)
        self.assertIn("p50RefreshAllNews", CLIENT)
        self.assertIn("news-refresh-progress", CLIENT)
        self.assertIn("startRefreshProgress", CLIENT)
        self.assertIn("visibilitychange", CLIENT)
        self.assertIn("cache:'no-store'", CLIENT)
        self.assertIn("content-intelligence.js?v=1.4", CONFIG)
        self.assertIn("content-intelligence.js?v=1.4", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")

    def test_admin_discovery_uses_verified_handles(self):
        self.assertIn("p50_social_links", DISCOVER)
        self.assertIn("status='verified'", DISCOVER)
        self.assertIn("officialHandles", DISCOVER)
        self.assertIn("news-validate.php", CLIENT)
        self.assertIn("Valider dans la fiche", CLIENT)

    def test_fifteen_minute_workflow_remains_as_fallback(self):
        self.assertIn("3,18,33,48 * * * *", WORKFLOW)
        self.assertIn("content-intelligence-cron.php", WORKFLOW)
        self.assertIn("pass50/content-intelligence", WORKFLOW)
        self.assertIn("CONTENT-INTELLIGENCE-V1.0", WORKFLOW)

    def test_no_secret_is_returned_or_logged(self):
        for forbidden in ("echo $CRON_SECRET", "echo $X_BEARER_TOKEN", "tokenValue", "Authorization: Bearer $CRON_SECRET"):
            self.assertNotIn(forbidden, ALL)
        self.assertNotRegex(FEED, re.compile(r"token|secret|password|cookie", re.I))


if __name__ == "__main__":
    unittest.main()
