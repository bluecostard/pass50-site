import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
OBS = (ROOT / "api/metrics-observability-core.php").read_text(encoding="utf-8")
ORCH = (ROOT / "api/metrics-orchestrator-core.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "api/config.example.php").read_text(encoding="utf-8")
MR = (ROOT / "api/metrics-ranking-core.php").read_text(encoding="utf-8")


class Classability15cThresholdsV1Tests(unittest.TestCase):
    def test_minimum_criteria_is_four(self):
        self.assertIn("const P50_DE_CLASSABLE_MIN_CRITERIA = 4;", CORE)
        self.assertIn("function p50_de_is_trend_classable", CORE)
        self.assertNotIn("const P50_DE_CLASSABLE_MIN_CRITERIA = 3;", CORE)
        self.assertNotIn("$w['classable']=$w['confidence']>=65&&$w['coverage']>=60&&$w['measuredCriteria']>=6", CORE)

    def test_observability_matches_publish_gate(self):
        self.assertIn("$confidence>=40&&$coverage>=25&&$criteria>=4", OBS)
        self.assertIn("fewerThanMinCriteria", OBS)
        self.assertNotIn("fewerThanSixCriteria", OBS)

    def test_p1_covers_full_census(self):
        self.assertIn("'p1_max_rank']??200)", ORCH)
        self.assertIn("'p1_max_profiles']??200)", ORCH)
        self.assertIn("'p1_max_rank' => 200", CONFIG)

    def test_published_score_restores_classability(self):
        self.assertIn("function p50_de_profile_has_published_score", CORE)
        self.assertIn("function p50_de_restore_scored_classability", CORE)
        self.assertIn("p50_de_restore_scored_classability($data)", (ROOT / "api/state.php").read_text(encoding="utf-8"))

    def test_audience_weight_moved_to_velocity(self):
        self.assertIn("'audience'=>0.05", MR)
        self.assertIn("'velocity'=>0.18", MR)
        self.assertNotIn("'audience'=>0.07", MR)


if __name__ == "__main__":
    unittest.main()
