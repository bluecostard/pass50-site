import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / "api" / "metrics-ranking-publication-core.php").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api" / "metrics-ranking-publication-simulate.php").read_text(encoding="utf-8")


def function(source, name):
    start = source.index(f"function {name}(")
    following = re.search(r"\nfunction [A-Za-z0-9_]+\(", source[start + 1 :])
    return source[start:] if following is None else source[start : start + 1 + following.start()]


class MetricsRankingPublicationSimulationV1Tests(unittest.TestCase):
    def test_endpoint_is_admin_get_only(self):
        self.assertIn("require_method('GET')", ENDPOINT)
        self.assertIn("require_role($user,'owner','admin')", ENDPOINT)
        self.assertIn("p50_mrp_simulate", ENDPOINT)

    def test_simulation_has_no_public_write_path(self):
        combined = CORE + ENDPOINT
        forbidden = (
            "INSERT INTO app_state",
            "UPDATE app_state",
            "DELETE FROM app_state",
            "FOR UPDATE",
            "p50_de_save_public_state",
            "p50_de_publish_score_pipeline",
            "data-publish.php",
        )
        for value in forbidden:
            self.assertNotIn(value, combined)
        self.assertIn("'publicationEnabled'=>false", CORE)
        self.assertIn("'automaticPublicationEnabled'=>false", CORE)
        self.assertIn("'appStateWriteAttempted'=>false", CORE)
        self.assertIn("'publicStateWrites'=>0", CORE)

    def test_comparison_reports_all_movement_types(self):
        compare = function(CORE, "p50_mrp_compare")
        for movement in ("entry", "exit", "up", "down", "stable"):
            self.assertIn(f"'{movement}'", compare)
        for field in (
            "publicRank",
            "candidateRank",
            "rankDelta",
            "publicScore",
            "candidateScore",
            "scoreDelta",
            "top10Retention",
            "top50Retention",
            "spearman",
        ):
            self.assertIn(f"'{field}'", compare)

    def test_fingerprints_bind_public_revision_and_experimental_run(self):
        simulate = function(CORE, "p50_mrp_simulate")
        self.assertIn("'stateRevision'", simulate)
        self.assertIn("'runUuid'", simulate)
        self.assertIn("'publicFingerprint'", simulate)
        self.assertIn("'candidateFingerprint'", simulate)
        self.assertIn("p50_mrp_canonicalize", CORE)
        self.assertIn("JSON_THROW_ON_ERROR", CORE)

    def test_guardrails_block_invalid_or_stale_candidates(self):
        simulate = function(CORE, "p50_mrp_simulate")
        for gate in (
            "public_state",
            "public_profile_ids",
            "experimental_profile_ids",
            "experimental_ranks",
            "successful_run",
            "candidate_run_consistency",
            "public_ranking_non_empty",
            "candidate_non_empty",
            "candidate_profiles_exist",
            "run_freshness",
            "exit_ratio",
            "entry_ratio",
            "maximum_rank_movement",
        ):
            self.assertIn(f"'{gate}'", simulate)
        self.assertIn("P50_MRP_MAX_RUN_AGE_HOURS=6", CORE)
        self.assertIn("$experimental['runUuids'][0]===($latestRun['runUuid']??null)", simulate)
        self.assertIn("$blocked?'blocked':($warnings?'review':'ready')", simulate)

    def test_candidate_uses_only_classable_experimental_rows(self):
        compare = function(CORE, "p50_mrp_compare")
        self.assertIn("!empty($row['classable'])", compare)
        self.assertIn("$row['rank']!==null", compare)
        self.assertIn("$row['score']!==null", compare)
        self.assertIn("candidateDerivedOnlyFromClassableExperimentalRows", CORE)

    def test_period_and_output_are_bounded(self):
        simulate = function(CORE, "p50_mrp_simulate")
        self.assertIn("$limit=max(1,min(500,$limit))", simulate)
        self.assertIn("array_slice($comparison['movements'],0,$limit)", simulate)
        self.assertIn("p50_mrp_period($period)", simulate)


if __name__ == "__main__":
    unittest.main()
