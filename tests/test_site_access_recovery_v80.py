import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class SiteAccessRecoveryV81Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.worker = read("sw.js")
        cls.public_copy = read("public-copy-fixes.js")
        cls.deploy = read(".github/workflows/deploy-ionos.yml")

    def test_service_worker_is_disabled_without_fetch_interception(self):
        self.assertIn("pass50-v81-service-worker-disabled", self.worker)
        self.assertIn("self.registration.unregister()", self.worker)
        self.assertNotIn("addEventListener('fetch'", self.worker)
        self.assertNotIn("cache.addAll", self.worker)
        self.assertNotIn("Promise.allSettled", self.worker)

    def test_all_pass50_caches_are_removed(self):
        self.assertIn("key.startsWith('pass50-')", self.worker)
        self.assertIn("PASS50_CLEAR_OLD_CACHES", self.worker)
        self.assertIn("caches.delete(key)", self.worker)

    def test_public_runtime_unregisters_without_reload_loop(self):
        self.assertIn("disableServiceWorkers", self.public_copy)
        self.assertIn("navigator.serviceWorker.getRegistrations()", self.public_copy)
        self.assertIn("registration.unregister()", self.public_copy)
        self.assertNotIn("controllerchange", self.public_copy)
        self.assertNotIn("location.reload()", self.public_copy)
        self.assertNotIn("registration.update()", self.public_copy)

    def test_dom_observer_is_throttled(self):
        self.assertIn("requestAnimationFrame", self.public_copy)
        self.assertIn("schedulePublicFixes", self.public_copy)
        self.assertIn("if(scheduled)return", self.public_copy)

    def test_deployment_prioritizes_recovery_files(self):
        self.assertIn("cancel-in-progress: true", self.deploy)
        self.assertIn('put -O "$REMOTE_DIR" .deploy/sw.js', self.deploy)
        self.assertIn('put -O "$REMOTE_DIR" .deploy/public-copy-fixes.js', self.deploy)
        self.assertIn("--only-newer", self.deploy)
        self.assertNotIn("--transfer-all", self.deploy)
        self.assertIn("pass50-v81-service-worker-disabled", self.deploy)
        self.assertIn("deployment-version.json", self.deploy)
        self.assertIn("https://pass50.store", self.deploy)
        self.assertIn("https://www.pass50.store", self.deploy)

    def test_hotfix_does_not_touch_ranking_state(self):
        combined = self.worker + self.public_copy + self.deploy
        for token in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state"):
            self.assertNotIn(token, combined)


if __name__ == "__main__":
    unittest.main()
