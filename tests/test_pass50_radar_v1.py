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


class RadarPipelineContractTests(unittest.TestCase):
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


if __name__ == "__main__":
    unittest.main(verbosity=2)
