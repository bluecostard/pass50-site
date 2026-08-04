import pathlib, unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/control-instagram-collector.yml").read_text()


class InstagramCollectorControlV1Tests(unittest.TestCase):
    def test_manual_pause_and_resume_are_explicit(self):
        self.assertIn("workflow_dispatch", WORKFLOW)
        self.assertIn("- pause", WORKFLOW)
        self.assertIn("- resume", WORKFLOW)
        self.assertIn("REQUESTED_ACTION", WORKFLOW)

    def test_pause_removes_only_server_side_instagram_credentials(self):
        self.assertIn("'instagram_enabled','false'", WORKFLOW)
        self.assertIn("'instagram_access_token',php_string('')", WORKFLOW)
        self.assertIn("'instagram_account_id',php_string('')", WORKFLOW)
        self.assertIn("'instagram_discovery_account_id',php_string('')", WORKFLOW)
        self.assertIn("suspended_pending_business_verification", WORKFLOW)
        self.assertIn("credentialsRetainedInGitHub':True", WORKFLOW)

    def test_resume_uses_existing_github_secrets(self):
        for secret in (
            "PASS50_INSTAGRAM_ACCESS_TOKEN",
            "PASS50_INSTAGRAM_ACCOUNT_ID",
            "PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID",
        ):
            self.assertIn(secret, WORKFLOW)
        self.assertIn("'instagram_enabled','true'", WORKFLOW)
        self.assertIn("business_discovery", WORKFLOW)
        self.assertIn("professional_authorized", WORKFLOW)

    def test_backup_probe_and_public_state_safety(self):
        self.assertIn("config.php.bak-instagram-control-", WORKFLOW)
        self.assertIn("metrics-collector-readiness-cron.php", WORKFLOW)
        self.assertIn("candidatesByPlatform.Instagram", WORKFLOW)
        self.assertIn("publicStateWrites == 0", WORKFLOW)
        self.assertIn("'publicStateWrites':0", WORKFLOW)
        self.assertNotIn("UPDATE app_state", WORKFLOW)
        self.assertNotIn("INSERT INTO app_state", WORKFLOW)
        self.assertNotIn("cat /tmp/p50-instagram-control/config.php", WORKFLOW)


if __name__ == "__main__":
    unittest.main()
