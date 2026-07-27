from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
API = (ROOT / "api" / "live-status-v3.php").read_text(encoding="utf-8")
CLIENT = (ROOT / "live-radar-v3.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
SCHEDULE = (ROOT / ".github" / "workflows" / "live-radar-sweep.yml").read_text(encoding="utf-8")


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


if __name__ == "__main__":
    unittest.main()
