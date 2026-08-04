import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
APPLY = (ROOT / ".github" / "workflows" / "metrics-ranking-publication-apply.yml").read_text(encoding="utf-8")
SIMULATION = (ROOT / ".github" / "workflows" / "metrics-ranking-publication-simulation.yml").read_text(encoding="utf-8")
ENDPOINT = (ROOT / "api" / "metrics-ranking-publication-apply-cron.php").read_text(encoding="utf-8")


class MetricsRankingPublicationApplyScheduleGateV1Tests(unittest.TestCase):
    def test_apply_has_no_independent_schedule(self):
        self.assertIn("workflow_dispatch:", APPLY)
        self.assertNotIn("schedule:", APPLY)
        self.assertNotIn("cron:", APPLY)
        self.assertIn("aucun cron autonome", APPLY)

    def test_simulation_is_the_only_automatic_dispatch_gate(self):
        self.assertIn("automatic_eligible == 'true'", SIMULATION)
        self.assertIn("sim_status != 'blocked'", SIMULATION)
        self.assertIn("metrics-ranking-publication-apply.yml/dispatches", SIMULATION)

    def test_apply_transport_is_observable_and_long_enough(self):
        self.assertIn("--max-time 180", APPLY)
        self.assertIn("set +e", APPLY)
        self.assertIn("curl_status=$?", APPLY)
        self.assertIn("Transport interrompu pendant la publication", APPLY)
        self.assertIn("jq -e '.ok == true'", APPLY)

    def test_gate_or_empty_result_remains_a_soft_skip(self):
        self.assertIn("Garde-fous", ENDPOINT)
        self.assertIn("Aucune mutation", ENDPOINT)
        self.assertIn("'skipped'=>true", ENDPOINT)
        self.assertIn("'publicStateWrites'=>0", ENDPOINT)

    def test_apply_does_not_write_public_state_directly(self):
        for token in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state"):
            self.assertNotIn(token, APPLY)


if __name__ == "__main__":
    unittest.main()
