import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
INDEX = (ROOT / "index.html").read_text(encoding="utf-8")
API = (ROOT / "api/vote-share.php").read_text(encoding="utf-8")
DUEL_HISTORY = (ROOT / "api/duel-history-core.php").read_text(encoding="utf-8")
COULES = (ROOT / "api/coules.php").read_text(encoding="utf-8")
DATA_ENGINE = (ROOT / "api/data-engine-core.php").read_text(encoding="utf-8")
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
        for value in ("'profile'=>$selectedId", "'source'=>'vote_share'", "'medium'=>'social'"):
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


class ShareVoteDuelHistoryTests(unittest.TestCase):
    def test_both_candidates_are_present_on_the_card(self):
        self.assertIn("'candidates'=>$candidates", API)
        self.assertIn("card.candidates.slice(0,2)", INDEX)
        self.assertIn("MON VOTE · LE DUEL", INDEX)
        self.assertIn("'VS'", INDEX)

    def test_opponent_is_derived_from_the_voted_poll(self):
        self.assertIn("explode('__',$pollKey)", DUEL_HISTORY)
        self.assertIn("count($ids)===2", DUEL_HISTORY)
        self.assertIn("!in_array($selectedId,$ids,true)", DUEL_HISTORY)
        self.assertNotRegex(API, r"\$input\[['\"]opponent")

    def test_selected_candidate_is_explicit_and_highlighted(self):
        self.assertIn("'selectedProfileId'=>$selectedId", API)
        self.assertIn("candidate.profileId===card.selectedProfileId", INDEX)
        self.assertIn("✓ MON VOTE", INDEX)
        self.assertIn("ctx.strokeStyle=selected?'#b7ff00'", INDEX)

    def test_percentages_are_only_rendered_when_frozen_values_exist(self):
        self.assertIn("$history['candidate_a_percentage']!==null&&$history['candidate_b_percentage']!==null", API)
        self.assertIn("card.percentagesAvailable&&Number.isFinite", INDEX)
        self.assertIn("$percentagesAvailable=false", API)

    def test_no_fake_opponent_or_result_can_be_supplied(self):
        self.assertIn("p50_duel_candidate_ids($pollKey)", API)
        self.assertIn("p50_duel_public_candidates($ids,$snapshot)", API)
        self.assertNotRegex(API, r"\$input\[['\"](?:percentage|candidateA|candidateB|opponent)")

    def test_each_confirmed_vote_creates_an_immutable_snapshot(self):
        vote_write = COULES.index("INSERT INTO coules_votes")
        history_write = COULES.index("p50_duel_capture_vote_history")
        self.assertLess(vote_write, history_write)
        self.assertIn("INSERT INTO p50_duel_vote_history", DUEL_HISTORY)
        self.assertNotRegex(DUEL_HISTORY, r"(?:UPDATE|DELETE FROM)\s+p50_duel_vote_history")

    def test_snapshot_contains_vote_state_and_ranking_fields(self):
        for field in (
            "poll_key", "candidate_a_id", "candidate_b_id", "selected_profile_id",
            "candidate_a_percentage", "candidate_b_percentage", "total_votes",
            "candidate_a_rank", "candidate_b_rank", "candidate_a_score",
            "candidate_b_score", "state_revision", "state_updated_at", "voted_at",
        ):
            self.assertIn(field, DUEL_HISTORY)

    def test_later_vote_changes_do_not_recalculate_an_old_card(self):
        self.assertIn("p50_duel_history_for_share", API)
        self.assertIn("$snapshotSource='frozen_history'", API)
        share_payload = re.search(r"function p50_share_duel_payload\(.*?\n}", API, re.S).group(0)
        self.assertNotIn("COUNT(*) AS vote_count", share_payload)

    def test_old_vote_without_history_has_labeled_result_free_fallback(self):
        self.assertIn("$snapshotSource='current_fallback'", API)
        self.assertIn("$percentagesAvailable=false", API)
        self.assertIn("Historique absent : profils actuels affichés sans résultat.", API)
        self.assertIn("card.snapshotSource==='current_fallback'", INDEX)

    def test_share_session_references_the_used_history(self):
        self.assertIn("history_id", API)
        self.assertIn("$history['id']??null", API)
        self.assertIn("idx_vote_share_history", SCHEMA)

    def test_history_survives_app_state_recalculation_and_publication(self):
        self.assertNotIn("p50_duel_vote_history", DATA_ENGINE)
        self.assertNotRegex(DUEL_HISTORY, r"(?:TRUNCATE|DROP|DELETE FROM)\s+p50_duel_vote_history")
        self.assertIn("idx_duel_history_user", SCHEMA)
        self.assertIn("idx_duel_history_poll", SCHEMA)
        self.assertIn("idx_duel_history_voted", SCHEMA)
        self.assertIn("idx_duel_history_selected", SCHEMA)


if __name__ == "__main__":
    unittest.main(verbosity=2)
