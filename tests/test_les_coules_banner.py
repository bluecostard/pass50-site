import hashlib
import pathlib
import struct
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
PUBLIC_BANNER = ROOT / "assets" / "les-coules-banner.png"
SOURCE_BANNER = ROOT / "assets" / "branding" / "les-coules-banner.png"


def png_dimensions(path):
    data = path.read_bytes()
    if data[:8] != b"\x89PNG\r\n\x1a\n":
        raise AssertionError(f"{path} is not a PNG")
    return struct.unpack(">II", data[16:24])


class LesCoulesBannerTests(unittest.TestCase):
    def test_source_and_web_banner_are_valid_identical_pngs(self):
        self.assertEqual((1857, 847), png_dimensions(SOURCE_BANNER))
        self.assertEqual((1857, 847), png_dimensions(PUBLIC_BANNER))
        self.assertEqual(
            hashlib.sha256(SOURCE_BANNER.read_bytes()).digest(),
            hashlib.sha256(PUBLIC_BANNER.read_bytes()).digest(),
        )

    def test_official_banner_replaces_the_text_heading(self):
        self.assertIn('src="./assets/les-coules-banner.png"', INDEX)
        self.assertIn('width="1857" height="847"', INDEX)
        coules_section = INDEX.split('<section class="section" id="coules"', 1)[1].split(
            "</section>", 1
        )[0]
        self.assertNotIn('class="section-head"', coules_section)

    def test_banner_preserves_its_aspect_ratio_responsively(self):
        self.assertIn(
            ".coules-banner{display:block;width:100%;height:auto;", INDEX
        )
        self.assertIn("object-fit:contain", INDEX)
        self.assertIn("@media(max-width:680px)", INDEX)
        self.assertIn(".coules-banner{max-height:240px", INDEX)


if __name__ == "__main__":
    unittest.main()
