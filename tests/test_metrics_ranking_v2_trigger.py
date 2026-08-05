import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/metrics-ranking-v2-trigger.yml").read_text(encoding="utf-8")


class MetricsRankingV2TriggerTests(unittest.TestCase):
    def test_trigger_waits_for_both_versioned_files(self):
        self.assertIn("name: Metrics Ranking V2 Trigger", WORKFLOW)
        self.assertIn("workflow_dispatch:", WORKFLOW)
        self.assertIn("push:", WORKFLOW)
        self.assertIn("branches:\n      - main", WORKFLOW)
        self.assertIn("metrics-ranking-fresh-capture-v2-core.php", WORKFLOW)
        self.assertIn("metrics-ranking-cron-v2.php", WORKFLOW)
        self.assertIn("MR-FRESH-CAPTURE-V2.0", WORKFLOW)
        self.assertIn("METRICS-RANKING-CRON-V2.0", WORKFLOW)
        self.assertIn('[ "$gate_code" = "200" ]', WORKFLOW)
        self.assertIn('[ "$endpoint_code" = "405" ]', WORKFLOW)

    def test_trigger_does_not_depend_on_an_exact_deployment_sha(self):
        self.assertNotIn("deployment-version.json", WORKFLOW)
        self.assertNotIn(".commit == $sha", WORKFLOW)
        self.assertNotIn("GITHUB_SHA", WORKFLOW)
        self.assertIn("cancel-in-progress: false", WORKFLOW)

    def test_trigger_dispatches_the_existing_protected_workflow(self):
        self.assertIn("actions: write", WORKFLOW)
        self.assertIn("metrics-ranking-experimental.yml/dispatches", WORKFLOW)
        self.assertIn("jq -nc --arg ref main", WORKFLOW)
        self.assertIn("Authorization: Bearer $GH_TOKEN", WORKFLOW)
        self.assertIn("X-GitHub-Api-Version: 2022-11-28", WORKFLOW)

    def test_trigger_has_no_ranking_or_public_state_write(self):
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "p50_mr_calculate",
            "metrics-ranking-publication-apply",
        ):
            self.assertNotIn(forbidden, WORKFLOW)
        self.assertIn("Écriture app_state : `0`", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
