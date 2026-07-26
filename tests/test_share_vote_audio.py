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
DUEL_PAGE = (ROOT / "duel.php").read_text(encoding="utf-8")
HTACCESS = (ROOT / ".htaccess").read_text(encoding="utf-8")


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
        self.assertIn("Vidéo avec audio indisponible sur ce navigateur", INDEX)
        self.assertIn("Aucun partage audio n’a été effectué.", INDEX)
        self.assertIn("Math.min(18", INDEX)

    def test_09_native_share_includes_generated_file(self):
        self.assertIn("navigator.canShare?.({files:[file]})", INDEX)
        self.assertIn("navigator.share({", INDEX)
        self.assertIn("files:[file]", INDEX)

    def test_10_download_fallback_exists(self):
        self.assertIn("function downloadVoteShare()", INDEX)
        self.assertIn("a.download=VOTE_SHARE.mediaFile.name", INDEX)

    def test_11_copy_link_fallback_exists(self):
        self.assertIn("navigator.clipboard.writeText(voteShareMessage(VOTE_SHARE.card))", INDEX)
        self.assertIn("link_copied", API)

    def test_12_campaign_targets_selected_profile_without_qr(self):
        self.assertIn("$campaign=$base.'/d/'.$shareId", API)
        self.assertNotIn("'profile'=>$selectedId", API)
        self.assertIn("lines.push('','👇 Et toi, tu aurais voté pour qui ?','',card.campaignUrl)", INDEX)
        self.assertNotIn("api.qrserver.com", INDEX)
        self.assertNotIn("qrUrl", INDEX)

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


class ShareVoteMessageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.message = re.search(r"function voteShareMessage\(card\)\{.*?\n}", INDEX, re.S).group(0)
        cls.panel = re.search(r"function voteSharePanel\(\)\{.*?\n}", INDEX, re.S).group(0)
        cls.native = re.search(r"async function nativeVoteShare\(\)\{.*?\n}", INDEX, re.S).group(0)
        cls.whatsapp = re.search(r"async function shareVoteWhatsapp\(\)\{.*?\n}", INDEX, re.S).group(0)

    def test_whatsapp_message_contains_exact_question(self):
        self.assertIn("Qui est le plus coulé des 2 ? 🤔", self.message)

    def test_message_does_not_reveal_selected_candidate(self):
        self.assertNotIn("selectedProfileId", self.message)
        self.assertNotIn("Mon choix :", self.message)
        self.assertIn("Mon choix est fait… 😅", self.message)

    def test_both_frozen_percentages_are_displayed_when_available(self):
        self.assertIn("card.percentagesAvailable", self.message)
        self.assertIn("Number(candidates[0].percentage)} %", self.message)
        self.assertIn("Number(candidates[1].percentage)} %", self.message)
        self.assertIn("$history['candidate_a_percentage']", API)
        self.assertIn("$history['candidate_b_percentage']", API)

    def test_no_percentage_is_added_when_history_has_none(self):
        condition = self.message.index("if(card.percentagesAvailable")
        percentage_lines = self.message.index("candidates[0].percentage")
        campaign = self.message.index("Et toi, tu aurais voté pour qui")
        self.assertLess(condition, percentage_lines)
        self.assertLess(percentage_lines, campaign)
        self.assertIn("$percentagesAvailable=false", API)

    def test_no_qr_code_or_qr_copy_is_present(self):
        self.assertNotIn("api.qrserver.com", INDEX)
        self.assertNotIn("qrUrl", INDEX)
        self.assertNotRegex(INDEX, r"(?i)QR code")

    def test_campaign_link_is_present_once_in_message(self):
        self.assertEqual(self.message.count("card.campaignUrl"), 1)
        self.assertIn("👇 Et toi, tu aurais voté pour qui ?", self.message)

    def test_duel_names_and_separator_are_present(self):
        self.assertIn("String(candidates[0]?.name||'')", self.message)
        self.assertIn("'🆚'", self.message)
        self.assertIn("String(candidates[1]?.name||'')", self.message)

    def test_native_whatsapp_and_copy_use_identical_text(self):
        self.assertIn("const shareText=voteShareMessage(VOTE_SHARE.card)", self.whatsapp)
        self.assertIn("encodeURIComponent(shareText)", self.whatsapp)
        self.assertIn("const shareText=voteShareMessage(VOTE_SHARE.card)", self.native)
        self.assertIn("text:shareText", self.native)
        self.assertIn("text:shareText", self.whatsapp)
        self.assertIn("navigator.clipboard.writeText(voteShareMessage(VOTE_SHARE.card))", INDEX)


class ShareCardLargeThumbnailTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.loader = re.search(r"function shareImage\(url\)\{.*?\n}", INDEX, re.S).group(0)
        cls.draw = re.search(r"async function drawVoteShareCard\(.*?\n}", INDEX, re.S).group(0)
        cls.export = re.search(r"function canvasFile\(.*?\n}", INDEX, re.S).group(0)
        cls.generate = re.search(r"async function generateVoteShareImage\(.*?\n}", INDEX, re.S).group(0)
        cls.whatsapp = re.search(r"async function shareVoteWhatsapp\(\)\{.*?\n}", INDEX, re.S).group(0)

    def test_real_portrait_canvas_is_1080_by_1350(self):
        self.assertIn("canvas.width = 1080", self.generate)
        self.assertIn("canvas.height = square ? 1080 : 1350", self.generate)
        self.assertIn('width="1080" height="1350"', INDEX)

    def test_both_photos_finish_loading_before_drawing_and_export(self):
        load = self.draw.index("await Promise.all")
        context = self.draw.index("canvas.getContext")
        self.assertLess(load, context)
        self.assertIn("candidates.map(candidate=>shareImage(candidate.photoUrl))", self.draw)
        self.assertIn("await drawVoteShareCard", self.generate)
        self.assertLess(self.generate.index("await drawVoteShareCard"), self.generate.index("canvasFile"))

    def test_loader_uses_anonymous_cors_decode_and_error_fallback(self):
        self.assertLess(self.loader.index("image.crossOrigin='anonymous'"), self.loader.index("image.src=url"))
        self.assertIn("await image.decode()", self.loader)
        self.assertIn("image.onerror=()=>resolve(null)", self.loader)

    def test_png_file_is_non_empty_and_has_expected_name_and_type(self):
        self.assertIn("blob&&blob.size>0", self.export)
        self.assertIn("new File([blob],name,{type:'image/png'})", self.export)
        self.assertIn("'mon-vote-pass50.png'", self.generate)
        self.assertIn("'image/png'", self.export)

    def test_two_large_candidate_cards_dominate_portrait(self):
        self.assertIn("candidates.slice(0,2)", self.draw)
        self.assertIn("margin=24,gap=34", self.draw)
        self.assertIn("h===1080?600:835", self.draw)
        self.assertIn("isStory?50:42", self.draw)
        self.assertIn("isStory?68:59", self.draw)

    def test_card_keeps_vs_and_selected_vote_badge(self):
        self.assertIn("'VS'", self.draw)
        self.assertIn("✓ MON VOTE", self.draw)
        self.assertIn("selected?'#b7ff00'", self.draw)

    def test_no_qr_or_flat_black_empty_background(self):
        self.assertNotIn("api.qrserver.com", INDEX)
        self.assertNotIn("qrUrl", INDEX)
        self.assertIn("ctx.createLinearGradient(0,0,w,h)", self.draw)
        self.assertNotIn("ctx.fillStyle='#030503';ctx.fillRect(0,0,w,h)", self.draw)

    def test_failed_photo_uses_initials_on_a_gradient(self):
        self.assertIn("fallback=ctx.createLinearGradient", self.draw)
        self.assertIn("candidate.initials||'P50'", self.draw)

    def test_whatsapp_shares_png_or_downloads_before_text_fallback(self):
        self.assertIn("navigator.canShare({files:[file]})", self.whatsapp)
        self.assertIn("files:[file]", self.whatsapp)
        self.assertLess(self.whatsapp.index("downloadVoteShare()"), self.whatsapp.index("window.open("))
        self.assertIn("https://wa.me/?text=${encodeURIComponent(shareText)}", self.whatsapp)

    def test_open_graph_metadata_is_complete_and_absolute(self):
        expected = (
            '<meta property="og:title" content="PASS50 — Qui est le plus coulé des 2 ?" />',
            '<meta property="og:description" content="Découvre le duel et vote sur PASS50." />',
            '<meta property="og:image" content="https://pass50.store/assets/pass50-og.png" />',
            '<meta property="og:image:width" content="1200" />',
            '<meta property="og:image:height" content="630" />',
            '<meta property="og:type" content="website" />',
            '<meta property="og:url" content="https://pass50.store/" />',
        )
        for tag in expected:
            self.assertIn(tag, INDEX)
        self.assertTrue((ROOT / "assets/pass50-og.png").is_file())


class ShareDuelRouteAndAudioTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.message = re.search(r"function voteShareMessage\(card\)\{.*?\n}", INDEX, re.S).group(0)
        cls.video = re.search(r"async function generateVoteShareVideo\(\)\{.*?\n}", INDEX, re.S).group(0)
        cls.native = re.search(r"async function nativeVoteShare\(\)\{.*?\n}", INDEX, re.S).group(0)
        cls.whatsapp = re.search(r"async function shareVoteWhatsapp\(\)\{.*?\n}", INDEX, re.S).group(0)

    def test_shared_link_targets_exact_duel_not_profile_page(self):
        self.assertIn("$campaign=$base.'/d/'.$shareId", API)
        self.assertIn("RewriteRule ^d/([a-f0-9]{64})/?$", HTACCESS)
        self.assertNotIn("'profile'=>$selectedId", API)
        self.assertIn("WHERE s.id=? LIMIT 1", DUEL_PAGE)

    def test_public_duel_renders_frozen_candidates_result_and_choice(self):
        self.assertIn("foreach(['a','b'] as $side)", DUEL_PAGE)
        self.assertIn("'name'=>(string)$row['candidate_'.$side.'_name']", DUEL_PAGE)
        self.assertIn("'percentage'=>$row['candidate_'.$side.'_percentage']", DUEL_PAGE)
        self.assertIn("selected'=>$id===$selectedId", DUEL_PAGE)
        self.assertIn("✓ MON VOTE", DUEL_PAGE)
        self.assertIn(">JE VOTE</a>", DUEL_PAGE)
        self.assertIn("p50_duel_vote_history h ON h.id=s.history_id", DUEL_PAGE)

    def test_invalid_duel_identifier_falls_back_to_home(self):
        self.assertIn("if(!preg_match('/^[a-f0-9]{64}$/',$shareId))", DUEL_PAGE)
        self.assertGreaterEqual(DUEL_PAGE.count("header('Location: '.$base.'/',true,302)"), 3)
        self.assertIn("RewriteRule ^d(?:/.*)?$ duel.php", HTACCESS)

    def test_audio_generates_and_prioritizes_a_non_empty_video_file(self):
        self.assertIn("const videoBlob=new Blob(chunks", self.video)
        self.assertIn("if(videoBlob.size<=0)", self.video)
        self.assertIn("new File([videoBlob],'mon-vote-pass50.webm',{type:videoBlob.type})", self.video)
        self.assertIn("VOTE_SHARE.mediaKind!=='video'?await generateVoteShareVideo()", self.native)
        self.assertIn("VOTE_SHARE.mediaKind==='video'?VOTE_SHARE.mediaFile:await generateVoteShareVideo()", self.whatsapp)

    def test_without_audio_png_remains_the_shared_media(self):
        self.assertIn("const wantsVideo=Boolean(VOTE_SHARE.audioBlob)", self.whatsapp)
        self.assertIn("if(!wantsVideo&&(!file||file.type!=='image/png'))", self.whatsapp)
        self.assertIn("setVoteShareMedia(file,'image')", INDEX)

    def test_unsupported_video_downloads_without_false_audio_success(self):
        self.assertIn("navigator.canShare({files:[file]})", self.whatsapp)
        self.assertIn("downloadVoteShare()", self.whatsapp)
        self.assertIn("La vidéo avec audio a été téléchargée. Ajoute-la ensuite dans WhatsApp.", self.whatsapp)
        self.assertIn("Aucun partage audio n’a été effectué.", self.video)
        self.assertNotIn("audio_shared", INDEX)

    def test_duel_link_occurs_once_in_the_shared_message(self):
        self.assertEqual(self.message.count("card.campaignUrl"), 1)
        self.assertNotIn("url:VOTE_SHARE.card.campaignUrl", self.native)


if __name__ == "__main__":
    unittest.main(verbosity=2)
