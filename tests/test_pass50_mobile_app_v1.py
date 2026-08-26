#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOBILE = ROOT / "apps" / "mobile"


class Pass50MobileAppV1Tests(unittest.TestCase):
    def test_expo_app_has_four_pass50_tabs(self):
        layout = (MOBILE / "app/(tabs)/_layout.tsx").read_text(encoding="utf-8")
        for tab in ("index", "feed", "live", "profile"):
            self.assertIn(f'name="{tab}"', layout)
        self.assertIn("Classement", layout)
        self.assertIn("Fil", layout)
        self.assertIn("Live", layout)
        self.assertIn("Compte", layout)

    def test_api_client_targets_pass50_store(self):
        client = (MOBILE / "src/api/client.ts").read_text(encoding="utf-8")
        self.assertIn("https://pass50.store/api/", client)
        self.assertIn("live-status.php?mode=status", client)
        self.assertIn("public-ranking.php", client)
        self.assertNotIn("mode=quick", client)

    def test_live_screen_polls_read_only_status(self):
        live = (MOBILE / "app/(tabs)/live.tsx").read_text(encoding="utf-8")
        self.assertIn("setInterval(load, 20000)", live)
        self.assertIn("lecture seule", live)

    def test_app_json_branding(self):
        app_json = (MOBILE / "app.json").read_text(encoding="utf-8")
        self.assertIn('"name": "PASS50"', app_json)
        self.assertIn('"scheme": "pass50"', app_json)
        self.assertIn("#050705", app_json)


if __name__ == "__main__":
    unittest.main()
