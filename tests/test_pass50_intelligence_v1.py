import pathlib
import unittest
from datetime import datetime, timedelta, timezone


ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/intelligence-core.php").read_text(encoding="utf-8")
COLLECT = (ROOT / "api/data-collect.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
MIGRATION = (ROOT / "migration-intelligence-v1.sql").read_text(encoding="utf-8")
PUBLISH = (ROOT / "api/data-publish.php").read_text(encoding="utf-8")

WEIGHTS = {"views": 35, "likes": 20, "comments": 20, "publications": 15, "followers": 10}


def comparison(recent, previous):
    if previous > 0:
        return "comparable", max(-100, min(200, (recent - previous) / previous * 100))
    if recent > 0:
        return "new_activity", None
    return "no_activity", 0


def calculate(observations, capture_moments=4, recent_data=True, concentration=0, platforms=1):
    available = {key: value for key, value in observations.items() if key in WEIGHTS and "recent" in value and "previous" in value}
    total_weight = sum(WEIGHTS[key] for key in available)
    comparisons = {key: comparison(value["recent"], value["previous"]) for key, value in available.items()}
    global_variation = sum((30 if status == "new_activity" else variation) * WEIGHTS[key] for key, (status, variation) in comparisons.items()) / total_weight if total_weight else 0
    growth = round(max(0, min(100, 50 + global_variation / 2)))

    weighted = weight = signals = 0
    for metric, metric_weight in {"comments": 40, "likes": 25, "views": 20}.items():
        if metric not in observations or "baseline" not in observations[metric]:
            continue
        recent, baseline = observations[metric]["recent"], observations[metric]["baseline"]
        acceleration = 2 if baseline <= 0 and recent > 0 else (0 if baseline <= 0 else recent / baseline)
        weighted += max(0, min(100, (acceleration - 1) * 50)) * metric_weight
        weight += metric_weight
        signals += acceleration >= 1.5 and recent > 0
    weighted += (100 if concentration >= 0.6 else concentration * 100) * 10
    weight += 10
    if platforms:
        weighted += min(100, (platforms - 1) * 50) * 5
        weight += 5
    buzz = round(weighted / weight) if weight else 0
    volume = sum(observations.get(metric, {}).get("recent", 0) for metric in ("views", "likes", "comments"))
    if not (volume >= 100 or observations.get("comments", {}).get("recent", 0) >= 10) or signals < 2:
        buzz = min(buzz, 69)
    metric_count = len(available)
    comparable = any(value[0] == "comparable" for value in comparisons.values())
    new_activity = any(value[0] == "new_activity" for value in comparisons.values())
    status = "comparable" if comparable else ("new_activity" if new_activity else ("insufficient_history" if not available else "no_activity"))
    confidence = "élevée" if capture_moments >= 4 and metric_count >= 3 and recent_data and status != "new_activity" else ("moyenne" if capture_moments >= 2 and metric_count >= 2 else "faible")
    return {"growth": growth, "buzz": buzz, "confidence": confidence, "variation": global_variation, "comparison_status": status, "recent_data": recent_data}


def distinct_capture_moments(captured_at_values):
    return len({value.astimezone(timezone.utc).replace(minute=0, second=0, microsecond=0) for value in captured_at_values})


class IntelligenceCalculationTests(unittest.TestCase):
    def test_growth_index(self):
        observations = {key: {"recent": 200, "previous": 100} for key in WEIGHTS}
        result = calculate(observations)
        self.assertEqual(result["growth"], 100)
        self.assertEqual(result["variation"], 100)
        self.assertEqual(result["comparison_status"], "comparable")

    def test_missing_metrics_renormalize_available_weights(self):
        observations = {
            "views": {"recent": 200, "previous": 100},
            "comments": {"recent": 50, "previous": 100},
        }
        result = calculate(observations, capture_moments=2)
        self.assertAlmostEqual(result["variation"], (100 * 35 - 50 * 20) / 55)
        self.assertEqual(result["growth"], 73)
        self.assertEqual(result["confidence"], "moyenne")

    def test_real_buzz_is_detected(self):
        observations = {
            "views": {"recent": 4000, "previous": 1000, "baseline": 900},
            "likes": {"recent": 500, "previous": 100, "baseline": 90},
            "comments": {"recent": 180, "previous": 20, "baseline": 18},
        }
        result = calculate(observations, concentration=0.75, platforms=3)
        self.assertGreaterEqual(result["buzz"], 70)
        self.assertEqual(result["confidence"], "élevée")

    def test_low_volume_does_not_create_false_buzz(self):
        observations = {
            "views": {"recent": 8, "previous": 1, "baseline": 1},
            "likes": {"recent": 3, "previous": 0, "baseline": 0},
            "comments": {"recent": 1, "previous": 0, "baseline": 0},
        }
        self.assertLess(calculate(observations, concentration=1, platforms=1)["buzz"], 70)

    def test_confidence_levels(self):
        two_metrics = {"views": {"recent": 2, "previous": 1}, "likes": {"recent": 2, "previous": 1}}
        three_metrics = dict(two_metrics, comments={"recent": 2, "previous": 1})
        self.assertEqual(calculate(two_metrics, capture_moments=1)["confidence"], "faible")
        self.assertEqual(calculate(two_metrics, capture_moments=2)["confidence"], "moyenne")
        self.assertEqual(calculate(three_metrics, capture_moments=4, recent_data=True)["confidence"], "élevée")
        self.assertEqual(calculate(three_metrics, capture_moments=4, recent_data=False)["confidence"], "moyenne")

    def test_decline_threshold(self):
        observations = {
            "views": {"recent": 60, "previous": 100},
            "likes": {"recent": 70, "previous": 100},
            "comments": {"recent": 75, "previous": 100},
        }
        result = calculate(observations)
        self.assertLessEqual(result["variation"], -20)
        self.assertIn(result["confidence"], {"moyenne", "élevée"})

    def test_multiple_contents_at_same_instant_count_as_one_moment(self):
        instant = datetime(2026, 7, 26, 12, 5, tzinfo=timezone.utc)
        self.assertEqual(distinct_capture_moments([instant, instant, instant, instant]), 1)

    def test_four_contents_from_one_cycle_are_not_high_confidence(self):
        observations = {
            "views": {"recent": 200, "previous": 100},
            "likes": {"recent": 40, "previous": 20},
            "comments": {"recent": 20, "previous": 10},
        }
        self.assertEqual(calculate(observations, capture_moments=1)["confidence"], "faible")

    def test_four_distinct_cycles_with_recent_metrics_can_be_high_confidence(self):
        base = datetime(2026, 7, 26, 8, tzinfo=timezone.utc)
        moments = [base + timedelta(hours=offset) for offset in range(4)]
        observations = {
            "views": {"recent": 200, "previous": 100},
            "likes": {"recent": 40, "previous": 20},
            "comments": {"recent": 20, "previous": 10},
        }
        self.assertEqual(distinct_capture_moments(moments), 4)
        self.assertEqual(calculate(observations, capture_moments=4, recent_data=True)["confidence"], "élevée")

    def test_thirty_hour_old_data_is_not_recent(self):
        now = datetime(2026, 7, 26, 12, tzinfo=timezone.utc)
        captured = now - timedelta(hours=30)
        self.assertFalse(captured >= now - timedelta(hours=24))
        observations = {"views": {"recent": 0, "previous": 100}, "likes": {"recent": 0, "previous": 10}}
        self.assertNotEqual(calculate(observations, capture_moments=4, recent_data=False)["confidence"], "élevée")

    def test_zero_previous_is_new_activity_not_two_hundred_percent(self):
        status, variation = comparison(100, 0)
        self.assertEqual(status, "new_activity")
        self.assertIsNone(variation)

    def test_first_collection_does_not_automatically_reach_growth_100(self):
        observations = {key: {"recent": 100, "previous": 0} for key in WEIGHTS}
        result = calculate(observations)
        self.assertEqual(result["comparison_status"], "new_activity")
        self.assertLess(result["growth"], 100)

    def test_isolated_new_activity_is_not_high_confidence(self):
        observations = {
            "views": {"recent": 1000, "previous": 0},
            "likes": {"recent": 100, "previous": 0},
            "comments": {"recent": 20, "previous": 0},
        }
        result = calculate(observations, capture_moments=4)
        self.assertEqual(result["comparison_status"], "new_activity")
        self.assertNotEqual(result["confidence"], "élevée")


class IntelligenceIntegrationTests(unittest.TestCase):
    def test_history_has_period_uniqueness_and_upsert(self):
        self.assertIn("UNIQUE KEY uq_p50_intelligence_period(profile_id,period_start,period_end)", MIGRATION)
        self.assertIn("ON DUPLICATE KEY UPDATE growth_index=VALUES(growth_index)", CORE)

    def test_intelligence_error_is_non_blocking_for_pass50_update(self):
        call = COLLECT.index("p50_intelligence_run_profile")
        nested_try = COLLECT.rfind("try{", 0, call)
        nested_catch = COLLECT.index("catch(Throwable $intelligenceError)", call)
        finish_run = COLLECT.index("p50_de_finish_run", nested_catch)
        self.assertGreater(nested_try, COLLECT.index("p50_radar_collect_profile"))
        self.assertLess(nested_catch, finish_run)
        self.assertIn("non bloquante", COLLECT[nested_catch:finish_run])

    def test_admin_displays_private_sections_including_building(self):
        self.assertIn("['intelligence','PASS50 Intelligence']", UI)
        self.assertIn("Tendances fortes", UI)
        self.assertIn("Buzz détectés", UI)
        self.assertIn("Profils en recul", UI)
        self.assertIn("Signaux en construction", UI)
        self.assertIn("buildingSignals", UI)
        self.assertIn("require_role($user,'owner','admin')", (ROOT / "api/intelligence.php").read_text(encoding="utf-8"))

    def test_official_ranking_pipeline_is_untouched_by_intelligence(self):
        self.assertNotIn("intelligence", PUBLISH.lower())
        self.assertNotIn("p50_intelligence", (ROOT / "index.html").read_text(encoding="utf-8").lower())
        self.assertIn("Aucun résultat ne modifie le score officiel ni le classement public.", UI)

    def test_sections_are_limited_and_display_thresholds_are_relaxed(self):
        self.assertIn("$item['recentData']&&$item['comparisonStatus']==='comparable'&&$item['growthIndex']>=55", CORE)
        self.assertIn("$item['recentData']&&$item['buzzIndex']>=60", CORE)
        self.assertIn("$item['globalVariation']<=-15", CORE)
        self.assertIn("'buildingSignals'=>array_slice($building,0,20)", CORE)
        self.assertEqual(CORE.count("array_slice($"), 4)
        # Les diagnostics MAJ restent stricts (confiance moyenne/élevée).
        diagnostics = CORE[CORE.index("function p50_intelligence_add_diagnostic"):CORE.index("function p50_intelligence_dashboard")]
        self.assertIn("['moyenne','élevée']", diagnostics)
        self.assertIn("($analysis['growthIndex']??0)>=65", diagnostics)
        self.assertIn("($analysis['buzzIndex']??0)>=70", diagnostics)

    def test_profiles_without_recent_data_are_excluded_from_trends_and_buzz(self):
        self.assertIn("$item['recentData']&&$item['comparisonStatus']==='comparable'&&$item['growthIndex']>=55", CORE)
        self.assertIn("$item['recentData']&&$item['buzzIndex']>=60", CORE)
        diagnostics = CORE[CORE.index("function p50_intelligence_add_diagnostic"):CORE.index("function p50_intelligence_dashboard")]
        self.assertIn("!empty($analysis['recentData'])", diagnostics)
        self.assertIn("($analysis['comparisonStatus']??'')==='comparable'", diagnostics)
        self.assertEqual(diagnostics.count("!empty($analysis['recentData'])"), 2)

    def test_capture_moments_are_hourly_distinct_and_not_row_count(self):
        self.assertIn("$captureMoments[$captured->format('Y-m-d H:00:00')]=true", CORE)
        self.assertIn("'distinctCaptureMoments'=>count($captureMoments)", CORE)
        self.assertNotIn("'captureCount'=>count($rows)", CORE)

    def test_intelligence_writes_use_utc(self):
        self.assertIn("UTC_TIMESTAMP()", CORE)
        self.assertNotIn("NOW()", CORE)


if __name__ == "__main__":
    unittest.main()
