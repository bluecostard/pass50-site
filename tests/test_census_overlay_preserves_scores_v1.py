import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def apply_body(name: str) -> str:
    source = (ROOT / name).read_text(encoding="utf-8")
    return source.split("function applyProfile", 1)[1]


class CensusOverlayPreservesScoresV1Tests(unittest.TestCase):
    def test_existing_profiles_are_not_forced_back_to_census(self):
        for name in (
            "profile-kawaii-nanami.js",
            "profile-ennemi-des-djandjou.js",
            "profile-obre-marie-pascale.js",
        ):
            body = apply_body(name)
            self.assertNotIn(
                "eligible:false",
                body,
                f"{name} still forces eligible=false on an existing scored profile",
            )
            self.assertNotIn(
                "classable:false",
                body,
                f"{name} still forces classable=false on an existing scored profile",
            )

    def test_new_profiles_can_still_start_as_census(self):
        kawaii = (ROOT / "profile-kawaii-nanami.js").read_text(encoding="utf-8")
        base = kawaii.split("function applyProfile", 1)[0]
        self.assertIn("eligible:false", base)
        self.assertIn("classable:false", base)

    def test_cache_bust_for_overlay_scripts(self):
        app = (ROOT / "app-config.js").read_text(encoding="utf-8")
        sw = (ROOT / "sw.js").read_text(encoding="utf-8")
        for src in (
            "profile-kawaii-nanami.js?v=1.1",
            "profile-ennemi-des-djandjou.js?v=1.1",
            "profile-obre-marie-pascale.js?v=1.2",
        ):
            self.assertIn(src, app)
            self.assertIn(src, sw)


if __name__ == "__main__":
    unittest.main()
