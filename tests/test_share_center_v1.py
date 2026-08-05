from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
CENTER = (ROOT / "share-center-v1.js").read_text(encoding="utf-8")
APP_CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
SHARE_PAGE = (ROOT / "partage.php").read_text(encoding="utf-8")
SHARE_IMAGE = (ROOT / "partage-image.php").read_text(encoding="utf-8")
VOTE_SHARE = (ROOT / "api" / "vote-share.php").read_text(encoding="utf-8")
LIVE = (ROOT / "live-experience-v4-1.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class ShareCenterV1Tests(unittest.TestCase):
    def test_five_visual_codes_are_explicit(self):
        expected = {
            "site": "#1ee5ff",
            "profile": "#b7ff00",
            "live": "#ff4b4b",
            "coules": "#ff9d1d",
            "coules-audio": "#a66cff",
        }
        for kind, color in expected.items():
            self.assertIn(kind, CENTER)
            self.assertIn(color, CENTER)
        self.assertIn("PARTAGE SANS AUDIO", CENTER)
        self.assertIn("PARTAGE AVEC AUDIO", CENTER)

    def test_center_loads_before_legacy_profile_sharing(self):
        self.assertIn("data-pass50-share-center", APP_CONFIG)
        self.assertIn("share-center-v1.js?v=1.1", APP_CONFIG)
        self.assertLess(
            APP_CONFIG.index("share-center-v1.js?v=1.1"),
            APP_CONFIG.index("fi-engagement-v3.js?v=1.3"),
        )

    def test_mobile_site_share_is_forced_visible(self):
        self.assertIn("#shareBtn{display:inline-grid!important", CENTER)
        self.assertIn("openSite()", CENTER)
        self.assertIn("target.closest('#shareBtn')", CENTER)

    def test_profile_share_has_its_own_title_and_deep_link(self):
        self.assertIn("'profile'=>['color'=>'#b7ff00'", SHARE_PAGE)
        self.assertIn("Fiche influenceur", SHARE_PAGE)
        self.assertIn("$query['profile']=$id", SHARE_PAGE)
        profile_block = SHARE_PAGE[
            SHARE_PAGE.index("'profile'=>"):SHARE_PAGE.index("'live'=>")
        ]
        self.assertNotIn("Les Coulés", profile_block)
        self.assertIn("type==='profile'", CENTER)
        self.assertIn("window.openProfile(profileId)", CENTER)

    def test_live_share_delegates_to_unified_red_card(self):
        self.assertIn("window.PASS50_SHARE_CENTER?.openLive", LIVE)
        self.assertIn("profileId,platform,directUrl", LIVE)
        self.assertIn("'live'=>['color'=>'#ff4b4b'", SHARE_PAGE)
        self.assertIn("$query['live']=$id", SHARE_PAGE)

    def test_site_and_coules_land_on_the_right_content(self):
        self.assertIn("'site'=>['color'=>'#1ee5ff'", SHARE_PAGE)
        self.assertIn("$query['section']='coules'", SHARE_PAGE)
        self.assertIn("'type'=>'coules'", VOTE_SHARE)
        self.assertIn("'type'=>'coules-audio'", VOTE_SHARE)
        self.assertIn("'campaignAudioUrl'=>$campaignAudio", VOTE_SHARE)
        self.assertNotIn("$base.'/?'.http_build_query(['profile'=>$selectedId", VOTE_SHARE)

    def test_social_metadata_is_dynamic_and_not_global_coules_copy(self):
        for tag in (
            'property="og:title"',
            'property="og:description"',
            'property="og:image"',
            'name="twitter:card"',
        ):
            self.assertIn(tag, SHARE_PAGE)
        self.assertIn("partage-image.php", SHARE_PAGE)
        self.assertIn("summary_large_image", SHARE_PAGE)

    def test_color_image_endpoint_is_bounded_and_has_fallback(self):
        self.assertIn("$allowed=['site','profile','live','coules','coules-audio']", SHARE_IMAGE)
        self.assertIn("extension_loaded('gd')", SHARE_IMAGE)
        self.assertIn("assets/pass50-og.png", SHARE_IMAGE)
        self.assertIn("Content-Type: image/png", SHARE_IMAGE)

    def test_new_share_code_does_not_write_public_state(self):
        combined = CENTER + SHARE_PAGE + SHARE_IMAGE + VOTE_SHARE + LIVE
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "REPLACE INTO app_state",
        ):
            self.assertNotIn(forbidden, combined)

    def test_service_worker_keeps_share_center_and_versioned_cache(self):
        self.assertIn("./share-center-v1.js?v=1.1", SW)
        self.assertIn("./coules-share-simple-v1.js?v=1.0", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")


if __name__ == "__main__":
    unittest.main()
