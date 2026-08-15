import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class MissingTrendScoreLabelTests(unittest.TestCase):
    def test_main_profile_card_labels_missing_score(self):
        source = (ROOT / 'index.html').read_text()
        self.assertIn("value>0?`${Math.round(value)}/100`:'À calculer'", source)

    def test_follow_feed_labels_missing_score(self):
        source = (ROOT / 'follow-feed-v1.js').read_text()
        self.assertIn("value > 0 ? String(Math.round(value)) : 'À calculer'", source)

    def test_public_profile_labels_missing_score(self):
        source = (ROOT / 'fi.php').read_text()
        self.assertGreaterEqual(source.count("'À calculer'"), 2)


if __name__ == '__main__':
    unittest.main()
