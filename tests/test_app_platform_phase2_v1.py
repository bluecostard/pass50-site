#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class AppPlatformPhase2Tests(unittest.TestCase):
    def test_cors_is_global_on_api_bootstrap(self):
        boot = read("api/bootstrap.php")
        self.assertIn("Access-Control-Allow-Origin: *", boot)
        self.assertIn("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With", boot)

    def test_app_bootstrap_contract(self):
        endpoint = read("api/app-bootstrap.php")
        self.assertIn("PASS50-APP-PLATFORM-V1", endpoint)
        self.assertIn("public-ranking.php", endpoint)
        self.assertIn("public-feed.php", endpoint)
        self.assertIn("live-status.php?mode=status", endpoint)
        self.assertIn("user_payload($user)", endpoint)

    def test_public_feed_alias_and_contract(self):
        alias = read("api/public-feed.php")
        feed = read("api/content-feed.php")
        self.assertIn("PASS50-PUBLIC-FEED-V1", alias)
        self.assertIn("content-feed.php", alias)
        self.assertIn("PASS50-PUBLIC-FEED-V1", feed)

    def test_live_public_paths_are_cache_only(self):
        home = read("index.html")
        feed = read("mon-fil.js")
        radar = read("live-radar-v3.js")
        legacy = read("api/live-status.php")
        self.assertIn("live-status.php?mode=status", home)
        self.assertIn("live-status.php?mode=status", feed)
        self.assertIn("mode:'status'", radar)
        self.assertIn("mode'] = 'status'", legacy)
        self.assertIn("mode=quick&force=1", home)

    def test_pwa_shell_assets(self):
        manifest = read("manifest.webmanifest")
        sw = read("sw.js")
        app = read("app.html")
        copy = read("public-copy-fixes.js")
        self.assertIn('"display": "standalone"', manifest)
        self.assertIn("maskable", manifest)
        self.assertIn("PASS50-APP-SHELL-SW-V1", sw)
        self.assertNotIn("self.registration.unregister()", sw)
        self.assertIn("app-bootstrap.php", app)
        self.assertIn("reconcileServiceWorkers", copy)
        self.assertNotIn("disableServiceWorkers", copy)

    def test_desktop_surfaces_still_linked(self):
        app = read("app.html")
        for href in ("./", "./mon-fil.html", "./pronostics.html", "./?open=account"):
            self.assertIn(href, app)


if __name__ == "__main__":
    unittest.main()
