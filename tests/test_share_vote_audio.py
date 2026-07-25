import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
API = (ROOT / "api/vote-share.php").read_text(encoding="utf-8")
SCHEMA = (ROOT / "schema.sql").read_text(encoding="utf-8")


class ShareVoteAudioContractTests(unittest.TestCase):
    def test_01_valid_vote_shows_share_button(self):
        self.assertIn("myVote===p.id", INDEX)
        self.assertIn("Partager mon vote", INDEX)
        self.assertIn("data-share-vote", INDEX)

    def test_02_rejected_vote_cannot_create_card(self):
        vote_check = API.index("SELECT profile_id,updated_at FROM coules_votes")
        rejection = API.index("Aucun vote correspondant ne peut être partagé")
        session_insert = API.index("INSERT INTO p50_vote_share_sessions")
        self.assertLess(vote_check, rejection)
        self.assertLess(rejection, session_insert)

    def test_03_image_without_audio_is_generated(self):
        self.assertIn("width=\"1080\" height=\"1350\"", INDEX)
        self.assertIn("canvas.toBlob", INDEX)
        self.assertIn("'image/png'", INDEX)
        self.assertIn("generateVoteShareImage(false)", INDEX)

    def test_04_microphone_permission_is_user_initiated(self):
        self.assertIn("navigator.mediaDevices.getUserMedia", INDEX)
        self.assertIn("e.target.id==='voteShareRecord'", INDEX)
        open_body = re.search(r"async function openVoteShare\(.*?\n}", INDEX, re.S).group(0)
        self.assertNotIn("getUserMedia", open_body)

    def test_05_recording_is_limited_to_fifteen_seconds(self):
        self.assertIn("VOTE_SHARE.seconds>=15", INDEX)
        self.assertIn("15 secondes", INDEX)

    def test_06_audio_can_be_played_and_recorded_again(self):
        self.assertIn("<audio controls", INDEX)
        self.assertIn("Recommencer", INDEX)
        self.assertIn("voteShareDeleteAudio", INDEX)

    def test_07_no_written_comment_field_exists(self):
        panel = re.search(r"function voteSharePanel\(\).*?\n}", INDEX, re.S).group(0)
        self.assertNotIn("<textarea", panel)
        self.assertNotRegex(panel, r"<input[^>]+type=[\"']text")
        self.assertIn("Aucun commentaire écrit", panel)

    def test_08_video_with_audio_prefers_mp4_and_has_fallback(self):
        self.assertIn("canvas.width=1080;canvas.height=1920", INDEX)
        self.assertIn("video/mp4;codecs=h264,aac", INDEX)
        self.assertIn("video/webm;codecs=vp9,opus", INDEX)
        self.assertIn("Vidéo indisponible sur ce navigateur", INDEX)
        self.assertIn("Math.min(18", INDEX)

    def test_09_native_share_includes_generated_file(self):
        self.assertIn("navigator.canShare?.({files:[file]})", INDEX)
        self.assertIn("navigator.share({", INDEX)
        self.assertIn("files:[file]", INDEX)

    def test_10_download_fallback_exists(self):
        self.assertIn("function downloadVoteShare()", INDEX)
        self.assertIn("a.download=VOTE_SHARE.mediaFile.name", INDEX)

    def test_11_copy_link_fallback_exists(self):
        self.assertIn("navigator.clipboard.writeText(VOTE_SHARE.card.campaignUrl)", INDEX)
        self.assertIn("link_copied", API)

    def test_12_qr_targets_profile_campaign(self):
        for value in ("'profile'=>$profileId", "'source'=>'vote_share'", "'medium'=>'social'"):
            self.assertIn(value, API)
        self.assertIn("data='+encodeURIComponent(card.campaignUrl)", INDEX)

    def test_13_analytics_does_not_claim_unverifiable_share(self):
        self.assertIn("native_share_triggered", API)
        self.assertNotIn("share_confirmed", API)
        self.assertNotIn("native_share_completed", API)
        self.assertIn("platform_selected", API)

    def test_14_temporary_media_is_not_persisted_and_urls_are_revoked(self):
        self.assertNotIn("$_FILES", API)
        self.assertNotIn("move_uploaded_file", API)
        self.assertIn("URL.revokeObjectURL(VOTE_SHARE.audioUrl)", INDEX)
        self.assertIn("URL.revokeObjectURL(VOTE_SHARE.mediaUrl)", INDEX)
        self.assertIn("getTracks().forEach(track=>track.stop())", INDEX)

    def test_15_mobile_iphone_and_android_capabilities_are_supported(self):
        for mime in ("audio/mp4", "audio/webm;codecs=opus", "video/mp4", "video/webm"):
            self.assertIn(mime, INDEX)
        self.assertIn("navigator.share", INDEX)
        self.assertIn("@media(max-width:680px)", INDEX)

    def test_16_abuse_protection_and_private_identity(self):
        self.assertIn("random_bytes(32)", API)
        self.assertIn(">=10", API)
        self.assertIn("INTERVAL 1 HOUR", API)
        self.assertNotRegex(API, r"['\"](?:email|displayName|userId)['\"]\s*=>")
        self.assertIn("p50_vote_share_sessions", SCHEMA)
        self.assertIn("p50_vote_share_events", SCHEMA)


if __name__ == "__main__":
    unittest.main(verbosity=2)
