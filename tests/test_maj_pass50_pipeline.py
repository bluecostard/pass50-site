import copy
import json
import math
import pathlib
import re
import unittest


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
        "c4": (likes + 3 * comments + 5 * shares) / views,
        "c5": shares / views,
        "c6": math.log10(1 + comments) if comments else None,
        "c7": math.log10(1 + views),
        "c8": 1,
        "c9": math.log10(1 + shares) if shares else None,
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
        "c15": math.log10(1 + shares) if shares else None,
    }
    weights = {
        "c2": 0.08, "c4": 0.08, "c5": 0.09, "c6": 0.05, "c7": 0.10,
        "c8": 0.08, "c9": 0.06, "c13": 0.04, "c14": 0.07, "c15": 0.07,
    }
    values = {}
    for key, value in raw.items():
        if value is None:
            continue
        if key in {"c2", "c6", "c7", "c9", "c15"}:
            values[key] = max(0, min(100, 20 + value * 16))
        elif key == "c4":
            values[key] = max(0, min(100, value * 500))
        elif key == "c5":
            values[key] = max(0, min(100, value * 1000))
        elif key == "c8":
            values[key] = max(0, min(100, value * 20))
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
        for counter in ("found", "usableMetrics", "recalculated", "scoresChanged", "ranksChanged", "published"):
            self.assertIn(counter, UI)
        self.assertIn("Collecte terminée, mais aucun score ni rang n'a changé.", UI)
        self.assertIn("totals.scoresChanged>0||totals.ranksChanged>0", UI)

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
