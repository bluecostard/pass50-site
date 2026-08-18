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


if __name__ == "__main__":
    unittest.main()
