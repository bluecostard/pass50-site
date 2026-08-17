import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")


class PublicTop10FirstPaintTests(unittest.TestCase):
    def test_init_cloud_paints_ranking_before_waiting_on_ionos(self):
        start = INDEX.index("async function initCloud()")
        body = INDEX[start:INDEX.index("initCloud();", start)]
        self.assertLess(
            body.index("scheduleRender({immediate:true})"),
            body.index("await loadCloudState()"),
        )
        self.assertIn("attempt<=3", body)
        self.assertIn("endBootSettling()", body)
        self.assertIn("timeoutMs:12000", INDEX)
        self.assertIn("AbortController", INDEX)
        self.assertIn("id=\"top10Grid\"", INDEX)
        self.assertIn("function renderTop10(", INDEX)


if __name__ == "__main__":
    unittest.main()
