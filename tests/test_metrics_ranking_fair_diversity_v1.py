import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ORCH = (ROOT / "api/metrics-orchestrator-core.php").read_text(encoding="utf-8")
RANK = (ROOT / "api/metrics-ranking-core.php").read_text(encoding="utf-8")
APPLY = (ROOT / "api/metrics-ranking-publication-apply-core.php").read_text(encoding="utf-8")
CONFIG = (ROOT / "api/config.example.php").read_text(encoding="utf-8")


class MetricsRankingFairDiversityTests(unittest.TestCase):
    def test_p0_fills_with_fair_rotation_not_fixed_priority(self):
        self.assertIn("function p50_mo_fair_rotation_profiles", ORCH)
        self.assertIn("p50_mo_fair_rotation_profiles($pdo,$fairMax,$reserved)", ORCH)
        self.assertIn("'p0UsePriorityIds'", ORCH)
        self.assertIn("p0_max_profiles']??40", ORCH)
        self.assertIn("'fairRotationEnabled'", ORCH)
        self.assertNotIn("p50_mo_viral_profiles($pdo),$cfg['priorityIds']", ORCH)

    def test_p1_reserves_exploration_outside_top_rank(self):
        self.assertIn("function p50_mo_exploration_profiles", ORCH)
        self.assertIn("p1ExplorationRatio", ORCH)
        self.assertIn("p50_mo_exploration_profiles($pdo,$exploreMax,$cfg['p1Rank'],$topIds)", ORCH)
        self.assertIn("last_capture IS NULL DESC,last_capture ASC", ORCH)

    def test_global_percentile_when_pool_is_small(self):
        self.assertIn("P50_MR_MIN_PERCENTILE_POOL=20", RANK)
        self.assertIn("function p50_mr_assign_feature_percentiles", RANK)
        self.assertIn("p50_mr_assign_feature_percentiles($raw,$weights)", RANK)
        self.assertIn("if(count($values)<$minPool)", RANK)
        self.assertIn("P50_MR_ALGORITHM_VERSION='MR-V1.5'", RANK)

    def test_publication_primary_period_matches_default_ui(self):
        self.assertIn("$primaryPeriod='24H'", APPLY)

    def test_config_documents_fair_rotation(self):
        self.assertIn("'p1_exploration_ratio' => 0.25", CONFIG)
        self.assertIn("'fair_rotation_enabled' => true", CONFIG)
        self.assertIn("'p0_use_priority_ids' => false", CONFIG)
        self.assertIn("'p0_max_profiles' => 40", CONFIG)


if __name__ == "__main__":
    unittest.main()
