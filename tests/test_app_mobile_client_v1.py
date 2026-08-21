#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class AppMobileClientV1Tests(unittest.TestCase):
    def test_app_shell_loads_client_contract(self):
        page = read("app.html")
        client = read("app-client.js")
        self.assertIn("PASS50", page)
        self.assertIn('src="./app-client.js?v=1.1"', page)
        self.assertIn('data-tab="ranking"', page)
        self.assertIn('data-tab="feed"', page)
        self.assertIn('data-tab="live"', page)
        self.assertIn('data-tab="account"', page)
        self.assertIn("PASS50-APP-CLIENT-V1.1", client)
        self.assertIn("isNativeShell", client)
        self.assertIn("public-ranking.php", client)
        self.assertIn("public-feed.php", client)
        self.assertIn("live-status.php?mode=status", client)
        self.assertIn("app-bootstrap.php", client)
        self.assertIn("login.php", client)
        self.assertIn("pass50_api_token", client)
        self.assertNotIn("mode=quick", client)

    def test_manifest_starts_on_app_shell(self):
        manifest = read("manifest.webmanifest")
        self.assertIn("./app.html?source=pwa", manifest)
        self.assertIn('"display": "standalone"', manifest)
        self.assertIn("maskable", manifest)

    def test_bootstrap_points_to_web_app_client(self):
        boot = read("api/app-bootstrap.php")
        self.assertIn("PASS50-APP-CLIENT-V1.1", boot)
        self.assertIn("app.html", boot)
        self.assertIn("'webApp' => 'app.html'", boot)
        self.assertIn("store.pass50.app", boot)

    def test_deploy_and_sw_include_app_client(self):
        deploy = read(".github/workflows/deploy-ionos.yml")
        sw = read("sw.js")
        self.assertIn('put -O ${REMOTE_DIR@Q} .deploy/app-client.js', deploy)
        self.assertIn("./app-client.js?v=1.1", sw)
        self.assertIn("./manifest.webmanifest?v=24.0", sw)
        self.assertIn("shell/", deploy)
        self.assertIn("assetlinks.json", deploy)
        self.assertIn("apple-app-site-association", deploy)


if __name__ == "__main__":
    unittest.main()
