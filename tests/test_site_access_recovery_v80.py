import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class SiteAccessRecoveryV82Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.worker = read("sw.js")
        cls.public_copy = read("public-copy-fixes.js")
        cls.deploy = read(".github/workflows/deploy-ionos.yml")
        cls.htaccess = read(".htaccess")

    def test_service_worker_stays_disabled(self):
        self.assertIn("pass50-v81-service-worker-disabled", self.worker)
        self.assertIn("self.registration.unregister()", self.worker)
        self.assertNotIn("addEventListener('fetch'", self.worker)

    def test_public_runtime_uses_only_share_v2(self):
        self.assertIn("PASS50-PUBLIC-RUNTIME-V82", self.public_copy)
        self.assertIn("context-share-v2.js?v=2.1", self.public_copy)
        self.assertIn("dataset.pass50ContextShareV2", self.public_copy)
        self.assertIn("LEGACY_CONTEXT_SHARE_DISABLED='./context-share-v1.js?v=1.0'", self.public_copy)
        self.assertNotIn("loadScript('script[data-pass50-context-share]','./context-share-v1.js", self.public_copy)

    def test_public_runtime_has_no_global_dom_observer_loop(self):
        self.assertNotIn("new MutationObserver", self.public_copy)
        self.assertNotIn("controllerchange", self.public_copy)
        self.assertNotIn("location.reload()", self.public_copy)
        self.assertIn("setTimeout(runPublicFixes,250)", self.public_copy)
        self.assertIn("setTimeout(runPublicFixes,1200)", self.public_copy)

    def test_http_cache_is_disabled_for_interface_assets(self):
        self.assertIn("Cache-Control \"no-store, no-cache, must-revalidate, max-age=0\"", self.htaccess)
        self.assertIn("FilesMatch", self.htaccess)
        self.assertIn("DirectoryIndex index.html", self.htaccess)

    def test_deployment_forces_current_interface_files(self):
        for path in (
            ".deploy/.htaccess",
            ".deploy/index.html",
            ".deploy/app-config.js",
            ".deploy/public-copy-fixes.js",
            ".deploy/context-share-v2.js",
            ".deploy/mobile-bottom-nav-v1.js",
            ".deploy/v9-tools.js",
            ".deploy/v9-tools.css",
        ):
            self.assertIn(f'put -O "$REMOTE_DIR" {path}', self.deploy)
        self.assertIn("v82-share-v2-only", self.deploy)
        self.assertIn("PASS50-PUBLIC-RUNTIME-V82", self.deploy)
        self.assertIn("context-share-v2.js?v=2.1", self.deploy)
        self.assertIn("--only-newer", self.deploy)
        self.assertNotIn("--transfer-all", self.deploy)

    def test_hotfix_does_not_touch_ranking_state(self):
        combined = self.worker + self.public_copy + self.deploy + self.htaccess
        for token in ("UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state"):
            self.assertNotIn(token, combined)


if __name__ == "__main__":
    unittest.main()
