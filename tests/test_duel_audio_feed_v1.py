from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
API = (ROOT / "api" / "duel-audio.php").read_text(encoding="utf-8")
CLIENT = (ROOT / "duel-audio-feed-v1.js").read_text(encoding="utf-8")
FEED = (ROOT / "mon-fil.js").read_text(encoding="utf-8")
FEED_HTML = (ROOT / "mon-fil.html").read_text(encoding="utf-8")
LOADER = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")
SCHEMA = (ROOT / "schema.sql").read_text(encoding="utf-8")


class DuelAudioFeedV1Tests(unittest.TestCase):
    def test_schema_links_audio_to_exact_duel_and_share(self):
        for source in (API, SCHEMA):
            for marker in (
                "p50_duel_audio_posts", "share_id", "poll_key",
                "candidate_a_id", "candidate_b_id", "selected_profile_id",
                "uq_p50_duel_audio_share",
            ):
                self.assertIn(marker, source)

    def test_upload_is_authenticated_consented_and_bounded(self):
        for marker in (
            "$user=auth_user()", "publishConsent",
            "Confirmation de publication obligatoire",
            "P50_DUEL_AUDIO_MAX_BYTES=3145728",
            "P50_DUEL_AUDIO_MAX_DURATION_MS=15000",
            "Session de partage expirée",
            "Limite de publications audio atteinte",
            "move_uploaded_file",
            "audio/webm", "audio/ogg", "audio/mp4",
        ):
            self.assertIn(marker, API)

    def test_public_duel_returns_only_three_latest_attributed_audios(self):
        for marker in (
            "ORDER BY p.created_at DESC LIMIT 3", "'lastPerDuel'=>3",
            "u.display_name author_display_name", "'authorPseudo'=>$authorPseudo",
            "'anonymousAuthor'=>false", "'authorIdentity'=>'account_display_name'",
            "P50_DUEL_AUDIO_RETENTION_DAYS=30",
       ):
            self.assertIn(marker, API)
        item = API[API.index("function p50_duel_audio_item"):API.index("function p50_duel_audio_candidates")]
        self.assertNotIn("'userId'", item)
        self.assertNotIn("'email'", item)

    def test_duel_page_displays_three_audio_players_with_pseudo(self):
        for marker in (
            "PASS50-DUEL-AUDIO-FEED-V1.1", "MAX_DUEL_AUDIOS=3",
            "Les 3 derniers audios du duel", "<audio controls",
            "item.authorPseudo", "pseudo public du compte PASS50",
            "fetchDuelAudios(true)",
        ):
            self.assertIn(marker, CLIENT)
        self.assertNotIn("COMMENTAIRE ANONYME", CLIENT)
        self.assertNotIn("Lidentité du membre n’est pas affichée", CLIENT)

    def test_audio_is_published_only_during_real_share_action(self):
        for marker in (
            "native_share_triggered", "download", "platform_selected",
            "Publier aussi cet audio dans PASS50",
            "visible sous ce duel et dans Mon fil",
            "Votre pseudo public PASS50 sera affiché",
            "publishConsent", "VOTE_SHARE",
        ):
            self.assertIn(marker, CLIENT)
        self.assertNotIn("audio_recorded','download", CLIENT)

    def test_follow_feed_includes_community_duel_audio_without_follow_filter(self):
        for marker in (
            "duel-audio.php", "limit: String(DUEL_AUDIO_LIMIT)",
            "feedType: 'duel_audio'", "candidateA", "candidateB",
            "Audios de la communauté Les Coulés", "item.authorPseudo",
            "commente son vote pour",
            "Pseudo issu de son compte utilisateur PASS50",
            "<audio controls", "PASS50-FOLLOW-FEED-PAGE-V2.19",
        ):
            self.assertIn(marker, FEED)
        self.assertNotIn("profileIds: state.following.join(',')", FEED)
        self.assertNotIn("Identité non affichée", FEED)
        self.assertIn("mon-fil.js?v=2.19", FEED_HTML)

    def test_no_ranking_or_public_state_write(self):
        combined = API + CLIENT + FEED
        for forbidden in (
            "UPDATE app_state", "INSERT INTO app_state", "DELETE FROM app_state",
            "metrics-ranking-publication-apply.php", "p50_mr_calculate",
            "rank_position=",
        ):
            self.assertNotIn(forbidden, combined)

    def test_loader_and_cache_are_versioned(self):
        self.assertIn("duel-audio-feed-v1.js?v=1.1", LOADER)
        self.assertIn("data-pass50-duel-audio-feed", LOADER)
        self.assertIn("duel-audio-feed-v1.js?v=1.1", SW)
        self.assertIn("mon-fil.js?v=2.19", SW)
        self.assertIn("pass50-v75-duel-audio-identity", SW)


if __name__ == "__main__":
    unittest.main()
