# Top 5 mobile — vertical page scroll must work when touch starts on the carousel.
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
NAV = (ROOT / "mobile-bottom-nav-v1.js").read_text(encoding="utf-8")
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
CLIENT = (ROOT / "content-intelligence.js").read_text(encoding="utf-8")


class Top5VerticalScrollV1Tests(unittest.TestCase):
    def test_content_grid_allows_vertical_scroll(self):
        self.assertIn("PASS50-MOBILE-BOTTOM-NAV-V1.12", NAV)
        self.assertIn("#contentGrid", NAV)
        self.assertIn("touch-action:pan-y pinch-zoom", NAV)
        self.assertNotRegex(
            NAV,
            r"\.top10,\s*\.content-grid,\s*\.follow-strip",
        )

    def test_index_mobile_content_grid_css(self):
        mobile = INDEX.split("@media(max-width:680px)", 1)[1].split("@media(max-width:390px)", 1)[0]
        self.assertIn("overflow-y:visible", mobile)
        self.assertIn("touch-action:pan-y pinch-zoom", mobile)
        self.assertIn("scroll-snap-stop:normal", mobile)
        self.assertNotIn("scroll-snap-stop:always", mobile)

    def test_content_intelligence_mobile_touch(self):
        self.assertIn("touch-action:pan-y pinch-zoom", CLIENT)
        self.assertIn("scroll-snap-type:x proximity", CLIENT)


if __name__ == "__main__":
    unittest.main()
