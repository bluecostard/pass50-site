import copy
import pathlib
import re
import unittest
from datetime import datetime, timedelta, timezone
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse


ROOT = pathlib.Path(__file__).resolve().parents[1]
RADAR = (ROOT / "api/radar-core.php").read_text(encoding="utf-8")
CORE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
COLLECT = (ROOT / "api/data-collect.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
HTTP_TOOLS = (ROOT / "api/http-tools.php").read_text(encoding="utf-8")
MIGRATION = (ROOT / "migration-data-engine-v1.sql").read_text(encoding="utf-8")
METRICS_CORE = (ROOT / "api/metrics-core.php").read_text(encoding="utf-8")
LIVE_CHECK = (ROOT / "api/live-check-youtube.php").read_text(encoding="utf-8")
BOOTSTRAP = (ROOT / "api/bootstrap.php").read_text(encoding="utf-8")


def canonicalize(url):
    parsed = urlparse(url)
    host = parsed.netloc.lower()
    if host.startswith("www."):
        host = host[4:]
    query = [
        (key, value)
        for key, value in parse_qsl(parsed.query)
        if not key.startswith("utm_") and key not in {"fbclid", "gclid", "si", "feature"}
    ]
    return urlunparse((parsed.scheme.lower(), host, parsed.path.rstrip("/"), "", urlencode(sorted(query)), ""))


def youtube_reference(url):
    parsed = urlparse(canonicalize(url))
    path = parsed.path.strip("/")
    query = dict(parse_qsl(parsed.query))
    if parsed.netloc == "youtu.be" and path:
        return "video", path
    if query.get("v"):
        return "video", query["v"]
    match = re.match(r"(shorts|live|embed)/([^/]+)", path)
    if match:
        return ("short" if match.group(1) == "shorts" else "video"), match.group(2)
    match = re.match(r"channel/([^/]+)", path)
    if match:
        return "channel", match.group(1)
    match = re.match(r"@([^/]+)", path)
    if match:
        return "handle", match.group(1)
    return "unsupported", ""


def normalized_metrics(raw):
    keys = ("views", "likes", "comments", "shares", "saves", "followers")
    return {key: int(raw[key]) if raw.get(key) is not None else None for key in keys}


def metric_delta(previous, current):
    result = {}
    for key, value in normalized_metrics(current).items():
        if value is None:
            continue
        old = normalized_metrics(previous).get(key)
        result[key] = None if old is None else max(0, value - old)
    return result


def store_capture(history, item):
    key = (item["profileId"], item["platform"], item.get("contentId") or canonicalize(item["canonicalUrl"]))
    metrics = {key: value for key, value in normalized_metrics(item["metrics"]).items() if value is not None}
    previous = history.get(key)
    if previous == metrics:
        return False, {}, history
    updated = copy.deepcopy(history)
    updated[key] = metrics
    return True, metric_delta(previous or {}, metrics), updated


class NetworkBudget:
    def __init__(self, batch=20, profile=5):
        self.batch_limit = batch
        self.profile_limit = profile
        self.used = 0
        self.profile_used = 0
        self.cache = {}

    def next_profile(self):
        self.profile_used = 0

    def fetch(self, url, temporary_failure=False):
        if url in self.cache:
            return "cached"
        if self.used >= self.batch_limit or self.profile_used >= self.profile_limit:
            return "budget_exceeded"
        self.used += 1
        self.profile_used += 1
        if temporary_failure and self.used < self.batch_limit and self.profile_used < self.profile_limit:
            self.used += 1
            self.profile_used += 1
        self.cache[url] = "response"
        return "response"


class RadarBehaviorTests(unittest.TestCase):
    def test_youtube_channel_url_is_recognized(self):
        self.assertEqual(youtube_reference("https://www.youtube.com/channel/UC123"), ("channel", "UC123"))

    def test_youtube_video_url_is_recognized(self):
        self.assertEqual(youtube_reference("https://youtube.com/watch?v=abc123XYZ"), ("video", "abc123XYZ"))
        self.assertEqual(youtube_reference("https://youtu.be/abc123XYZ"), ("video", "abc123XYZ"))

    def test_youtube_short_url_is_recognized(self):
        self.assertEqual(youtube_reference("https://youtube.com/shorts/abc123XYZ"), ("short", "abc123XYZ"))

    def test_tracking_parameters_are_removed(self):
        self.assertEqual(
            canonicalize("https://www.example.com/post/?utm_source=x&fbclid=secret&id=7"),
            "https://example.com/post?id=7",
        )

    def test_content_id_deduplicates_different_urls(self):
        first = {"profileId": "a", "platform": "YouTube", "contentId": "v1", "canonicalUrl": "https://youtube.com/watch?v=v1", "metrics": {"views": 100}}
        second = dict(first, canonicalUrl="https://youtu.be/v1")
        recorded, _, history = store_capture({}, first)
        duplicate, _, _ = store_capture(history, second)
        self.assertTrue(recorded)
        self.assertFalse(duplicate)

    def test_existing_content_accepts_a_new_capture(self):
        item = {"profileId": "a", "platform": "YouTube", "contentId": "v1", "canonicalUrl": "https://youtube.com/watch?v=v1", "metrics": {"views": 100}}
        _, _, history = store_capture({}, item)
        recorded, _, _ = store_capture(history, dict(item, metrics={"views": 130}))
        self.assertTrue(recorded)

    def test_view_delta_uses_absolute_snapshots_once(self):
        self.assertEqual(metric_delta({"views": 100_000}, {"views": 130_000})["views"], 30_000)

    def test_missing_metric_is_not_converted_to_zero(self):
        metrics = normalized_metrics({"views": 42})
        self.assertEqual(metrics["views"], 42)
        self.assertIsNone(metrics["likes"])
        self.assertNotIn("likes", {key: value for key, value in metrics.items() if value is not None})

    def test_content_older_than_seven_days_is_inactive(self):
        now = datetime.now(timezone.utc)
        self.assertGreater((now - (now - timedelta(days=8))).total_seconds(), 7 * 86400)

    def test_recent_content_is_active(self):
        now = datetime.now(timezone.utc)
        self.assertLessEqual((now - (now - timedelta(days=6))).total_seconds(), 7 * 86400)

    def test_unavailable_platform_has_explicit_status(self):
        for status in ("public_metrics_unavailable", "rate_limited", "temporarily_unavailable"):
            self.assertIn(f"'{status}'", RADAR)

    def test_profile_error_is_isolated(self):
        self.assertIn("foreach($links as $link)", RADAR)
        self.assertIn("catch(Throwable $e)", RADAR)
        self.assertIn("'error'=>'Collecte publique impossible'", RADAR)

    def test_legacy_and_radar_share_one_useful_request(self):
        budget = NetworkBudget()
        self.assertEqual(budget.fetch("https://youtube.com/channel/example"), "response")
        self.assertEqual(budget.fetch("https://youtube.com/channel/example"), "cached")
        self.assertEqual(budget.used, 1)
        self.assertNotIn("p50_de_collect_youtube_activity($profile)", COLLECT)
        self.assertNotIn("p50_de_collect_social_activity($profile)", COLLECT)

    def test_network_budget_blocks_excess_requests(self):
        budget = NetworkBudget(batch=2, profile=2)
        self.assertEqual(budget.fetch("https://example.com/1"), "response")
        self.assertEqual(budget.fetch("https://example.com/2"), "response")
        self.assertEqual(budget.fetch("https://example.com/3"), "budget_exceeded")
        self.assertIn("p50_radar_begin_batch(20,5,count($profiles))", COLLECT)

    def test_timeout_is_isolated_and_retry_is_bounded(self):
        budget = NetworkBudget(batch=20, profile=5)
        self.assertEqual(budget.fetch("https://slow.example", temporary_failure=True), "response")
        self.assertEqual(budget.used, 2)
        budget.next_profile()
        self.assertEqual(budget.fetch("https://healthy.example"), "response")
        self.assertIn("'timeout'", RADAR)

    def test_detected_but_inaccessible_content_has_exact_status(self):
        self.assertIn("'detected_content_inaccessible'", RADAR)
        self.assertIn("'Contenu détecté mais inaccessible'", RADAR)
        for status in ("timeout", "rate_limited", "http_error", "content_removed_or_private"):
            self.assertIn(f"'{status}'", RADAR)

    def test_web_home_page_is_not_an_event(self):
        self.assertIn("$path!==''&&$path!=='index.php'", RADAR)
        self.assertIn("!$content['isPublication']", RADAR)

    def test_rss_without_date_is_rejected(self):
        self.assertIn("$ts===false", RADAR)
        self.assertIn("$title===''", RADAR)

    def test_generic_open_graph_is_rejected(self):
        self.assertIn("Article|NewsArticle|BlogPosting|VideoObject|SocialMediaPosting", RADAR)
        self.assertIn("$structured||in_array(strtolower($ogType)", RADAR)

    def test_capture_without_event_is_refused(self):
        self.assertIn("SELECT id FROM p50_activity_events", RADAR)
        self.assertIn("Événement Radar introuvable après écriture.", RADAR)
        self.assertIn("event_id BIGINT UNSIGNED NULL", RADAR)

    def test_identical_consecutive_capture_is_not_inserted(self):
        item = {"profileId": "a", "platform": "YouTube", "contentId": "same", "canonicalUrl": "https://youtube.com/watch?v=same", "metrics": {"views": 10}}
        _, _, history = store_capture({}, item)
        recorded, delta, _ = store_capture(history, item)
        self.assertFalse(recorded)
        self.assertEqual(delta, {})

    def test_delta_is_scoped_to_the_correct_event(self):
        first = {"profileId": "a", "platform": "YouTube", "contentId": "one", "canonicalUrl": "https://youtube.com/watch?v=one", "metrics": {"views": 100}}
        other = dict(first, contentId="two", canonicalUrl="https://youtube.com/watch?v=two", metrics={"views": 500})
        _, _, history = store_capture({}, first)
        _, _, history = store_capture(history, other)
        recorded, delta, _ = store_capture(history, dict(first, metrics={"views": 130}))
        self.assertTrue(recorded)
        self.assertEqual(delta["views"], 30)


class RadarPipelineContractTests(unittest.TestCase):
    def test_youtube_key_comes_only_from_api_config(self):
        self.assertLess(COLLECT.index("require __DIR__ . '/bootstrap.php';"), COLLECT.index("require __DIR__ . '/radar-core.php';"))
        self.assertIn("$config = require $configFile;", BOOTSTRAP)
        key_function = re.search(r"function p50_radar_youtube_key\(\): string \{.*?\n}", RADAR, re.S)
        self.assertIsNotNone(key_function)
        self.assertIn("$config['metrics']['PASS50_YOUTUBE_API_KEY']", key_function.group(0))
        self.assertNotIn("getenv(", key_function.group(0))
        self.assertIn("$config['metrics']['PASS50_YOUTUBE_API_KEY']", METRICS_CORE)
        self.assertIn("$config['metrics']['PASS50_YOUTUBE_API_KEY']", LIVE_CHECK)
        for source in (RADAR, METRICS_CORE, LIVE_CHECK):
            self.assertNotRegex(source, r"getenv\(['\"](?:PASS50_)?YOUTUBE_API_KEY")

    def test_youtube_api_has_persistent_cache_and_quota_guard(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS p50_youtube_api_cache", RADAR)
        self.assertIn("expires_at>UTC_TIMESTAMP()", RADAR)
        self.assertIn("'quotaLimit'=>20", RADAR)
        self.assertIn("apiRequests']>=", RADAR)
        self.assertIn("CREATE TABLE IF NOT EXISTS p50_youtube_api_cache", MIGRATION)

    def test_same_youtube_video_is_fetched_once_per_update(self):
        self.assertIn("['videos'][$videoId]", RADAR)
        self.assertIn("$GLOBALS['p50_youtube_run']['videos'][$videoId]=$result", RADAR)

    def test_youtube_api_fields_feed_radar(self):
        for field in ("viewCount", "likeCount", "commentCount", "publishedAt", "channelId", "channelTitle", "subscriberCount"):
            self.assertIn(field, RADAR)
        self.assertIn("$metrics['followers']=$subscribers", RADAR)
        self.assertIn("'youtubeApi'=>p50_radar_youtube_status()", COLLECT)
        self.assertIn("$metadata['videos']", RADAR)

    def test_missing_key_is_explicit_and_public_collection_remains(self):
        self.assertIn("'mode'=>!empty($run['configured'])?'youtube_data_api_v3':'public_only'", RADAR)
        self.assertIn("youtube_api_unconfigured", RADAR)
        self.assertIn("p50_radar_content_document($url,'YouTube')", RADAR)

    def test_api_key_is_removed_from_shared_cache_identity(self):
        self.assertIn("strcasecmp((string)$key,'key')===0", HTTP_TOOLS)

    def test_no_intermediate_app_state_publication(self):
        self.assertNotIn("p50_de_save_public_state(", RADAR)
        self.assertNotIn("p50_de_publish_profile(", RADAR)
        self.assertNotIn("p50_de_save_public_state(", COLLECT)

    def test_pipeline_keeps_one_final_publication(self):
        match = re.search(r"function p50_de_publish_score_pipeline\b.*?\n}\n", CORE, re.S)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(0).count("p50_de_save_public_state("), 1)

    def test_admin_counters_are_complete(self):
        counters = (
            "fiTraversed", "officialLinksAnalyzed", "recentPublications", "uniqueEvents",
            "capturesRecorded", "activeMetrics", "unavailablePlatforms", "recalculated",
            "scoresChanged", "ranksChanged", "published",
        )
        for counter in counters:
            self.assertIn(counter, UI)

    def test_radar_is_integrated_in_existing_update_pipeline(self):
        self.assertIn("require __DIR__ . '/radar-core.php';", COLLECT)
        self.assertIn("p50_radar_collect_profile($profile)", COLLECT)
        self.assertNotIn("data-admin-tab=\"radar\"", UI)

    def test_real_collectors_share_the_global_budget_and_url_cache(self):
        budget_start = COLLECT.index("p50_radar_begin_batch(20,5,count($profiles))")
        enrichment = COLLECT.index("p50_de_collect_enrichment($profile")
        radar = COLLECT.index("p50_radar_collect_profile($profile)")
        self.assertLess(budget_start, enrichment)
        self.assertLess(radar, enrichment)
        self.assertIn("p50_network_begin_profile()", COLLECT)
        self.assertIn("$cycle['cache'][$cacheKey]", HTTP_TOOLS)
        self.assertIn("$cacheKey=p50_network_cache_key($url)", HTTP_TOOLS)
        self.assertIn("p50_network_reserve_youtube($youtubeProfiles)", RADAR)
        self.assertIn("p50_network_release_youtube_profile()", COLLECT)
        self.assertIn("$remaining<=(int)($cycle['youtubeReservations']", HTTP_TOOLS)
        self.assertNotIn("curl_init(", CORE)
        self.assertNotIn("curl_init(", RADAR)
        self.assertNotIn("p50_de_collect_youtube_activity($profile)", COLLECT)
        self.assertNotIn("p50_de_collect_social_activity($profile)", COLLECT)

    def test_youtube_runtime_diagnostics_and_exact_statuses(self):
        for counter in ("profilesWithLink", "callsAttempted", "callsSucceeded", "videosRetrieved", "errors403", "errors429", "budgetExceeded", "invalidUrls", "noRecentProfiles"):
            self.assertIn(counter, RADAR)
        for status in (
            "youtube_api_collected", "youtube_api_unconfigured", "youtube_invalid_url",
            "youtube_no_recent_video", "youtube_quota_exceeded", "youtube_forbidden",
            "youtube_api_error", "youtube_budget_exceeded",
        ):
            self.assertIn(status, RADAR)
        self.assertIn("$httpStatus===403", RADAR)
        self.assertIn("$httpStatus===429", RADAR)

    def test_update_summary_displays_safe_youtube_diagnostics(self):
        self.assertIn("const youtube=radar.youtubeApi||{}", UI)
        for label in (
            "Clé configurée", "profil(s) avec lien", "appel(s) tenté(s)", "réussi(s)",
            "vidéo(s) récupérée(s)", "erreur(s) 403", "erreur(s) 429",
            "budget(s) dépassé(s)", "URL(s) invalide(s)", "profil(s) sans vidéo récente",
        ):
            self.assertIn(label, UI)
        for status in (
            "API configurée mais aucun appel tenté", "Appels tentés mais tous échoués",
            "Appels réussis mais aucune vidéo récente",
            "Vidéos récupérées mais aucune capture enregistrée",
            "Captures enregistrées avec succès",
        ):
            self.assertIn(status, UI)

    def test_update_summary_never_exposes_youtube_secrets_or_google_payloads(self):
        summary = re.search(r"function deYoutubeMajSummary\(.*?\n  }", UI, re.S)
        self.assertIsNotNone(summary)
        self.assertNotIn("PASS50_YOUTUBE_API_KEY", summary.group(0))
        self.assertNotRegex(summary.group(0), r"(?:key=|googleapis\.com|response_json|response\.body)")
        self.assertIn("'youtubeApi'=>p50_radar_youtube_status()", COLLECT)

    def test_youtube_is_prioritized_and_receives_an_api_attempt(self):
        self.assertIn("usort($links", RADAR)
        self.assertIn("(string)$a['platform']==='YouTube'?0:1", RADAR)
        self.assertLess(RADAR.index("$run['callsAttempted']++"), RADAR.index("$run['callsSucceeded']++"))
        self.assertLess(
            COLLECT.index("p50_de_collect_state_links($profile)"),
            COLLECT.index("p50_radar_collect_profile($profile)"),
        )

    def test_youtube_key_value_is_never_exposed(self):
        status_function = re.search(r"function p50_radar_youtube_status\(\): array \{.*?\n}", RADAR, re.S)
        self.assertIsNotNone(status_function)
        self.assertNotRegex(status_function.group(0), r"['\"](?:apiKey|key)['\"]\s*=>")
        self.assertNotRegex(RADAR, r"error_log\([^\n]*\$key")

    def test_existing_radar_table_is_migrated_and_captures_are_linked(self):
        self.assertIn("information_schema.COLUMNS", RADAR)
        self.assertIn("ADD COLUMN event_id BIGINT UNSIGNED NULL", RADAR)
        self.assertIn("UPDATE p50_radar_metric_captures c JOIN p50_activity_events e", RADAR)
        self.assertIn("WHERE c.event_id IS NULL", RADAR)
        self.assertIn("information_schema.STATISTICS", RADAR)
        self.assertIn("ADD COLUMN event_id BIGINT UNSIGNED NULL", MIGRATION)
        self.assertNotRegex(MIGRATION, r"\b(?:DROP|TRUNCATE|DELETE)\b")


if __name__ == "__main__":
    unittest.main(verbosity=2)
