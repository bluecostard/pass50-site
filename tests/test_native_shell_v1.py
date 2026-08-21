#!/usr/bin/env python3
"""Coque Capacitor iOS/Android autour de /app.html."""
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class NativeShellV1Tests(unittest.TestCase):
    def test_capacitor_points_to_production_app(self):
        cfg = json.loads(read("shell/capacitor.config.json"))
        pkg = json.loads(read("shell/package.json"))
        www = read("shell/www/index.html")
        self.assertEqual(cfg["appId"], "store.pass50.app")
        self.assertEqual(cfg["appName"], "PASS50")
        self.assertIn("pass50.store/app.html?source=native", cfg["server"]["url"])
        self.assertIn("pass50.store", cfg["server"]["allowNavigation"])
        self.assertIn("@capacitor/core", pkg["dependencies"])
        self.assertIn("@capacitor/android", pkg["dependencies"])
        self.assertIn("@capacitor/ios", pkg["dependencies"])
        self.assertIn("PASS50-NATIVE-SHELL-V1", www)
        self.assertIn("pass50-native-shell", pkg["name"])

    def test_well_known_deep_link_placeholders(self):
        assetlinks = json.loads(read(".well-known/assetlinks.json"))
        aasa = json.loads(read(".well-known/apple-app-site-association"))
        self.assertEqual(assetlinks[0]["target"]["package_name"], "store.pass50.app")
        self.assertIn("REPLACE_WITH_PLAY_APP_SIGNING_SHA256", assetlinks[0]["target"]["sha256_cert_fingerprints"])
        self.assertIn("TEAMID.store.pass50.app", aasa["applinks"]["details"][0]["appID"])
        self.assertIn("/app.html", aasa["applinks"]["details"][0]["paths"])

    def test_htaccess_and_deploy_wire_well_known(self):
        htaccess = read(".htaccess")
        deploy = read(".github/workflows/deploy-ionos.yml")
        self.assertIn('Files "apple-app-site-association"', htaccess)
        self.assertIn('Files "assetlinks.json"', htaccess)
        self.assertIn("--exclude 'shell/'", deploy)
        self.assertIn("put -O ${REMOTE_DIR@Q}/.well-known .deploy/.well-known/assetlinks.json", deploy)
        self.assertIn("apple-app-site-association", deploy)

    def test_readme_documents_store_flow(self):
        readme = read("shell/README.md")
        self.assertIn("npm run add:android", readme)
        self.assertIn("npm run add:ios", readme)
        self.assertIn("store.pass50.app", readme)
        self.assertIn("App Store", readme)


if __name__ == "__main__":
    unittest.main()
