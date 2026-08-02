import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MetricsFictiveOperationalV1Tests(unittest.TestCase):
    def test_fictive_ranking_is_admin_only_and_read_only(self):
        php = read("api/metrics-ranking-fictive.php")
        self.assertIn("require_role($user,'owner','admin')", php)
        self.assertIn("'publicPublication'=>false", php)
        self.assertIn("'publicStateWrites'=>0", php)
        self.assertIn("exclusionSummary", php)
        self.assertIn("freshness", php)
        self.assertIn("runUuid", php)
        self.assertNotIn("UPDATE app_state", php)
        self.assertNotIn("INSERT INTO app_state", php)

    def test_fictive_page_is_explicitly_internal_and_linked_from_admin(self):
        html = read("classement-fictif.html")
        loader = read("public-copy-fixes.js")
        self.assertIn("CLASSEMENT FICTIF INTERNE", html)
        self.assertIn("noindex,nofollow", html)
        self.assertIn("pass50_api_token", html)
        self.assertIn("Exclusions (non classables)", html)
        self.assertIn("runUuid", html)
        self.assertIn("admin-fictive-ranking-v1.js?v=1.0", loader)

    def test_operational_credentials_use_non_empty_server_secrets(self):
        php = read("api/metrics-social-collectors-core.php")
        self.assertIn("foreach([$perProfile[$key]??null,$metrics[$key]??null", php)
        self.assertIn("$configured=$secret!==''||(bool)$explicitEnabled", php)
        self.assertIn("PASS50_X_BEARER_TOKEN", php)
        self.assertIn("business_discovery", php)
        self.assertIn("PASS50_TIKTOK_RESEARCH_APPROVED", php)

    def test_readiness_never_exposes_secrets(self):
        php = read("api/metrics-collector-readiness-core.php")
        self.assertIn("'secretsExposed'=>false", php)
        self.assertIn("'publicStateWrites'=>0", php)
        self.assertNotIn("secret'=>", php)

    def test_deployment_waits_for_new_baseline_contract(self):
        core = read("api/metrics-public-baseline-core.php")
        workflow = read(".github/workflows/metrics-public-baseline-p1.yml")
        self.assertIn("PUBLIC-BASELINE-P1-V1.2", core)
        self.assertGreaterEqual(workflow.count("PUBLIC-BASELINE-P1-V1.2"), 3)
        self.assertIn("api/metrics-social-collectors-core.php", workflow)


if __name__ == "__main__":
    unittest.main()
