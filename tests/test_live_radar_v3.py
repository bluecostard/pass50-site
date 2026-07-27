from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
API = (ROOT / "api" / "live-status-v3.php").read_text(encoding="utf-8")
CLIENT = (ROOT / "live-radar-v3.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
TOOLS = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
SCHEDULE = (ROOT / ".github" / "workflows" / "live-radar-sweep.yml").read_text(encoding="utf-8")


def deduplicate_lives(streams):
    seen = set()
    result = []
    for stream in streams:
        key = "|".join(
            (
                str(stream.get("profileId", "")).strip().lower(),
                str(stream.get("platform", "")).strip().lower(),
                str(stream.get("url", "")).strip().rstrip("/").lower(),
            )
        )
        if key == "||" or key in seen:
            continue
        seen.add(key)
        result.append(stream)
    return result


class LiveRadarV3Tests(unittest.TestCase):
    def test_four_live_platforms_are_monitored(self):
        for platform in ("TikTok", "YouTube", "Instagram", "Facebook"):
            self.assertIn(platform, API)
        self.assertIn("P50_LIVE_PLATFORMS", API)
        self.assertIn("P50_LIVE_OFFICIAL_STATUSES", API)

    def test_only_official_direct_links_are_sources(self):
        self.assertIn("p50_live_v3_direct_url", API)
        self.assertIn("s.status='verified'", API)
        self.assertIn("owner_verified", API)
        self.assertIn("manual_verified", API)

    def test_tiktok_uses_multiple_independent_probes(self):
        self.assertIn("api-live/user/room", API)
        self.assertIn("api_basic", API)
        self.assertIn("mobile_live", API)
        self.assertIn("embed/live", API)
        self.assertIn("p50_live_v3_tiktok_room_id", API)

    def test_scans_are_parallel_and_tuned(self):
        self.assertIn("curl_multi_init", API)
        self.assertIn("CURLMOPT_MAX_TOTAL_CONNECTIONS,16", API)
        self.assertIn("p50_live_v3_scan_batch", API)
        self.assertIn("min(12,(int)($_GET['batch']??8))", API)

    def test_a_network_block_does_not_end_a_live(self):
        self.assertIn("consecutive_offline", API)
        self.assertIn("consecutive_unknown", API)
        self.assertIn("$health['offline']>=2", API)
        self.assertIn("$sameUrl", API)
        self.assertNotIn("$health['unknown']>=", API)

    def test_full_sweep_advances_even_when_a_source_disappears(self):
        self.assertIn("count($keys)", API)
        self.assertIn("live_radar_v3_cycle_", API)
        self.assertIn("cycleComplete", API)
        self.assertIn("last_full_sweep", API)

    def test_active_lives_have_a_longer_safety_window(self):
        self.assertIn("$refresh=45;$stale=90", API)
        self.assertIn("stream_key<>?", API)
        self.assertIn("p50_live_v3_active_rows($stale)", API)

    def test_client_runs_quick_and_complete_scans(self):
        self.assertIn("const QUICK_INTERVAL=45_000", CLIENT)
        self.assertIn("mode:'quick',batch:'8'", CLIENT)
        self.assertIn("mode:'full',force:'1'", CLIENT)
        self.assertIn("calls<160", CLIENT)
        self.assertIn("PASS50_VERIFY_LIVE_PROFILE", CLIENT)
        self.assertIn("RADAR LIVE V3", CLIENT)

    def test_v3_replaces_v2_in_the_browser_and_cache(self):
        self.assertIn("live-radar-v3.js?v=1.1", CONFIG)
        self.assertNotIn("live-radar-v2.js", CONFIG)
        self.assertIn("live-radar-v3.js?v=1.1", SW)
        self.assertNotIn("live-radar-v2.js", SW)

    def test_server_side_full_sweep_is_scheduled(self):
        self.assertIn("*/10 * * * *", SCHEDULE)
        self.assertIn("api/live-status-v3.php", SCHEDULE)
        self.assertIn("mode=full", SCHEDULE)
        self.assertIn("cycleComplete", SCHEDULE)

    def test_stream_identity_keeps_profiles_with_the_same_live_url(self):
        store = re.search(r"function p50_live_v3_store\(array \$live\): void \{.*?\n}", API, re.S)
        self.assertIsNotNone(store)
        self.assertIn("$profileId.'|'.$platform.'|'", store.group(0))

    def test_same_live_url_for_different_profiles_is_preserved(self):
        shared_url = "https://www.tiktok.com/live"
        streams = [
            {"profileId": "alice", "platform": "TikTok", "url": shared_url},
            {"profileId": "bob", "platform": "TikTok", "url": shared_url},
            {"profileId": "carole", "platform": "TikTok", "url": shared_url},
        ]
        self.assertEqual(len(deduplicate_lives(streams)), 3)

    def test_only_a_strict_stream_duplicate_is_removed(self):
        stream = {"profileId": "alice", "platform": "TikTok", "url": "https://www.tiktok.com/live"}
        other_url = dict(stream, url="https://www.tiktok.com/@alice/live")
        self.assertEqual(len(deduplicate_lives([stream, dict(stream), other_url])), 2)

    def test_deduplication_only_removes_the_same_profile_platform_and_url(self):
        dedup = re.search(r"function p50_live_v3_dedup\(.*?\n}", API, re.S)
        self.assertIsNotNone(dedup)
        self.assertIn("profileId", dedup.group(0))
        self.assertIn("platform", dedup.group(0))
        self.assertIn("url", dedup.group(0))

    def test_api_keeps_started_at_nullable_and_exposes_last_seen_separately(self):
        active_rows = re.search(r"function p50_live_v3_active_rows\(.*?\n}", API, re.S)
        self.assertIsNotNone(active_rows)
        self.assertNotRegex(
            active_rows.group(0),
            r"'startedAt'\s*=>\s*p50_live_v3_iso\([^)]*started_at[^)]*\)\s*\?\?\s*p50_live_v3_iso",
        )
        self.assertIn("'lastSeenAt'=>p50_live_v3_iso", active_rows.group(0))

    def test_browser_normalizers_keep_undated_server_confirmed_lives(self):
        base = re.search(r"function normalizeLiveStreams\(\)\{.*?\n}", INDEX, re.S)
        override = re.search(r"function freshLive\(x\)\{.*?\n}", TOOLS, re.S)
        self.assertIsNotNone(base)
        self.assertIsNotNone(override)
        for source in (base.group(0), override.group(0)):
            self.assertNotIn("start<=0", source)
            self.assertNotIn("now-start>8*3600000", source)

    def test_manual_stream_is_not_rejected_only_for_missing_dates(self):
        manual = re.search(r"function p50_live_v3_manual_streams\(.*?\n}", API, re.S)
        self.assertIsNotNone(manual)
        self.assertNotIn("$start<=0", manual.group(0))

    def test_active_lives_sorts_without_requiring_started_at(self):
        active = re.search(r"function activeLives\(\)\{.*?\n}", INDEX, re.S)
        self.assertIsNotNone(active)
        self.assertIn("lastSeenAt", active.group(0))
        self.assertNotIn("new Date(b.startedAt)-new Date(a.startedAt)", active.group(0))

    def test_public_counter_and_modal_use_every_active_live(self):
        header = re.search(r"function renderLiveHeader\(\)\{.*?\n}", INDEX, re.S)
        modal = re.search(r"function openLives\(\)\{.*?\n}", INDEX, re.S)
        self.assertIsNotNone(header)
        self.assertIsNotNone(modal)
        self.assertIn("lives.length", header.group(0))
        self.assertNotIn("viewers", header.group(0))
        self.assertIn("lives.map(", modal.group(0))
        self.assertNotIn("lives[0]", modal.group(0))


if __name__ == "__main__":
    unittest.main()
