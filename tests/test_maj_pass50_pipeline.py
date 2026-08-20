import copy
import json
import math
import pathlib
import re
import unittest
from datetime import datetime, timedelta, timezone


ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
COLLECT = (ROOT / "api/data-collect.php").read_text(encoding="utf-8")
PUBLISH = (ROOT / "api/data-publish.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
STATE_API = (ROOT / "api/state.php").read_text(encoding="utf-8")
PERIODS = ("2H", "24H", "48H", "7J", "15J")


def score_from_metrics(metrics):
    """Reference of the existing 15C normalization used for pipeline invariants."""
    views = int(metrics.get("views", 0))
    likes = int(metrics.get("likes", 0))
    comments = int(metrics.get("comments", 0))
    shares = int(metrics.get("shares", 0))
    if views <= 0:
        return None
    raw = {
        "c2": math.log10(1 + views),
        "c3": 0.05,
        "c4": (likes + 3 * comments + 5 * shares) / views,
        "c5": shares / views,
        "c6": math.log10(1 + comments) if comments else None,
        "c7": math.log10(1 + views),
        "c8": 1,
        "c9": math.log10(1 + shares) if shares else None,
        "c10": math.log10(1 + likes) if likes else None,
        "c11": None,
        "c12": 1,
        "c13": 1,
        "c14": max(
            0,
            min(
                100,
                70
                + min(25, math.log10(1 + views) * 3)
                - min(20, abs((likes + comments + shares) / views - 0.08) * 100),
            ),
        ),
        "c15": math.log10(1.5),
    }
    weights = {
        "c2": 0.09, "c3": 0.07, "c4": 0.07, "c5": 0.07, "c6": 0.05, "c7": 0.11,
        "c8": 0.07, "c9": 0.06, "c10": 0.06, "c11": 0.05, "c12": 0.06,
        "c13": 0.04, "c14": 0.07, "c15": 0.07,
    }
    values = {}
    for key, value in raw.items():
        if value is None:
            continue
        if key in {"c2", "c6", "c7", "c9", "c10", "c11", "c15"}:
            values[key] = max(0, min(100, 20 + value * 16))
        elif key == "c3":
            values[key] = max(0, min(100, 50 + value * 100))
        elif key == "c4":
            values[key] = max(0, min(100, value * 500))
        elif key == "c5":
            values[key] = max(0, min(100, value * 1000))
        elif key == "c8":
            values[key] = max(0, min(100, value * 20))
        elif key == "c12":
            values[key] = max(0, min(100, value * 25))
        elif key == "c13":
            values[key] = max(0, min(100, value * 8))
        else:
            values[key] = max(0, min(100, value))
    total_weight = sum(weights[key] for key in values)
    base = sum(values[key] * weights[key] for key in values) / total_weight
    return round(base)


def run_reference_pipeline(state, metrics_by_profile):
    before = copy.deepcopy(state)
    result = copy.deepcopy(state)
    recalculated = 0
    for profile in result["profiles"]:
        calculated = score_from_metrics(metrics_by_profile.get(profile["id"], {}))
        if calculated is None:
            profile["scoreStatus"] = "not_recalculated"
            continue
        profile["scores"] = dict(profile["scores"])
        for period in PERIODS:
            profile["scores"][period] = calculated
        profile["scoreStatus"] = "recalculated"
        recalculated += 1

    def rank_map(document):
        ranked = sorted(
            (p for p in document["profiles"] if p.get("alive") and p.get("eligible")),
            key=lambda p: (-p["scores"]["2H"], p["name"]),
        )
        return {profile["id"]: index + 1 for index, profile in enumerate(ranked)}

    old_ranks, new_ranks = rank_map(before), rank_map(result)
    result["profiles"].sort(key=lambda p: (new_ranks.get(p["id"], 10**9), p["name"]))
    scores_changed = sum(
        before_profile["scores"] != next(p for p in result["profiles"] if p["id"] == before_profile["id"])["scores"]
        for before_profile in before["profiles"]
    )
    ranks_changed = sum(old_ranks.get(key) != new_ranks.get(key) for key in set(old_ranks) | set(new_ranks))
    return result, {
        "recalculatedProfiles": recalculated,
        "scoresChanged": scores_changed,
        "ranksChanged": ranks_changed,
    }


def sample_state():
    return {
        "profiles": [
            {"id": "a", "name": "Alpha", "alive": True, "eligible": True, "scores": dict.fromkeys(PERIODS, 80)},
            {"id": "b", "name": "Beta", "alive": True, "eligible": True, "scores": dict.fromkeys(PERIODS, 60)},
        ]
    }


def accept_state_write(current_revision, base_revision, current_state, incoming_state):
    if base_revision < current_revision:
        return current_state, current_revision, False
    if incoming_state == current_state:
        return current_state, current_revision, True
    return incoming_state, current_revision + 1, True


def time_weight(age_hours):
    if age_hours <= 24:
        return 1.0
    if age_hours <= 48:
        return 0.90
    if age_hours <= 72:
        return 0.75
    if age_hours <= 120:
        return 0.55
    if age_hours <= 168:
        return 0.35
    return 0.0


def event_key(event):
    return event["profile_id"], event["platform"].lower(), event["url"].split("?")[0].rstrip("/")


def summarize_events(events, now):
    unique = {event_key(event): event for event in events}
    historical = sum(len(event["metrics"]) for event in unique.values())
    active = sum(
        len(event["metrics"])
        for event in unique.values()
        if time_weight((now - event["published_at"]).total_seconds() / 3600) > 0
    )
    return historical, len(unique), active


class PipelineBehaviorTests(unittest.TestCase):
    def test_collection_without_metrics_preserves_scores_and_ranking(self):
        original = sample_state()
        final, counters = run_reference_pipeline(original, {})
        self.assertEqual([p["scores"] for p in final["profiles"]], [p["scores"] for p in original["profiles"]])
        self.assertEqual(counters["scoresChanged"], 0)
        self.assertEqual(counters["ranksChanged"], 0)

    def test_changed_metric_changes_profile_scores_and_published_json(self):
        final, counters = run_reference_pipeline(sample_state(), {"b": {"views": 1_000_000, "likes": 100_000, "comments": 20_000, "shares": 10_000}})
        beta = next(profile for profile in final["profiles"] if profile["id"] == "b")
        self.assertNotEqual(beta["scores"]["2H"], 60)
        self.assertEqual(json.loads(json.dumps(final))["profiles"][0]["scores"], final["profiles"][0]["scores"])
        self.assertEqual(counters["scoresChanged"], 1)

    def test_changed_score_can_change_rank(self):
        state=sample_state()
        state["profiles"][0]["scores"]=dict.fromkeys(PERIODS,40)
        state["profiles"][1]["scores"]=dict.fromkeys(PERIODS,20)
        final, counters = run_reference_pipeline(state, {"b": {"views": 10_000_000, "likes": 2_000_000, "comments": 500_000, "shares": 300_000}})
        self.assertEqual(final["profiles"][0]["id"], "b")
        self.assertGreaterEqual(counters["ranksChanged"], 2)

    def test_rolling_window_time_weights(self):
        expected = ((12, 1.00), (36, .90), (60, .75), (96, .55), (144, .35), (192, 0))
        for age, weight in expected:
            with self.subTest(age=age):
                self.assertEqual(time_weight(age), weight)

    def test_identical_events_count_once(self):
        now = datetime.now(timezone.utc)
        event = {"profile_id": "a", "platform": "X", "url": "https://x.com/a/status/1?utm_source=test", "published_at": now - timedelta(hours=3), "metrics": {"views": 100, "comments": 2}}
        duplicate = dict(event, url="https://x.com/a/status/1")
        self.assertEqual(summarize_events([event, duplicate], now), (2, 1, 2))

    def test_six_day_event_can_trigger_recalculation(self):
        weight = time_weight(6 * 24)
        weighted = {key: value * weight for key, value in {"views": 1_000_000, "likes": 100_000, "comments": 20_000, "shares": 10_000}.items()}
        coverage = .72
        confidence = .5 * coverage + .3 * weight + .2 * .94
        self.assertEqual(weight, .35)
        self.assertIsNotNone(score_from_metrics(weighted))
        self.assertGreaterEqual(len(("c2", "c4", "c5", "c6", "c7", "c8", "c9", "c13", "c14", "c15")), 6)
        self.assertGreaterEqual(confidence, .65)

    def test_eight_day_event_does_not_trigger_recalculation(self):
        self.assertEqual(time_weight(8 * 24), 0)

    def test_historical_unique_and_active_counters_are_coherent(self):
        now = datetime.now(timezone.utc)
        events = [
            {"profile_id": "a", "platform": "X", "url": "https://x.com/a/status/1", "published_at": now - timedelta(days=1), "metrics": {"views": 10, "comments": 1}},
            {"profile_id": "a", "platform": "X", "url": "https://x.com/a/status/1?utm_source=x", "published_at": now - timedelta(days=1), "metrics": {"views": 10, "comments": 1}},
            {"profile_id": "b", "platform": "YouTube", "url": "https://youtube.com/watch?v=old", "published_at": now - timedelta(days=8), "metrics": {"views": 20}},
        ]
        self.assertEqual(summarize_events(events, now), (3, 2, 2))


class PipelineSourceContractTests(unittest.TestCase):
    def test_collection_has_no_intermediate_publication(self):
        self.assertNotIn("p50_de_publish_profile(", COLLECT)
        self.assertNotIn("p50_de_save_public_state(", COLLECT)
        self.assertNotIn("syncCloudState()", UI[UI.index("async function deRunMajPass50"):UI.index("function deRenderHub")])

    def test_pipeline_has_one_public_state_write_and_transaction_rollback(self):
        match = re.search(r"function p50_de_publish_score_pipeline\b.*?\n}\n", CORE, re.S)
        self.assertIsNotNone(match)
        body = match.group(0)
        self.assertEqual(body.count("p50_de_save_public_state("), 1)
        self.assertIn("beginTransaction()", body)
        self.assertIn("rollBack()", body)
        self.assertIn("p50_de_sort_state_profiles(", body)
        self.assertIn("p50_de_publish_score_pipeline(", PUBLISH)

    def test_admin_counters_and_exact_no_change_message_exist(self):
        for counter in ("found", "historicalMetrics", "uniqueEvents", "activeMetrics", "recalculated", "scoresChanged", "ranksChanged", "published"):
            self.assertIn(counter, UI)
        self.assertIn("Collecte terminée, mais aucune métrique récente n'est disponible pour recalculer les scores.", UI)
        self.assertIn("Collecte terminée. Les profils ont été recalculés, mais aucun score ni rang n'a changé.", UI)
        self.assertIn("totals.scoresChanged>0||totals.ranksChanged>0", UI)

    def test_rolling_window_is_server_side_admissibility_base(self):
        self.assertIn("p50_de_15c_window($profileId,168)", CORE)
        self.assertIn("function p50_de_time_weight(", CORE)
        for fragment in ("return 1.0", "return .90", "return .75", "return .55", "return .35", "return 0.0"):
            self.assertIn(fragment, CORE)

    def test_server_deduplicates_before_metrics_and_history(self):
        self.assertIn("p50_de_unique_activity_rows(", CORE)
        self.assertIn("p50_de_activity_key(", CORE)
        self.assertIn("p50_de_normalize_activity_url(", CORE)
        self.assertIn("SELECT metrics FROM p50_activity_metric_history", CORE)

    def test_pending_frontend_sync_is_cancelled_before_update(self):
        start = UI.index("async function deRunMajPass50")
        collect = UI.index("data-hub.php", start)
        prefix = UI[start:collect]
        self.assertIn("window.majPass50Running=true", prefix)
        self.assertIn("clearTimeout(CLOUD.syncTimer)", prefix)
        self.assertIn("CLOUD.syncTimer=null", prefix)

    def test_frontend_cloud_writes_are_blocked_during_update(self):
        self.assertIn("if(window.majPass50Running||!CLOUD.enabled||!CLOUD.ready)return;", INDEX)
        self.assertIn("if(window.majPass50Running)return {ok:true,skipped:true", INDEX)
        self.assertIn("window.majPass50Running=false", UI)
        self.assertNotIn("publishVerified:true", UI)

    def test_maj_collects_one_profile_per_request_with_retry_and_resume(self):
        self.assertIn("const body={limit:1,deep:true,excludeIds:[...DE.majSeen],includeHub:false,syncRegistry:false};", UI)
        self.assertNotIn("{limit:5,deep:true,excludeIds:[...DE.majSeen]}", UI)
        self.assertIn("preview:true,limit:1", UI)
        self.assertIn("attempt<=3", UI)
        self.assertIn("function deMajCanResume", UI)
        self.assertIn("processedIds:[...DE.majSeen]", UI)
        self.assertIn("REPRENDRE LA MAJ PASS50", UI)
        self.assertIn("'hub'=>$includeHub?p50_de_hub_payload():null", COLLECT)
        self.assertIn("if($preview)", COLLECT)
        self.assertIn("Erreur serveur (${res.status})", INDEX)

    def test_final_publish_avoids_ionos_500_after_full_collection(self):
        self.assertIn("set_time_limit(300)", PUBLISH)
        self.assertIn("ignore_user_abort(true)", PUBLISH)
        self.assertIn("'hub'=>$includeHub?p50_de_hub_payload():null", PUBLISH)
        self.assertNotIn("'hub'=>p50_de_hub_payload()", PUBLISH)
        self.assertNotIn("p50_de_sync_registry_from_state()", PUBLISH)
        self.assertIn("function deMajPublish", UI)
        self.assertIn("includeHub:false", UI)
        self.assertIn("timeoutMs:180000", UI)
        self.assertIn("Hub MAJ non bloquant", UI)
        self.assertIn("function p50_de_import_all_state_activities", CORE)
        self.assertIn("if($ownsState)", CORE)
        start = UI.index("DE.majStage='4/7 · Publication des scores'")
        chunk = UI[start:UI.index("DE.majStage='5/7 · Rechargement et reclassement'")]
        self.assertIn("deMajPublish()", chunk)
        self.assertNotIn("apiFetch('data-publish.php'", chunk)

    def test_maj_display_refresh_does_not_fail_the_completed_collection(self):
        start = UI.index("DE.majStage='5/7 · Rechargement et reclassement'")
        chunk = UI[start:UI.index("DE.majStage='6/7 · État final publié'")]
        self.assertIn("Reclassement affichage non bloquant", chunk)
        self.assertIn("try{", chunk)

    def test_stale_frontend_state_cannot_overwrite_newer_state(self):
        current = {"profiles": [{"id": "a", "scores": {"2H": 80}}]}
        stale = {"profiles": [{"id": "a", "scores": {"2H": 20}}]}
        final, revision, accepted = accept_state_write(4, 3, current, stale)
        self.assertFalse(accepted)
        self.assertEqual(revision, 4)
        self.assertEqual(final, current)
        self.assertIn("$baseRevision<$currentRevision", STATE_API)
        self.assertIn("'code'=>'stale_state'", STATE_API)
        self.assertIn("LIMIT 1 FOR UPDATE", STATE_API)
        self.assertIn("bool $incrementRevision = true", CORE)
        self.assertIn("p50_de_save_public_state($state,$userId,false)", CORE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
