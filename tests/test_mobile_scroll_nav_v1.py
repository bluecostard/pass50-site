# Mobile bottom nav V1.11 — scroll usable, lock only for overlays, content clear of bar.
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
NAV = (ROOT / "mobile-bottom-nav-v1.js").read_text(encoding="utf-8")
LOADER = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")
FEED = (ROOT / "mon-fil.html").read_text(encoding="utf-8")
PRONO = (ROOT / "pronostics.html").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class MobileScrollNavV1Tests(unittest.TestCase):
    def test_contract_and_cache_bust(self):
        self.assertIn("PASS50-MOBILE-BOTTOM-NAV-V1.11", NAV)
        self.assertIn("mobile-bottom-nav-v1.js?v=1.11", LOADER)
        self.assertIn('pass50MobileBottomNav","1.11"', LOADER.replace("'", '"'))
        self.assertIn("mobile-bottom-nav-v1.js?v=1.11", FEED)
        self.assertIn("mobile-bottom-nav-v1.js?v=1.11", PRONO)
        self.assertIn("mobile-bottom-nav-v1.js?v=1.11", SW)

    def test_bottom_padding_clears_floating_nav(self):
        self.assertIn("padding-bottom:calc(132px + env(safe-area-inset-bottom))", NAV)
        self.assertIn("padding-bottom:calc(140px + env(safe-area-inset-bottom))", NAV)
        self.assertIn("padding-bottom:calc(142px + env(safe-area-inset-bottom))", NAV)
        self.assertIn("bottom:calc(148px + env(safe-area-inset-bottom))", NAV)

    def test_scroll_lock_only_for_overlays(self):
        self.assertIn("function lockBodyScroll()", NAV)
        self.assertIn("function unlockBodyScroll()", NAV)
        self.assertIn("function syncOverlayScrollLock()", NAV)
        self.assertIn("body.p50-scroll-locked", NAV)
        self.assertIn("pageshow", NAV)
        # Press feedback must not freeze the document on every touchstart.
        press_start = NAV.split("const onPressStart = (event) => {", 1)[1].split("};", 1)[0]
        self.assertNotIn("freezeScroll()", press_start)
        self.assertIn("freezeScroll()", NAV)  # still used when leaving the page

    def test_carousel_and_modal_overscroll_guards(self):
        self.assertIn("touch-action:pan-x", NAV)
        self.assertIn("overscroll-behavior:contain", NAV)
        self.assertIn(".modal.show", NAV)


if __name__ == "__main__":
    unittest.main()
