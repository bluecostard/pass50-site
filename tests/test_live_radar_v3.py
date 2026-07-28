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
        self.assertIn("$sameUrl", API)
        self.assertIn("p50_live_v3_mark_unconfirmed", API)
        self.assertIn("in_array($platform,['TikTok','Instagram','Facebook'],true)", API)
        self.assertNotIn("elseif($stateValue==='unknown')p50_live_v3_mark_ended", API)

    def test_full_sweep_advances_even_when_a_source_disappears(self):
        self.assertIn("count($keys)", API)
        self.assertIn("live_radar_v3_cycle_", API)
        self.assertIn("cycleComplete", API)
        self.assertIn("last_full_sweep", API)

    def test_active_lives_have_strict_safety_windows(self):
        active = re.search(r"function p50_live_v3_active_rows\(.*?\n}", API, re.S).group(0)
        self.assertIn("INTERVAL 3 MINUTE", active)
        self.assertIn("INTERVAL 10 MINUTE", active)
        self.assertIn("INTERVAL 24 HOUR", active)
        self.assertIn("h.last_state='live'", active)
        self.assertIn("status='unconfirmed'", active)

    def test_client_runs_quick_and_complete_scans(self):
        self.assertIn("const QUICK_INTERVAL=45_000", CLIENT)
        self.assertIn("mode:'quick',batch:'8'", CLIENT)
        self.assertIn("mode:'full',force:'1'", CLIENT)
        self.assertIn("calls<160", CLIENT)
        self.assertIn("PASS50_VERIFY_LIVE_PROFILE", CLIENT)
        self.assertIn("RADAR LIVE V3", CLIENT)

    def test_v3_replaces_v2_in_the_browser_and_cache(self):
        self.assertIn("live-radar-v3.js?v=1.2", CONFIG)
        self.assertIn("pass50LiveRadar = '3.2'", CONFIG)
        self.assertNotIn("live-radar-v2.js", CONFIG)
        self.assertIn("live-radar-v3.js?v=1.2", SW)
        self.assertIn("pass50-v43-live-strict-active", SW)
        self.assertNotIn("live-radar-v3.js?v=1.1", CONFIG + SW)
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

    def test_browser_normalizers_require_recent_confirmation(self):
        base = re.search(r"function normalizeLiveStreams\(\)\{.*?\n}", INDEX, re.S)
        override = re.search(r"function freshLive\(x\)\{.*?\n}", TOOLS, re.S)
        self.assertIsNotNone(base)
        self.assertIsNotNone(override)
        for source in (base.group(0), override.group(0)):
            self.assertIn("lastConfirmedAt||x.lastSeenAt", source)
            self.assertIn("10*60_000:3*60_000", source)
            self.assertNotIn("detectedAt", source)
            self.assertNotIn("createdAt", source)

    def test_manual_stream_requires_future_ends_at(self):
        manual = re.search(r"function p50_live_v3_manual_streams\(.*?\n}", API, re.S)
        self.assertIsNotNone(manual)
        self.assertIn("($live['source']??'')!=='manual'", manual.group(0))
        self.assertIn("$end===false||$end<=$now", manual.group(0))
        self.assertIn("p50_public_http_url", manual.group(0))

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

    def test_tiktok_requires_active_json_and_room_id(self):
        parser = re.search(r"function p50_live_v3_parse_tiktok\(.*?\n}", API, re.S).group(0)
        self.assertIn("foreach(['api','api_basic']", parser)
        self.assertIn("json_decode($body,true)===null", parser)
        self.assertIn("$active", parser)
        self.assertIn("$candidate===''", parser)
        self.assertIn("tiktok_no_explicit_api_live_signal", parser)
        self.assertNotIn('"LiveRoom"\\s*:', parser)

    def test_tiktok_negative_evidence_wins_before_positive(self):
        parser = re.search(r"function p50_live_v3_parse_tiktok\(.*?\n}", API, re.S).group(0)
        negative = parser.index("tiktok_explicit_offline")
        positive = parser.index("foreach(['api','api_basic']")
        self.assertLess(negative, positive)
        for evidence in ("live has ended", "not currently live", "room not found", "is_live"):
            self.assertIn(evidence, parser)

    def test_instagram_and_facebook_require_explicit_active_state(self):
        instagram = re.search(r"function p50_live_v3_parse_instagram\(.*?\n}", API, re.S).group(0)
        facebook = re.search(r"function p50_live_v3_parse_facebook\(.*?\n}", API, re.S).group(0)
        self.assertIn("is_live_broadcast", instagram)
        self.assertIn("broadcast_status", instagram)
        self.assertIn("instagram_explicit_offline", instagram)
        self.assertIn("$live=$active&&$specific", facebook)
        self.assertIn("facebook_explicit_offline", facebook)
        self.assertNotIn("watch/live|", facebook)

    def test_unknown_is_hidden_and_server_returns_confirmation(self):
        active = re.search(r"function p50_live_v3_active_rows\(.*?\n}", API, re.S).group(0)
        self.assertIn("h.last_state='live'", active)
        self.assertIn("'lastConfirmedAt'=>p50_live_v3_iso", active)
        self.assertNotIn("last_state='unknown'", active)

    def test_cloud_never_persists_automatic_lives(self):
        safe = re.search(r"function cloudSafeState\(\)\{.*?\n}", INDEX, re.S).group(0)
        cloud = re.search(r"async function loadCloudState\(\)\{.*?\n}", INDEX, re.S).group(0)
        for source in (safe, cloud):
            self.assertIn("source!=='manual'", source)
            self.assertIn("startsWith('auto_')", source)
            self.assertIn("endsAt>Date.now()", source)
        self.assertIn("normalizeLiveStreams();localStorage.setItem", cloud)

    def test_public_copy_uses_confirmation_only(self):
        modal = re.search(r"function openLives\(\)\{.*?\n}", INDEX, re.S).group(0)
        active = re.search(r"function activeLives\(\)\{.*?\n}", INDEX, re.S).group(0)
        self.assertIn("Confirmé à l’instant", modal)
        self.assertIn("Confirmé il y a", modal)
        self.assertIn("data-live-profile", modal)
        self.assertIn("data-live-platform", modal)
        self.assertIn("data-live-confirmed-at", modal)
        self.assertIn("lastConfirmedAt||l.lastSeenAt", modal)
        for stale_field in ("startedAt", "detectedAt", "createdAt", "updatedAt"):
            self.assertNotIn(stale_field, active)

    def test_watch_link_is_revalidated_before_social_navigation(self):
        self.assertIn("verifyWatchLink", CLIENT)
        self.assertIn("mode:'profile',force:'1'", CLIENT)
        self.assertIn("Ce direct vient de se terminer ou ne peut plus être confirmé.", CLIENT)
        self.assertIn("dataset.liveConfirmedAt", CLIENT)
        self.assertIn("Date.now()-freshAt<=maxAge", CLIENT)

    def test_deterministic_false_live_scenarios(self):
        def tiktok(body, api_json=False):
            lowered = body.lower()
            if any(value in lowered for value in ("live has ended", "not currently live", "room not found", '"livestatus":4')):
                return "offline"
            has_room = "roomid" in lowered or "room_id" in lowered
            return "live" if api_json and '"status":2' in lowered and has_room else "unknown"

        def facebook(body):
            lowered = body.lower()
            if any(value in lowered for value in ('"is_live":false', '"broadcast_status":"vod"', "replay")):
                return "offline"
            active = '"is_live":true' in lowered or '"broadcast_status":"live"' in lowered
            specific = "/videos/" in lowered or '"video_id":' in lowered
            return "live" if active and specific else "unknown"

        def instagram(body):
            lowered = body.lower()
            if any(value in lowered for value in ('"is_live":false', '"broadcast_status":"ended"', "replay")):
                return "offline"
            return "live" if '"is_live":true' in lowered or '"broadcast_status":"active"' in lowered else "unknown"

        def fresh(platform, age_seconds):
            return age_seconds <= (10 * 60 if platform == "YouTube" else 3 * 60)

        self.assertEqual(tiktok('{"roomId":"123456789","liveStatus":4} live has ended', True), "offline")
        self.assertEqual(tiktok('{"roomId":"123456789","LiveRoom":{}}'), "unknown")
        self.assertEqual(tiktok('{"status":2,"room_id":"123456789"}', True), "live")
        self.assertEqual(facebook("https://facebook.com/pass50/videos/123456789"), "unknown")
        self.assertEqual(instagram("<html>profil accessible</html>"), "unknown")
        self.assertFalse(fresh("TikTok", 11 * 7 * 24 * 3600))
        self.assertFalse(fresh("Instagram", 27 * 60))
        self.assertTrue(fresh("YouTube", 9 * 60))

    def test_live_admin_rejects_epoch_and_uses_confirmation(self):
        date_helper = re.search(r"function p50LiveDateMs\(.*?\n}", INDEX, re.S).group(0)
        confirmed = re.search(r"function p50LiveConfirmedLabel\(.*?\n}", INDEX, re.S).group(0)
        admin = re.search(r"function renderLiveAdmin\(.*?\n}", INDEX, re.S).group(0)
        self.assertIn("Date.UTC(2020,0,1)", date_helper)
        self.assertIn("if(!value)return null", date_helper)
        self.assertIn("lastConfirmedAt||live?.lastSeenAt", confirmed)
        self.assertIn("p50LiveAdminTiming(l)", admin)
        self.assertNotIn("new Date(l.startedAt)", admin)
        self.assertNotIn("01/01/1970", INDEX)

    def test_live_admin_timing_is_strict_for_automatic_and_manual(self):
        timing = re.search(r"function p50LiveAdminTiming\(.*?\n}", INDEX, re.S).group(0)
        self.assertIn("Dernière confirmation", timing)
        self.assertIn("p50LiveConfirmedLabel(live)", timing)
        self.assertIn("startedAt===null?'':", timing)
        self.assertIn("Horaire non renseigné", timing)
        self.assertIn("if(startedAt!==null)", timing)
        self.assertIn("if(endsAt!==null)", timing)

    def test_date_policy_rejects_null_and_1970(self):
        minimum = 1577836800000  # 2020-01-01 UTC

        def valid(milliseconds):
            return milliseconds if milliseconds is not None and milliseconds >= minimum else None

        self.assertIsNone(valid(None))
        self.assertIsNone(valid(0))
        self.assertIsNone(valid(1000))
        self.assertEqual(valid(minimum), minimum)

    def test_ended_closes_live_and_unconfirmed_without_losing_history(self):
        ended = re.search(r"function p50_live_v3_mark_ended\(.*?\n}", API, re.S).group(0)
        store = re.search(r"function p50_live_v3_store\(.*?\n}", API, re.S).group(0)
        active = re.search(r"function p50_live_v3_active_rows\(.*?\n}", API, re.S).group(0)
        for source in (ended, store):
            self.assertIn("status IN ('live','unconfirmed')", source)
        self.assertIn("ended_at=COALESCE(ended_at,NOW())", ended)
        self.assertIn("stream_key<>?", store)
        self.assertIn("status IN ('live','unconfirmed')", active)
        self.assertIn("INTERVAL 24 HOUR", active)

    def test_health_categories_are_mutually_exclusive(self):
        summary = re.search(r"function p50_live_v3_health_summary\(.*?\n}", API, re.S).group(0)
        self.assertIn("public_state", summary)
        self.assertIn("THEN 'unconfirmed' ELSE h.last_state", summary)
        self.assertIn("current_live.status='live'", summary)
        self.assertNotIn("$summary[$p]['unconfirmed']=", summary)
        for state in ("live", "offline", "unknown", "unconfirmed", "never_checked"):
            self.assertIn(f"'{state}'=>0", summary)

    def test_revalidation_preopens_and_reuses_the_pending_window(self):
        verify = re.search(r"async function verifyWatchLink\(.*?\n}", CLIENT, re.S).group(0)
        self.assertIn("window.open('about:blank','_blank')", verify)
        self.assertIn("pendingWindow.opener=null", verify)
        self.assertLess(verify.index("window.open('about:blank'"), verify.index("await verifyProfile"))
        self.assertIn("pendingWindow.location.replace(confirmedUrl)", verify)
        self.assertIn("pendingWindow.close()", verify)
        self.assertIn("const confirmedUrl=String(confirmed.url||'')", verify)
        self.assertNotIn("confirmed.url||link.href", verify)

    def test_busy_radar_does_not_report_a_false_end(self):
        wait = re.search(r"async function waitForRadarIdle\(.*?\n}", CLIENT, re.S).group(0)
        verify = re.search(r"async function verifyWatchLink\(.*?\n}", CLIENT, re.S).group(0)
        self.assertIn("while(runningMode", wait)
        self.assertIn("await waitForRadarIdle()", verify)
        self.assertIn("Vérification du direct en cours", verify)
        self.assertLess(verify.index("await waitForRadarIdle()"), verify.index("await verifyProfile"))


if __name__ == "__main__":
    unittest.main()
