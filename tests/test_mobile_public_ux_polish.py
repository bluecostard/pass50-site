import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MobilePublicUxPolishTests(unittest.TestCase):
    def test_cloud_banner_hidden_by_default(self):
        html = read("index.html")
        self.assertIn('id="cloudBanner" hidden', html)
        self.assertIn("publicVisible", html)
        self.assertNotIn("IONOS + MySQL actifs", html)

    def test_census_toast_only_on_manual_import(self):
        tools = read("v9-tools.js")
        self.assertIn("p50ImportCensus(false)", tools)
        self.assertIn(
            "if(window.__pass50CloudReady){\n      clearInterval(cloudTimer);\n      p50ImportCensus(false);\n    }",
            tools,
        )

    def test_open_profile_guards_missing_badges(self):
        tools = read("v9-tools.js")
        html = read("index.html")
        self.assertIn("Array.isArray(p.badges)?p.badges:[]", tools)
        self.assertIn("Profil introuvable", tools)
        self.assertIn("Array.isArray(p.badges)?p.badges:[]", html)
        self.assertNotIn("p.badges.map(badgeHtml)", tools)
        self.assertNotIn("${p.badges.map(badgeHtml)", html)

    def test_live_button_enlarged_on_mobile(self):
        html = read("index.html")
        self.assertIn("actions .live{display:inline-flex;align-items:center;gap:4px;font-size:12px", html)
        self.assertIn("v9-tools.js?v=15.5", html)


if __name__ == "__main__":
    unittest.main()
