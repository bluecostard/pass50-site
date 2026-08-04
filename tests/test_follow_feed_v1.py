import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class FollowFeedV1Tests(unittest.TestCase):
    def test_feed_is_finite_and_user_selected(self):
        feed = read("follow-feed-v1.js")
        self.assertIn("PASS50-FOLLOW-WATCH-V1.0", feed)
        self.assertIn("const MAX_FOLLOWED = 5", feed)
        self.assertIn("slice(0, MAX_FOLLOWED)", feed)
        self.assertIn("Fin de votre veille", feed)
        self.assertIn("Aucun contenu suggéré", feed)
        self.assertNotIn("infinite", feed.lower())

    def test_feed_combines_ranking_live_news_and_official_links(self):
        feed = read("follow-feed-v1.js")
        self.assertIn("completeRanking", feed)
        self.assertIn("activeLives", feed)
        self.assertIn("content-feed.php", feed)
        self.assertIn("p50RecoverableDirectLink", feed)
        self.assertIn("POURQUOI DANS LE TOP 5", feed)

    def test_user_space_and_cache_are_wired(self):
        loader = read("public-copy-fixes.js")
        worker = read("sw.js")
        self.assertIn("follow-feed-v1.js?v=1.0", loader)
        self.assertIn("data-pass50-follow-watch", loader)
        self.assertIn("follow-feed-v1.js?v=1.0", worker)
        self.assertIn("pass50-v58-follow-watch", worker)


if __name__ == "__main__":
    unittest.main()
