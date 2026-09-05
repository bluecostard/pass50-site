#!/usr/bin/env python3
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOBILE = ROOT / "apps" / "mobile"
SHELL = ROOT / "shell"


class Pass50MobileAppV1Tests(unittest.TestCase):
    def test_native_shell_loads_site_mobile_client(self):
        index = (MOBILE / "app/index.tsx").read_text(encoding="utf-8")
        url_mod = (MOBILE / "src/shell/url.ts").read_text(encoding="utf-8")
        layout = (MOBILE / "app/_layout.tsx").read_text(encoding="utf-8")
        package = json.loads((MOBILE / "package.json").read_text(encoding="utf-8"))

        self.assertIn("react-native-webview", package["dependencies"])
        self.assertIn("PASS50_NATIVE_APP_URL", index)
        self.assertIn("from 'react-native-webview'", index)
        self.assertIn("https://pass50.store/app.html?source=native", url_mod)
        self.assertIn('name="index"', layout)
        self.assertNotIn("hasCompletedOnboarding", index)
        self.assertNotIn("/(tabs)", index)

    def test_shell_url_matches_capacitor_doctrine(self):
        url_mod = (MOBILE / "src/shell/url.ts").read_text(encoding="utf-8")
        cap = json.loads((SHELL / "capacitor.config.json").read_text(encoding="utf-8"))
        self.assertEqual(
            cap["server"]["url"],
            "https://pass50.store/app.html?source=native",
        )
        self.assertIn(cap["server"]["url"], url_mod)

    def test_app_json_branding_and_app_url(self):
        app = json.loads((MOBILE / "app.json").read_text(encoding="utf-8"))
        expo = app["expo"]
        self.assertEqual(expo["name"], "PASS50")
        self.assertEqual(expo["scheme"], "pass50")
        self.assertIn("#050705", (MOBILE / "app.json").read_text(encoding="utf-8"))
        self.assertEqual(
            expo["extra"]["appUrl"],
            "https://pass50.store/app.html?source=native",
        )
        self.assertEqual(expo["ios"]["bundleIdentifier"], "store.pass50.app")

    def test_external_hosts_open_in_browser(self):
        index = (MOBILE / "app/index.tsx").read_text(encoding="utf-8")
        url_mod = (MOBILE / "src/shell/url.ts").read_text(encoding="utf-8")
        self.assertIn("onShouldStartLoadWithRequest", index)
        self.assertIn("openBrowserAsync", index)
        self.assertIn("isPass50Host", index)
        self.assertIn("pass50.store", url_mod)
        self.assertIn("www.pass50.store", url_mod)


if __name__ == "__main__":
    unittest.main()
