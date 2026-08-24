import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MetricsRankingPublicationPostCalcV1Tests(unittest.TestCase):
    """Publication refusée après calcul : runUuid divergents, périodes sans public, cache périmé."""

    def test_apply_resolves_conflicting_run_uuids_instead_of_silent_block(self):
        core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn("function p50_mrp_apply_resolve_publish_plans", core)
        self.assertIn("run_uuid_mismatch", core)
        self.assertIn("global:run_uuid_mismatch", core)
        self.assertIn("global:no_mutations", core)
        self.assertNotIn("elseif($runUuid!==$planRun)$blocked=true", core)

    def test_empty_public_period_is_skippable(self):
        core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn(
            "'candidate_non_empty','successful_run','public_ranking_non_empty','exit_ratio'",
            core,
        )

    def test_calculate_clears_stale_preview_cache(self):
        ranking = read("api/metrics-ranking.php")
        cron = read("api/metrics-ranking-cron.php")
        core = read("api/metrics-ranking-publication-apply-core.php")
        self.assertIn("p50_mrp_apply_clear_preview_cache", core)
        self.assertIn("p50_mrp_apply_clear_preview_cache($pdo)", ranking)
        self.assertIn("p50_mrp_apply_clear_preview_cache($pdo)", cron)

    def test_successful_run_gate_uses_current_algorithm_version(self):
        core = read("api/metrics-ranking-publication-core.php")
        self.assertIn("'Un cycle '.P50_MR_ALGORITHM_VERSION.' réussi couvre la période.'", core)

    def test_admin_publish_recovers_from_run_uuid_mismatch(self):
        ui = read("data-engine-ui.js")
        self.assertIn("run_uuid_mismatch", ui)
        self.assertIn("no_mutations", ui)
        self.assertIn("dePublishNeedsRecalculate", ui)
        self.assertIn("'run_uuid_mismatch'", ui)


if __name__ == "__main__":
    unittest.main()
