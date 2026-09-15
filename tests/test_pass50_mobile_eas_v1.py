#!/usr/bin/env python3
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOBILE = ROOT / "apps" / "mobile"


class Pass50MobileEasV1Tests(unittest.TestCase):
    def test_eas_json_has_store_profiles(self):
        eas = json.loads((MOBILE / "eas.json").read_text(encoding="utf-8"))
        self.assertIn("preview", eas["build"])
        self.assertIn("production", eas["build"])
        self.assertEqual(eas["build"]["preview"]["android"]["buildType"], "apk")
        self.assertFalse(eas["build"]["preview"]["ios"]["simulator"])
        self.assertTrue(eas["build"]["production"]["autoIncrement"])
        self.assertIn("EXPO_PUBLIC_API_BASE", eas["build"]["production"]["env"])

    def test_app_json_matches_capacitor_bundle_id(self):
        app = json.loads((MOBILE / "app.json").read_text(encoding="utf-8"))
        expo = app["expo"]
        self.assertEqual(expo["ios"]["bundleIdentifier"], "store.pass50.app")
        self.assertEqual(expo["android"]["package"], "store.pass50.app")
        self.assertIn("associatedDomains", expo["ios"])
        self.assertIn("intentFilters", expo["android"])

    def test_shell_readme_points_to_expo_successor(self):
        readme = (ROOT / "shell" / "README.md").read_text(encoding="utf-8")
        self.assertIn("apps/mobile", readme)

    def test_mobile_readme_documents_eas(self):
        readme = (MOBILE / "README.md").read_text(encoding="utf-8")
        self.assertIn("npm run eas:preview:android", readme)
        self.assertIn("eas:preview:ios", readme)
        self.assertIn("Expo Go", readme)
        self.assertIn("npx eas", readme)
        self.assertIn("store.pass50.app", readme)
        self.assertIn("eas:init", readme)


if __name__ == "__main__":
    unittest.main()
