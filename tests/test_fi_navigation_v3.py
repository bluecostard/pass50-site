import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
NAV = (ROOT / "fi-navigation-v3.js").read_text(encoding="utf-8")
ENGAGEMENT = (ROOT / "fi-engagement-v3.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class FiNavigationV3Tests(unittest.TestCase):
    def test_new_uncached_modules_are_loaded(self):
        self.assertIn("fi-navigation-v3.js?v=1.0", CONFIG)
        self.assertIn("fi-engagement-v3.js?v=1.0", CONFIG)
        self.assertNotIn("fi-navigation.js?v=1.2", CONFIG)
        self.assertNotIn("fi-engagement-v2.js?v=3.2", CONFIG)

    def test_desktop_navigation_wraps_core_open_profile(self):
        self.assertIn("__p50NavigationV3", NAV)
        self.assertIn("coreOpenProfile.apply(this,arguments)", NAV)
        self.assertIn("window.openProfile=wrapped", NAV)
        self.assertIn("ArrowLeft", NAV)
        self.assertIn("ArrowRight", NAV)

    def test_mobile_swipe_supports_pointer_and_touch_events(self):
        for event in (
            "pointerdown",
            "pointermove",
            "pointerup",
            "touchstart",
            "touchmove",
            "touchend",
        ):
            self.assertIn(event, NAV)
        self.assertIn("touch-action:pan-y", NAV)
        self.assertIn("event.preventDefault()", NAV)
        self.assertIn("Math.abs(dx)<38", NAV)

    def test_mobile_has_no_arrows_or_indicator(self):
        self.assertIn(".p50-fi-nav-controls{display:none!important}", NAV)
        self.assertNotIn("/ 134", NAV)
        self.assertNotIn("p50-fi-indicator", NAV)

    def test_engagement_is_idempotent_before_dom_rebuild(self):
        guard = ENGAGEMENT.index("const alreadyCorrect=")
        early_return = ENGAGEMENT.index("if(alreadyCorrect)")
        rebuild = ENGAGEMENT.index("body.querySelectorAll('.p50-fi-actions,.p50-verified')")
        self.assertLess(guard, early_return)
        self.assertLess(early_return, rebuild)
        self.assertIn("if(button.textContent!=='♥ J’aime')", ENGAGEMENT)
        self.assertIn("if(scheduled)return", ENGAGEMENT)

    def test_service_worker_forces_fresh_scripts(self):
        self.assertIn("pass50-v27-fi-navigation-v3", SW)
        self.assertIn("fi-navigation-v3.js?v=1.0", SW)
        self.assertIn("fi-engagement-v3.js?v=1.0", SW)
        self.assertIn("cache:'no-store'", SW)


if __name__ == "__main__":
    unittest.main()
