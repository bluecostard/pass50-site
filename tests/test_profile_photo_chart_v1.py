import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
V9 = (ROOT / "v9-tools.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class ProfilePhotoChartV1Tests(unittest.TestCase):
    def test_stale_local_photo_does_not_beat_newer_cloud_photo(self):
        self.assertIn("function p50LocalPhotoShouldKeep(kept,cloud)", INDEX)
        self.assertIn("if(localAt&&cloudAt)return localAt>cloudAt", INDEX)
        self.assertIn("if(p50LocalPhotoShouldKeep(kept,p))Object.assign(p,kept)", INDEX)
        self.assertNotIn("const localBetter=!cloudHasPhoto||kept.photoManualLocked", INDEX)

    def test_public_photo_is_cache_busted(self):
        self.assertIn("function photoCacheBust(url,p)", INDEX)
        self.assertIn("p50=", INDEX)
        self.assertIn("return raw?photoCacheBust(raw,p):''", INDEX)

    def test_chart_uses_twelve_decorative_bars_with_animation(self):
        self.assertIn("function p50ProfileChartHtml(p)", INDEX)
        self.assertIn("[31,38,42,36,51,59,63,70,66,79,85,score(p)]", INDEX)
        self.assertIn("${p50ProfileChartHtml(p)}", INDEX)
        self.assertIn("${p50ProfileChartHtml(p)}", V9)
        self.assertNotIn("Score PASS50 par période", INDEX)
        self.assertIn("p50BarGrow", INDEX)
        self.assertIn("--bar-delay:${i*45}ms", INDEX)

    def test_loader_cache_is_bumped(self):
        self.assertIn("v9-tools.js?v=15.37", INDEX)
        self.assertIn("v9-tools.js?v=15.33", SW)


if __name__ == "__main__":
    unittest.main()
