import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class SiteAccessRecoveryV80Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.worker = read("sw.js")
        cls.public_copy = read("public-copy-fixes.js")
        cls.offline = read("offline.html")
        cls.deploy = read(".github/workflows/deploy-ionos.yml")

    def test_service_worker_install_is_fail_soft(self):
        self.assertIn("pass50-v80-site-recovery", self.worker)
        self.assertIn("Promise.allSettled", self.worker)
        self.assertIn("cacheAsset", self.worker)
        self.assertNotIn("cache.addAll(ASSETS)", self.worker)
        self.assertIn("self.skipWaiting()", self.worker)
        self.assertIn("self.clients.claim()", self.worker)

    def test_old_pass50_caches_are_removed(self):
        self.assertIn("key.startsWith('pass50-')&&key!==CACHE", self.worker)
        self.assertIn("PASS50_CLEAR_OLD_CACHES", self.worker)
        self.assertIn("SKIP_WAITING", self.worker)

    def test_navigation_has_an_explicit_offline_recovery(self):
        self.assertIn("./offline.html", self.worker)
        self.assertIn("Connexion au site momentanément indisponible", self.offline)
        self.assertIn("Réessayer maintenant", self.offline)
        self.assertNotIn("app_state", self.offline)

    def test_public_runtime_forces_uncached_v80_registration(self):
        self.assertIn("./sw.js?v=80", self.public_copy)
        self.assertIn("updateViaCache:'none'", self.public_copy)
        self.assertIn("registration.update()", self.public_copy)
        self.assertIn("controllerchange", self.public_copy)
        self.assertIn("pass50-sw-v80-reloaded", self.public_copy)

    def test_deployment_transfers_and_verifies_the_real_version(self):
        self.assertIn("--transfer-all", self.deploy)
        self.assertNotIn("--only-newer", self.deploy)
        self.assertIn("deployment-version.json", self.deploy)
        self.assertIn("pass50-v80-site-recovery", self.deploy)
        self.assertIn("https://pass50.store", self.deploy)
        self.assertIn("https://www.pass50.store", self.deploy)
        self.assertIn(".commit == $sha", self.deploy)

    def test_recovery_does_not_touch_ranking_state(self):
        combined = self.worker + self.public_copy + self.offline + self.deploy
        for token in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state"):
            self.assertNotIn(token, combined)


if __name__ == "__main__":
    unittest.main()
