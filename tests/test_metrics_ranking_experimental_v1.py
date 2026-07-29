from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
CORE = (ROOT / 'api' / 'metrics-ranking-core.php').read_text(encoding='utf-8')
API = (ROOT / 'api' / 'metrics-ranking.php').read_text(encoding='utf-8')
CRON = (ROOT / 'api' / 'metrics-ranking-cron.php').read_text(encoding='utf-8')
CAL_CORE = (ROOT / 'api' / 'metrics-ranking-calibration-core.php').read_text(encoding='utf-8')
CAL_API = (ROOT / 'api' / 'metrics-ranking-calibration.php').read_text(encoding='utf-8')
TOOLS = (ROOT / 'v9-tools.js').read_text(encoding='utf-8')
SW = (ROOT / 'sw.js').read_text(encoding='utf-8')
WORKFLOW = (ROOT / '.github' / 'workflows' / 'validate-metrics-ranking-experimental.yml').read_text(encoding='utf-8')


class MetricsRankingExperimentalV1Tests(unittest.TestCase):
    def test_endpoints_are_opt_in_and_admin_only(self):
        for source in (API, CRON, CAL_API):
            self.assertIn("require_method(", source)
        self.assertIn("require_role($user,'admin')", API)
        self.assertIn("require_role($user,'admin')", CAL_API)
        self.assertIn("p50mr_require_cron_secret", CRON)

    def test_ranking_does_not_write_public_state(self):
        for source in (CORE, API, CRON, CAL_CORE, CAL_API):
            self.assertNotIn("p50_de_save_public_state", source)
            self.assertNotIn("UPDATE profiles", source)
            self.assertNotIn("INSERT INTO profiles", source)

    def test_ranking_has_separate_schema_and_locking(self):
        self.assertIn("p50_metrics_ranking_runs", CORE)
        self.assertIn("p50_metrics_ranking_rows", CORE)
        self.assertIn("p50_metrics_ranking_calibrations", CAL_CORE)
        self.assertIn("GET_LOCK", CORE)
        self.assertIn("RELEASE_LOCK", CORE)

    def test_score_is_bounded_and_coverage_guarded(self):
        self.assertIn("min(100.0,max(0.0", CORE)
        self.assertIn("coverage", CORE)
        self.assertIn("insufficient_coverage", CORE)
        self.assertIn("minimum_coverage", CORE)

    def test_ranking_is_experimental_in_ui(self):
        self.assertIn("CLASSEMENT EXPÉRIMENTAL", TOOLS)
        self.assertIn("metrics-ranking.php", TOOLS)
        self.assertIn("metrics-ranking-calibration.php", TOOLS)
        self.assertIn("data-p50-ranking-experimental", TOOLS)

    def test_no_social_secret_is_exposed_client_side(self):
        for secret in ("YOUTUBE_API_KEY", "TIKTOK_ACCESS_TOKEN", "INSTAGRAM_ACCESS_TOKEN", "FACEBOOK_ACCESS_TOKEN"):
            self.assertNotIn(secret, TOOLS)

    def test_calibration_uses_multiple_windows_and_guardrails(self):
        self.assertIn("['2H','24H','48H','7J','15J']", CAL_CORE)
        self.assertIn("min_samples", CAL_CORE)
        self.assertIn("max_delta", CAL_CORE)
        self.assertIn("rollback", CAL_CORE.lower())

    def test_calibration_is_versioned_and_not_automatic(self):
        self.assertIn("version", CAL_CORE)
        self.assertIn("candidate", CAL_CORE)
        self.assertIn("activate", CAL_API)
        self.assertNotIn("setInterval", CAL_API)

    def test_ranking_history_and_read_paths_are_bounded(self):
        read = self._function("p50mr_read_runs")
        self.assertIn("SELECT COUNT(*) total_count", read)
        self.assertIn("AVG(coverage)", read)
        self.assertIn("SELECT exclusion_reasons_json", read)
        self.assertLess(read.index("SELECT COUNT(*) total_count"), read.index("LIMIT ?"))
        self.assertIn("$limit=max(1,min(200,$limit))", read)

    def test_cache_and_workflow_versions(self):
        self.assertIn("data-engine-ui.js?v=18.0", TOOLS)
        self.assertIn("data-engine-ui.css?v=27.0", TOOLS)
        self.assertIn("data-engine-ui.js?v=18.0", SW)
        self.assertIn("data-engine-ui.css?v=27.0", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")
        self.assertIn("mariadb:11.4", WORKFLOW)
        for variable in ("P50_TEST_DSN", "P50_TEST_DB_USER", "P50_TEST_DB_PASSWORD"):
            self.assertIn(variable, WORKFLOW)
        self.assertNotIn("PASS50_TEST_", WORKFLOW)

    @staticmethod
    def _function(name):
        start = CORE.index(f"function {name}(")
        next_match = re.search(r"\nfunction [a-zA-Z0-9_]+\(", CORE[start + 1:])
        return CORE[start:] if next_match is None else CORE[start:start + 1 + next_match.start()]


if __name__ == '__main__':
    unittest.main()
