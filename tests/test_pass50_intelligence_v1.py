import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/intelligence-core.php").read_text(encoding="utf-8")
COLLECT = (ROOT / "api/data-collect.php").read_text(encoding="utf-8")
UI = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
MIGRATION = (ROOT / "migration-intelligence-v1.sql").read_text(encoding="utf-8")
PUBLISH = (ROOT / "api/data-publish.php").read_text(encoding="utf-8")

WEIGHTS = {"views": 35, "likes": 20, "comments": 20, "publications": 15, "followers": 10}


def change(recent, previous):
    if previous <= 0:
        return 200 if recent > 0 else 0
    return max(-100, min(200, (recent - previous) / previous * 100))


def calculate(observations, captures=4, recent_data=True, concentration=0, platforms=1):
    available = {key: value for key, value in observations.items() if key in WEIGHTS and "recent" in value and "previous" in value}
    total_weight = sum(WEIGHTS[key] for key in available)
    global_variation = sum(change(value["recent"], value["previous"]) * WEIGHTS[key] for key, value in available.items()) / total_weight if total_weight else 0
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
    confidence = "élevée" if captures >= 4 and metric_count >= 3 and recent_data else ("moyenne" if captures >= 2 and metric_count >= 2 else "faible")
    return growth, buzz, confidence, global_variation


class IntelligenceCalculationTests(unittest.TestCase):
    def test_growth_index(self):
        observations = {key: {"recent": 200, "previous": 100} for key in WEIGHTS}
        growth, _, _, variation = calculate(observations)
        self.assertEqual(growth, 100)
        self.assertEqual(variation, 100)

    def test_missing_metrics_renormalize_available_weights(self):
        observations = {
            "views": {"recent": 200, "previous": 100},
            "comments": {"recent": 50, "previous": 100},
        }
        growth, _, confidence, variation = calculate(observations, captures=2)
        self.assertAlmostEqual(variation, (100 * 35 - 50 * 20) / 55)
        self.assertEqual(growth, 73)
        self.assertEqual(confidence, "moyenne")

    def test_real_buzz_is_detected(self):
        observations = {
            "views": {"recent": 4000, "previous": 1000, "baseline": 900},
            "likes": {"recent": 500, "previous": 100, "baseline": 90},
            "comments": {"recent": 180, "previous": 20, "baseline": 18},
        }
        _, buzz, confidence, _ = calculate(observations, concentration=0.75, platforms=3)
        self.assertGreaterEqual(buzz, 70)
        self.assertEqual(confidence, "élevée")

    def test_low_volume_does_not_create_false_buzz(self):
        observations = {
            "views": {"recent": 8, "previous": 1, "baseline": 1},
            "likes": {"recent": 3, "previous": 0, "baseline": 0},
            "comments": {"recent": 1, "previous": 0, "baseline": 0},
        }
        _, buzz, _, _ = calculate(observations, concentration=1, platforms=1)
        self.assertLess(buzz, 70)

    def test_confidence_levels(self):
        two_metrics = {"views": {"recent": 2, "previous": 1}, "likes": {"recent": 2, "previous": 1}}
        three_metrics = dict(two_metrics, comments={"recent": 2, "previous": 1})
        self.assertEqual(calculate(two_metrics, captures=1)[2], "faible")
        self.assertEqual(calculate(two_metrics, captures=2)[2], "moyenne")
        self.assertEqual(calculate(three_metrics, captures=4, recent_data=True)[2], "élevée")
        self.assertEqual(calculate(three_metrics, captures=4, recent_data=False)[2], "moyenne")

    def test_decline_threshold(self):
        observations = {
            "views": {"recent": 60, "previous": 100},
            "likes": {"recent": 70, "previous": 100},
            "comments": {"recent": 75, "previous": 100},
        }
        _, _, confidence, variation = calculate(observations)
        self.assertLessEqual(variation, -20)
        self.assertIn(confidence, {"moyenne", "élevée"})


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

    def test_admin_displays_three_private_sections(self):
        self.assertIn("['intelligence','PASS50 Intelligence']", UI)
        self.assertIn("Tendances fortes", UI)
        self.assertIn("Buzz détectés", UI)
        self.assertIn("Profils en recul", UI)
        self.assertIn("require_role($user,'owner','admin')", (ROOT / "api/intelligence.php").read_text(encoding="utf-8"))

    def test_official_ranking_pipeline_is_untouched_by_intelligence(self):
        self.assertNotIn("intelligence", PUBLISH.lower())
        self.assertNotIn("p50_intelligence", (ROOT / "index.html").read_text(encoding="utf-8").lower())
        self.assertIn("Aucun résultat ne modifie le score officiel ni le classement public.", UI)

    def test_sections_are_limited_to_ten_and_thresholded(self):
        self.assertIn("$item['growthIndex']>=65", CORE)
        self.assertIn("$item['buzzIndex']>=70", CORE)
        self.assertIn("$item['globalVariation']<=-20", CORE)
        self.assertEqual(CORE.count("array_slice($"), 3)


if __name__ == "__main__":
    unittest.main()
