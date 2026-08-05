import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class ContextShareV2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.js = read("context-share-v2.js")
        cls.nav = read("mobile-bottom-nav-v1.js")
        cls.worker = read("sw.js")
        cls.loader = read("public-copy-fixes.js")
        cls.photo = read("partage-photo.php")
        cls.photo_core = read("api/share-photo-core.php")
        cls.landing = read("partage-contexte-v2.php")
        cls.og = read("partage-contexte-image-v2.php")

    def test_one_floating_ranking_button_opens_three_choices(self):
        self.assertIn("PASS50-CONTEXT-SHARE-V2.0", self.js)
        self.assertIn("p50-ranking-share-fab", self.js)
        self.assertIn("p50-ranking-share-toggle", self.js)
        for size in (3, 10, 50):
            self.assertIn(f'data-p50-ranking-share-size="{size}"', self.js)
        self.assertIn("openPayload(rankingPayload", self.js)
        self.assertNotIn("Partager le Top 3", self.js)
        self.assertNotIn("Partager le Top 10", self.js)
        self.assertNotIn("Partager le Top 50", self.js)

    def test_global_site_share_is_removed(self):
        self.assertIn("#shareBtn{display:none!important}", self.js)
        self.assertIn("document.getElementById('shareBtn')?.remove()", self.js)
        self.assertIn("removeLegacyShareUi", self.js)

    def test_coules_container_is_tighter_and_not_black_panel(self):
        self.assertIn("#coules.section{padding:12px!important;background:transparent!important", self.js)
        self.assertIn("#coules .coules-banner", self.js)
        self.assertIn("display:flex!important;align-items:center!important", self.js)
        self.assertIn("#coules .sunk-duel", self.js)
        self.assertIn("linear-gradient(145deg,#2a1014,#12090b", self.js)
        self.assertIn("#coules .sunk{padding:12px!important", self.js)

    def test_share_images_use_validated_profile_photos(self):
        self.assertIn("partage-photo.php", self.js)
        self.assertIn("photoUrl: photoUrl(profile", self.js)
        self.assertIn("loadImage(row.photoUrl)", self.js)
        self.assertIn("drawAvatar(ctx", self.js)
        self.assertIn("candidateA", self.js)
        self.assertIn("candidateB", self.js)
        self.assertIn("p50_share_photo_reference", self.photo_core)
        self.assertIn("photoStatus", self.photo_core)
        self.assertIn("p50_share_photo_remote_asset", self.photo_core)
        self.assertIn("CURLOPT_RESOLVE", self.photo_core)
        self.assertIn("P50_SHARE_PHOTO_MAX_BYTES", self.photo_core)
        self.assertIn("p50_share_photo_resize", self.photo)
        self.assertIn("imagecopyresampled", self.photo)

    def test_social_previews_also_include_portraits(self):
        self.assertIn("partage-contexte-image-v2.php", self.landing)
        self.assertIn("og:image", self.landing)
        self.assertIn("p50_og_v2_avatar", self.og)
        self.assertIn("p50_og_v2_cached_asset", self.og)
        self.assertIn("p50_share_photo_cached_asset", self.og)
        for kind in ("ranking-top3", "ranking-top10", "ranking-top50", "feed-post", "duel-audio"):
            self.assertIn(f"'{kind}'", self.landing)
            self.assertIn(f"'{kind}'", self.og)
        self.assertNotRegex(self.landing, r"\b(?:INSERT|UPDATE|DELETE)\b")
        self.assertNotRegex(self.og, r"\b(?:INSERT|UPDATE|DELETE)\b")

    def test_feed_sharing_keeps_audio_and_account_pseudo(self):
        self.assertIn("Partager cet audio", self.js)
        self.assertIn("Partager ce post", self.js)
        self.assertIn("audioFile(payload)", self.js)
        self.assertIn("author_display_name", self.landing)
        self.assertIn("JOIN users", self.landing)
        self.assertIn("og:audio", self.landing)

    def test_runtime_loads_only_v2_and_keeps_legacy_as_marker(self):
        self.assertIn("context-share-v2.js?v=2.3", self.loader)
        self.assertIn("dataset.pass50ContextShareV2", self.loader)
        self.assertIn("LEGACY_CONTEXT_SHARE_DISABLED='./context-share-v1.js?v=1.0'", self.loader)
        self.assertNotIn("loadScript('script[data-pass50-context-share]','./context-share-v1.js", self.loader)
        self.assertIn("context-share-v2.js?v=2.3", self.nav)
        self.assertIn("context-share-v1.js?v=1.0", self.nav)
        self.assertIn("context-share-v2.js?v=2.3", self.worker)
        self.assertIn("pass50-v77-context-share", self.worker)
        self.assertRegex(self.worker, r"pass50-v\d+-[a-z0-9-]+")


if __name__ == "__main__":
    unittest.main()
