import re
import statistics
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / "api" / "metrics-ranking-core.php").read_text(encoding="utf-8")
CALIBRATION = (ROOT / "api" / "metrics-ranking-calibration-core.php").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api" / "metrics-ranking-calibration.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
CSS = (ROOT / "data-engine-ui.css").read_text(encoding="utf-8")
TOOLS = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


def function(source, name):
    start = source.index(f"function {name}(")
    following = re.search(r"\nfunction [A-Za-z0-9_]+\(", source[start + 1 :])
    return source[start:] if following is None else source[start : start + 1 + following.start()]


def transition(previous, current):
    if previous is None:
        return {
            "top10": None,
            "top50": None,
            "entries": None,
            "exits": None,
            "movement": None,
            "score": None,
        }
    before10 = {profile for profile, rank, _ in previous if rank <= 10}
    after10 = {profile for profile, rank, _ in current if rank <= 10}
    before50 = {profile for profile, rank, _ in previous if rank <= 50}
    after50 = {profile for profile, rank, _ in current if rank <= 50}
    before = {profile: (rank, score) for profile, rank, score in previous}
    common = [(rank, score, *before[profile]) for profile, rank, score in current if profile in before]
    return {
        "top10": len(before10 & after10) / len(before10) * 100 if before10 else None,
        "top50": len(before50 & after50) / len(before50) * 100 if before50 else None,
        "entries": len(after50 - before50),
        "exits": len(before50 - after50),
        "movement": statistics.median(abs(rank - old_rank) for rank, _, old_rank, _ in common) if common else None,
        "score": statistics.median(abs(score - old_score) for _, score, _, old_score in common) if common else None,
    }


def stability(transitions, retention, movement):
    if transitions < 6:
        return "insufficient_history"
    if retention >= 85 and movement <= 3:
        return "stable"
    if retention >= 70 and movement <= 8:
        return "moderate"
    return "volatile"


class MetricsRankingCalibrationV1Tests(unittest.TestCase):
    def test_schema_adds_optional_exact_period_summaries(self):
        self.assertIn("P50_MR_CALIBRATION_VERSION='CAL-V1.0'", CALIBRATION)
        self.assertIn("CREATE TABLE IF NOT EXISTS p50_metric_ranking_period_runs", CORE)
        self.assertIn("PRIMARY KEY(run_uuid,period_key)", CORE)
        self.assertIn("INDEX idx_p50_mr_period_algorithm(algorithm_version,period_key,calculated_at)", CORE)
        schema_status = function(CORE, "p50_mr_schema_status")
        self.assertNotIn("p50_metric_ranking_period_runs", schema_status)

    def test_period_summary_is_transactional_and_complete(self):
        calculate = function(CORE, "p50_mr_calculate")
        summary = function(CORE, "p50_mr_period_summary")
        self.assertIn("p50_mr_period_summary($rows)", calculate)
        self.assertIn("INSERT INTO p50_metric_ranking_period_runs", calculate)
        self.assertIn("ON DUPLICATE KEY UPDATE", calculate)
        self.assertLess(calculate.index("beginTransaction()"), calculate.index("INSERT INTO p50_metric_ranking_period_runs"))
        self.assertLess(calculate.index("INSERT INTO p50_metric_ranking_period_runs"), calculate.index("commit()"))
        self.assertIn("foreach($selected as $periodKey=>$hours)", calculate)
        self.assertIn("'thresholdExcludedCount'", summary)
        self.assertIn("'hardExcludedCount'", summary)
        self.assertIn("'otherExcludedCount'", summary)
        self.assertIn("'profilesConsidered'=>$profiles", summary)

    def test_median_and_exclusion_partition_are_deterministic(self):
        self.assertEqual(statistics.median([1, 3, 2]), 2)
        self.assertEqual(statistics.median([1, 4, 2, 3]), 2.5)
        median = function(CORE, "p50_mr_median")
        self.assertIn("$count%2===1", median)
        self.assertIn("($numeric[$middle-1]+$numeric[$middle])/2", median)
        rows = [
            "classable",
            "threshold",
            "hard",
            "other",
        ]
        self.assertEqual(sum(1 for _ in rows), 4)

    def test_calibration_get_is_read_only_bounded_and_admin_only(self):
        read = function(CALIBRATION, "p50_mrc_read")
        self.assertIn("$runLimit=max(6,min(100,$runLimit))", read)
        self.assertIn("LIMIT 200", read)
        self.assertNotIn("p50_mr_ensure_schema", CALIBRATION + ENDPOINT)
        self.assertNotRegex(CALIBRATION + ENDPOINT, r"\b(?:INSERT|UPDATE|DELETE|CREATE TABLE)\b")
        self.assertIn("require_method('GET')", ENDPOINT)
        self.assertIn("require_role($user,'owner','admin')", ENDPOINT)

    def test_old_cycles_fall_back_to_top100_with_explicit_reliability(self):
        read = function(CALIBRATION, "p50_mrc_read")
        self.assertIn("$snapshotCount=count($cycleSnapshots)", read)
        self.assertIn("$capped=!$exact&&$snapshotCount>=100", read)
        self.assertIn("'summaryExact'=>$exact", read)
        self.assertIn("'classableCountCapped'=>$capped", read)
        self.assertIn("'excludedCount'=>$exact?", read)
        self.assertIn("Historique Top 100", UI)
        self.assertIn("run.summaryExact?'Exact':'Historique Top 100'", UI)

    def test_cycle_transition_metrics(self):
        previous = [("A", 1, 90), ("B", 2, 80), ("C", 3, 70)]
        current = [("B", 1, 84), ("A", 2, 88), ("D", 3, 75)]
        measured = transition(previous, current)
        self.assertAlmostEqual(measured["top10"], 200 / 3)
        self.assertAlmostEqual(measured["top50"], 200 / 3)
        self.assertEqual(measured["entries"], 1)
        self.assertEqual(measured["exits"], 1)
        self.assertEqual(measured["movement"], 1)
        self.assertEqual(measured["score"], 3)
        transition_php = function(CALIBRATION, "p50_mrc_transition")
        self.assertIn("function p50_mrc_transition(?array $previous,array $current)", transition_php)
        self.assertIn("if($previous===null)", transition_php)
        for field in ("top10Retention", "top50Retention", "medianAbsoluteRankMovement", "top50Entries", "top50Exits", "medianScoreChange"):
            self.assertIn(f"'{field}'", transition_php)

    def test_first_empty_and_following_empty_cycles_are_distinct(self):
        first = transition(None, [("A", 1, 80)])
        self.assertTrue(all(value is None for value in first.values()))
        after_empty = transition([], [("A", 1, 80), ("B", 2, 70)])
        self.assertIsNone(after_empty["top10"])
        self.assertIsNone(after_empty["top50"])
        self.assertEqual(after_empty["entries"], 2)
        self.assertEqual(after_empty["exits"], 0)
        before_empty = transition([("A", 1, 80), ("B", 2, 70)], [])
        self.assertEqual(before_empty["top10"], 0)
        self.assertEqual(before_empty["top50"], 0)
        self.assertEqual(before_empty["entries"], 0)
        self.assertEqual(before_empty["exits"], 2)
        self.assertIsNone(before_empty["movement"])
        self.assertIsNone(before_empty["score"])
        read = function(CALIBRATION, "p50_mrc_read")
        self.assertIn("$previousSnapshots=null", read)
        self.assertIn("$previousSnapshots=$cycleSnapshots", read)

    def test_capped_cycles_are_excluded_from_average_classable_count(self):
        cycles = [
            {"classableCount": 12, "classableCountCapped": False},
            {"classableCount": 8, "classableCountCapped": False},
            {"classableCount": 100, "classableCountCapped": True},
        ]
        included = [
            cycle["classableCount"]
            for cycle in cycles
            if cycle["classableCount"] is not None and not cycle["classableCountCapped"]
        ]
        self.assertEqual(sum(included) / len(included), 10)
        read = function(CALIBRATION, "p50_mrc_read")
        self.assertIn("if($run['classableCount']!==null&&!$run['classableCountCapped'])", read)
        self.assertIn("'averageClassableCount'=>p50_mrc_average($classableValues)", read)

    def test_stability_and_maturity_states(self):
        self.assertEqual(stability(5, 100, 0), "insufficient_history")
        self.assertEqual(stability(6, 90, 2), "stable")
        self.assertEqual(stability(6, 75, 7), "moderate")
        self.assertEqual(stability(6, 60, 10), "volatile")
        for state in ("insufficient_history", "stable", "moderate", "volatile"):
            self.assertIn(f"'{state}'", CALIBRATION)
        for state in ("collecting", "observing", "calibratable"):
            self.assertIn(f"'{state}'", CALIBRATION)
        self.assertIn("'minimumExactCycles'=>24", CALIBRATION)

    def test_threshold_matrix_has_36_cells_and_exact_baseline(self):
        simulation = function(CALIBRATION, "p50_mrc_threshold_simulation")
        self.assertIn("[35,40,45,50,55,60]", simulation)
        self.assertIn("[45,50,55,60,65,70]", simulation)
        self.assertIn("'coverageThreshold'=>45,'confidenceThreshold'=>55", simulation)
        self.assertIn("$baseline=count(array_filter($currentRows", simulation)
        self.assertIn("$hard=array_filter($reasons", simulation)
        self.assertIn("if($hard)continue", simulation)
        self.assertEqual(6 * 6, 36)
        self.assertIn("coverage===45&&confidence===55", UI)

    def test_response_is_safe_and_server_never_reads_public_state(self):
        combined = CALIBRATION + ENDPOINT
        for forbidden in (
            "app_state",
            "metadata_json",
            "components_json",
            "raw_features_json",
            "data-publish.php",
            "p50_de_publish_profile",
            "p50_de_publish_score_pipeline",
        ):
            self.assertNotIn(forbidden, combined)
        for limitation in ("historicalSnapshotsTop100", "serverPublicRankingAccess", "automaticChanges"):
            self.assertIn(f"'{limitation}'", CALIBRATION)

    def test_three_subviews_and_non_classable_wording(self):
        for view, label in (("current", "Classement actuel"), ("history", "Historique des cycles"), ("calibration", "Calibration")):
            self.assertIn(f'data-ranking-view="{view}"', UI)
            self.assertIn(label, UI)
        current = function(UI, "deRankingCurrentHtml")
        self.assertIn("!row.classable?'Non classé'", current)
        self.assertIn("evolutionClass=!row.classable?'unranked'", current)
        self.assertIn('class="${evolutionClass}"', current)
        self.assertIn("#adminModal .de-ranking-lab td.unranked", CSS)
        self.assertNotIn("class=\"${row.rankDelta>0?", current)
        self.assertIn("row.rankDelta===null?'Nouvelle entrée'", current)
        ranking_block = UI[UI.index("function deRankingCurrentHtml"):UI.index("async function deCalculateRankingLab")]
        for forbidden in (">Appliquer<", ">Publier<", "Valider les seuils", "Remplacer le classement"):
            self.assertNotIn(forbidden, ranking_block)

    def test_public_comparison_and_spearman_stay_in_browser(self):
        comparison = function(UI, "deRankingPublicComparison")
        spearman = function(UI, "deSpearman")
        self.assertIn("dePublicRanking(DE.rankingLabPeriod)", comparison)
        self.assertIn("regionEligible(profile)", function(UI, "dePublicRanking"))
        self.assertIn("pairs.length<3", spearman)
        self.assertIn("denominator===0?null", spearman)
        self.assertIn("Math.max(-1,Math.min(1", spearman)
        self.assertIn("Zone publique active", UI)
        self.assertNotIn("app_state", CALIBRATION)

    def test_versions_and_admin_scoped_styles_are_coherent(self):
        self.assertIn("data-engine-ui.js?v=18.2", TOOLS)
        self.assertIn("data-engine-ui.css?v=27.0", TOOLS)
        self.assertIn("data-engine-ui.js?v=18.2", SW)
        self.assertIn("data-engine-ui.css?v=27.0", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")
        self.assertIn("v9-tools.js?v=15.3", INDEX)
        self.assertIn("v9-tools.js?v=15.3", SW)
        self.assertNotIn("v9-tools.js?v=15.2", INDEX + SW)
        self.assertIn("#adminModal .de-ranking-lab", CSS)
        self.assertNotIn("data-engine-ui.js?v=17.0", TOOLS + SW)
        self.assertNotIn("data-engine-ui.css?v=26.0", TOOLS + SW)


if __name__ == "__main__":
    unittest.main()
