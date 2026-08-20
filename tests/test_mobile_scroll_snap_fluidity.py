import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class MobileScrollSnapFluidityTests(unittest.TestCase):
    def test_content_grid_uses_mandatory_center_snap(self):
        html = read("index.html")
        self.assertIn("scroll-snap-type:x mandatory", html)
        self.assertIn("scroll-snap-align:center", html)
        self.assertIn("scroll-snap-stop:always", html)
        self.assertIn(".content-grid{display:flex", html)
        self.assertNotIn("scroll-snap-type:x proximity", html)

    def test_profile_navigation_has_enter_exit_transitions(self):
        nav = read("fi-navigation-v3.js")
        self.assertIn("p50-fi-out", nav)
        self.assertIn("p50-fi-in-active", nav)
        self.assertIn("cubic-bezier(.22,.8,.24,1)", nav)
        self.assertGreater(nav.find("Math.abs(dx)<48"), 0)

    def test_cache_bumped(self):
        self.assertIn("fi-navigation-v3.js?v=1.3", read("app-config.js"))
        self.assertIn("fi-navigation-v3.js?v=1.3", read("sw.js"))
        self.assertRegex(read("sw.js"), r"pass50-v\d+-[a-z0-9-]+")


if __name__ == "__main__":
    unittest.main()
