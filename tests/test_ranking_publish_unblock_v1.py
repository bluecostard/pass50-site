import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WATCHDOG = (ROOT / ".github/workflows/metrics-ranking-publication-watchdog.yml").read_text(encoding="utf-8")
EXPERIMENTAL = (ROOT / ".github/workflows/metrics-ranking-experimental.yml").read_text(encoding="utf-8")
SIMULATION = (ROOT / ".github/workflows/metrics-ranking-publication-simulation.yml").read_text(encoding="utf-8")


class RankingPublishUnblockV1Tests(unittest.TestCase):
    def test_watchdog_publishes_newer_run_before_stale_window(self):
        self.assertIn('"$candidate_run" != "$latest_run"', WATCHDOG)
        self.assertIn("publicFingerprint != .value.candidateFingerprint", WATCHDOG)
        self.assertIn('if [ "$newer_candidate" != "true" ] && [ "$stale" != "true" ]', WATCHDOG)
        self.assertNotIn(
            'if [ "$stale" != "true" ]; then\n            echo "::notice::Classement frais',
            WATCHDOG,
        )

    def test_experimental_and_simulation_wait_for_slow_ionos(self):
        self.assertIn("--max-time 180", EXPERIMENTAL)
        self.assertIn("--max-time 180", SIMULATION)
        self.assertNotIn("--max-time 55", EXPERIMENTAL)
        self.assertNotIn("--max-time 55", SIMULATION)

    def test_watchdog_runs_on_its_own_main_push(self):
        self.assertIn("push:", WATCHDOG)
        self.assertIn("metrics-ranking-publication-watchdog.yml", WATCHDOG)

    def test_watchdog_forces_recalculate_when_experimental_data_missing(self):
        self.assertIn("metrics-ranking-cron-v2.php", WATCHDOG)
        self.assertIn("force:true", WATCHDOG)
        self.assertIn("recalcul forcé MR-V1.0", WATCHDOG)

    def test_watchdog_retries_preview_and_apply_on_ionos_flakes(self):
        self.assertIn("signed_post_retry", WATCHDOG)
        self.assertIn("max_time=\"${4:-180}\"", WATCHDOG)
        self.assertIn('signed_post_retry "$APPLY_URL" "$preview_body" watchdog-preview.json 240 3 preview', WATCHDOG)
        self.assertIn('signed_post_retry "$APPLY_URL" "$apply_body" watchdog-apply.json 300 4 apply', WATCHDOG)
        self.assertIn('signed_post_retry "$RANKING_URL" "$rank_body" watchdog-ranking-force.json 300 3 ranking-force', WATCHDOG)
        self.assertIn('echo "::warning::$label tentative $attempt/$attempts', WATCHDOG)
        self.assertIn(">&2", WATCHDOG)
        self.assertIn("apply_ok=false", WATCHDOG)


if __name__ == "__main__":
    unittest.main()
