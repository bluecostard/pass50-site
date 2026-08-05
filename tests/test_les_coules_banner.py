import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


class LesCoulesBannerTests(unittest.TestCase):
    def test_compact_html_header_replaces_padded_png(self):
        coules_section = INDEX.split('<section class="section" id="coules"', 1)[1].split(
            "</section>", 1
        )[0]
        self.assertIn('class="coules-banner"', coules_section)
        self.assertIn("Les Coulés — Le duel", coules_section)
        self.assertIn("On entend plus parler d’eux", coules_section)
        self.assertIn("coules-banner-icon", coules_section)
        self.assertNotIn("les-coules-banner.png", coules_section)
        self.assertNotIn('class="section-head"', coules_section)

    def test_banner_stays_compact_without_letterbox(self):
        self.assertIn(".coules-banner{display:flex;align-items:center;", INDEX)
        self.assertNotIn(".coules-banner{display:block;width:100%;height:auto;", INDEX)
        self.assertNotIn(".coules-banner{max-height:240px", INDEX)
        self.assertIn("@media(max-width:680px)", INDEX)
        self.assertIn(".coules-banner{padding:10px 12px", INDEX)


if __name__ == "__main__":
    unittest.main()
