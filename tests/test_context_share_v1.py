import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class ContextShareV1Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.js = read("context-share-v1.js")
        cls.endpoint = read("partage-contexte.php")
        cls.image = read("partage-contexte-image.php")
        cls.loader = read("public-copy-fixes.js")
        cls.nav = read("mobile-bottom-nav-v1.js")
        cls.worker = read("sw.js")

    def test_rankings_have_explicit_share_actions(self):
        self.assertIn("PASS50-CONTEXT-SHARE-V1.0", self.js)
        for size in (3, 10, 50):
            self.assertIn(f"Partager le Top {size}", self.js)
            self.assertIn(f"'ranking', {size}", self.js)
        self.assertIn("#buzz .hero-intro", self.js)
        self.assertIn("#top10 .section-head", self.js)
        self.assertIn("#top50Modal .modal-head", self.js)

    def test_shared_ranking_keeps_period_region_and_deep_link(self):
        self.assertIn("url.searchParams.set('period'", self.js)
        self.assertIn("url.searchParams.set('region'", self.js)
        self.assertIn("params.get('period')", self.js)
        self.assertIn("params.get('region')", self.js)
        self.assertIn("open === 'top50'", self.js)
        self.assertIn("button.click()", self.js)
        self.assertIn("section", self.endpoint)
        self.assertIn("share_ranking", self.endpoint)
        self.assertIn("p50_context_ranking", self.endpoint)
        self.assertIn("classable", self.endpoint)

    def test_feed_posts_and_duel_audio_are_shareable(self):
        self.assertIn("Partager ce post", self.js)
        self.assertIn("Partager cet audio", self.js)
        self.assertIn("duel-audio-feed-card", self.js)
        self.assertIn("audioTokenFromUrl", self.js)
        self.assertIn("feed-post", self.endpoint)
        self.assertIn("duel-audio", self.endpoint)
        self.assertIn("author_display_name", self.endpoint)
        self.assertIn("JOIN users", self.endpoint)
        self.assertIn("og:audio", self.endpoint)
        self.assertIn("Pseudo", read("mon-fil.js"))
        self.assertNotIn("anonyme", self.js.lower())

    def test_native_share_generates_readable_media_and_fallbacks(self):
        self.assertIn("drawRankingImage", self.js)
        self.assertIn("drawFeedImage", self.js)
        self.assertIn("navigator.share", self.js)
        self.assertIn("navigator.canShare", self.js)
        self.assertIn("audioFile(payload)", self.js)
        self.assertIn("WhatsApp", self.js)
        self.assertIn("Télécharger", self.js)
        self.assertIn("copyText", self.js)

    def test_social_landing_and_images_cover_all_contexts(self):
        for kind in ("ranking-top3", "ranking-top10", "ranking-top50", "feed-post", "duel-audio"):
            self.assertIn(f"'{kind}'", self.endpoint)
            self.assertIn(f"'{kind}'", self.image)
        self.assertIn("og:image", self.endpoint)
        self.assertIn("twitter:card", self.endpoint)
        self.assertIn("partage-contexte-image.php", self.endpoint)
        self.assertNotRegex(self.endpoint, r"\b(?:INSERT|UPDATE|DELETE)\b")

    def test_v1_is_kept_only_as_a_legacy_asset_not_loaded_by_runtime(self):
        self.assertIn("LEGACY_CONTEXT_SHARE_DISABLED='./context-share-v1.js?v=1.0'", self.loader)
        self.assertIn("context-share-v2.js?v=2.4", self.loader)
        self.assertIn("dataset.pass50ContextShareV2", self.loader)
        self.assertNotIn("loadScript('script[data-pass50-context-share]','./context-share-v1.js", self.loader)
        self.assertIn("context-share-v1.js?v=1.0", self.nav)
        self.assertIn("context-share-v1.js?v=1.0", self.worker)
        self.assertRegex(self.worker, r"pass50-v\d+-[a-z0-9-]+")


if __name__ == "__main__":
    unittest.main()
