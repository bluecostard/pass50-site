import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/metrics-ranking-publication-preview.yml").read_text(encoding="utf-8")
VALIDATE = (ROOT / ".github/workflows/validate-metrics-ranking-publication-preview.yml").read_text(encoding="utf-8")


class MetricsRankingPublicationPreviewTests(unittest.TestCase):
    def test_workflow_is_manual_or_self_triggered_preview_only(self):
        self.assertIn("name: Metrics Ranking Publication Preview", WORKFLOW)
        self.assertIn("workflow_dispatch:", WORKFLOW)
        self.assertIn("push:", WORKFLOW)
        self.assertIn("branches:\n      - main", WORKFLOW)
        self.assertIn("metrics-ranking-publication-preview.yml", WORKFLOW)
        self.assertIn("group: pass50-metrics-ranking-publication-preview", WORKFLOW)
        self.assertIn("cancel-in-progress: false", WORKFLOW)

    def test_only_probe_and_preview_actions_are_sent(self):
        self.assertIn("'{action:\"probe\",dispatchId:$dispatchId}'", WORKFLOW)
        self.assertIn("'{action:\"preview\",dispatchId:$dispatchId}'", WORKFLOW)
        self.assertNotIn("action:\"apply\"", WORKFLOW)
        self.assertNotIn("confirm:true", WORKFLOW)
        self.assertNotIn("bootstrap:true", WORKFLOW)
        self.assertNotIn("metrics-ranking-publication-apply.yml/dispatches", WORKFLOW)

    def test_permissions_cannot_trigger_other_actions(self):
        permissions = WORKFLOW.split("permissions:", 1)[1].split("concurrency:", 1)[0]
        self.assertIn("contents: read", permissions)
        self.assertIn("statuses: write", permissions)
        self.assertNotIn("actions: write", permissions)

    def test_preview_exposes_bootstrap_and_safety_diagnostics(self):
        for marker in (
            "PUBAPPLY-V1.0",
            "publicationEnabled",
            "automaticPublicationEnabled",
            "bootstrapAllowed",
            "publicationEligible",
            "automaticPublicationEligible",
            "Garde-fous 24H assouplis par bootstrap",
            "Action exécutée : `preview` uniquement",
            "Confirmation envoyée : `non`",
            "Écriture app_state : `0`",
        ):
            self.assertIn(marker, WORKFLOW)
        self.assertIn("publication-probe.json", WORKFLOW)
        self.assertIn("publication-preview.json", WORKFLOW)
        self.assertIn("pass50/publication-bootstrap-preview", WORKFLOW)

    def test_validation_covers_contract_and_diff(self):
        self.assertIn("test_metrics_ranking_publication_preview", VALIDATE)
        self.assertIn("metrics-ranking-publication-preview.yml", VALIDATE)
        self.assertIn("git diff --check", VALIDATE)


if __name__ == "__main__":
    unittest.main()
