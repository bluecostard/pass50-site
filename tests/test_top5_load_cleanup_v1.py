import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
CLIENT = (ROOT / "content-intelligence.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


def extract_function(source, name):
    match = re.search(rf"function {name}\s*\(", source)
    if not match:
        match = re.search(rf"{name}\s*=\s*function\s*\(", source)
    if not match:
        raise AssertionError(f"function {name} not found")
    start = match.start()
    brace = source.find("{", match.end() - 1)
    depth = 0
    for index, char in enumerate(source[brace:], brace):
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[start : index + 1]
    raise AssertionError(f"unterminated function {name}")


class Top5LoadCleanupV1Tests(unittest.TestCase):
    def test_public_grid_never_paints_seed_videos(self):
        for source, name in ((INDEX, "renderContent"), (V9, "renderContent")):
            body = extract_function(source, name)
            self.assertNotIn("db.content", body)
            self.assertNotIn("data-content=\"${c.id}\"", body)
            self.assertNotIn("Chargement des tendances", body)
            self.assertIn("grid.innerHTML=''", body.replace(' ', ''))

    def test_legacy_seed_slots_are_hidden_and_removed(self):
        for source in (INDEX, CLIENT, V9):
            self.assertIn('data-content="c1"', source)
            self.assertIn("p50-content-grid-loading", source)
        self.assertIn("stripLegacyTrendCards", CLIENT)
        self.assertIn("paintTrendWait", CLIENT)
        self.assertIn(".content-card:not(.p50ci-card)", CLIENT)
        self.assertIn("display:none!important", INDEX)
        self.assertIn("display:none!important", CLIENT)

    def test_ci_hook_does_not_fall_back_to_old_cards(self):
        hook = extract_function(CLIENT, "installRenderHook")
        self.assertNotIn("original.apply", hook)
        self.assertNotIn("const original", hook)
        self.assertIn("renderTrends()", hook)
        self.assertIn("refreshTrends()", hook)

    def test_content_intelligence_loads_before_window_load(self):
        self.assertIn("content-intelligence.js?v=1.15", CONFIG)
        self.assertIn("content-intelligence.js?v=1.15", SW)
        self.assertIn("v9-tools.js?v=15.37", INDEX)
        self.assertIn("v9-tools.js?v=15.33", SW)
        self.assertIn("DOMContentLoaded", CONFIG)
        self.assertNotIn("addEventListener('load', loadContentIntelligence", CONFIG)

    def test_trend_grid_has_no_visible_status_copy(self):
        for source in (INDEX, V9, extract_function(CLIENT, "renderTrends"), extract_function(CLIENT, "paintTrendWait")):
            self.assertNotIn("Chargement des tendances", source)
            self.assertNotIn("Calcul du Top 5", source)
            self.assertNotIn("premier calcul automatique", source)
            self.assertNotIn("Aucun contenu suffisamment récent", source)


if __name__ == "__main__":
    unittest.main()
