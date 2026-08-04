from pathlib import Path
import re
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
            self.assertIn("p50_duel_audio_posts", source)
            self.assertIn("share_id", source)
            self.assertIn("poll_key", source)
            self.assertIn("candidate_a_id", source)
            self.assertIn("candidate_b_id", source)
            self.assertIn("selected_profile_id", source)
            self.assertIn("uq_p50_duel_audio_share", source)

    def test_upload_is_authenticated_consented_and_bounded(self):
        self.assertIn("$user=auth_user()", API)
        self.assertIn("publishConsent", API)
        self.assertIn("Confirmation de publication obligatoire", API)
        self.assertIn("P50_DUEL_AUDIO_MAX_BYTES=3145728", API)
        self.assertIn("P50_DUEL_AUDIO_MAX_DURATION_MS=15000", API)
        self.assertIn("Session de partage expirée", API)
        self.assertIn("Limite de publications audio atteinte", API)
        self.assertIn("move_uploaded_file", API)
        for mime in ("audio/webm", "audio/ogg", "audio/mp4"):
            self.assertIn(mime, API)

    def test_public_duel_returns_only_three_latest_anonymous_audios(self):
        self.assertIn("ORDER BY created_at DESC LIMIT 3", API)
        self.assertIn("'lastPerDuel'=>3", API)
        self.assertIn("'authorLabel'=>'Un membre PASS50'", API)
        self.assertIn("P50_DUEL_AUDIO_RETENTION_DAYS=30", API)
        item = API[API.index("function p50_duel_audio_item"):API.index("function p50_duel_audio_candidates")]
        self.assertNotIn("user_id", item)
        self.assertNotIn("display_name", item)

    def test_duel_page_displays_three_audio_players(self):
        self.assertIn("PASS50-DUEL-AUDIO-FEED-V1.0", CLIENT)
        self.assertIn("MAX_DUEL_AUDIOS=3", CLIENT)
        self.assertIn("Les 3 derniers audios du duel", CLIENT)
        self.assertIn("<audio controls", CLIENT)
        self.assertIn("COMMENTAIRE ANONYME", CLIENT)
        self.assertIn("fetchDuelAudios(true)", CLIENT)

    def test_audio_is_published_only_during_real_share_action(self):
        for event in ("native_share_triggered", "download", "platform_selected"):
            self.assertIn(event, CLIENT)
        self.assertIn("Publier aussi cet audio dans PASS50", CLIENT)
        self.assertIn("visible sous ce duel et dans Mon fil", CLIENT)
        self.assertIn("publishConsent", CLIENT)
        self.assertIn("VOTE_SHARE", CLIENT)
        self.assertNotIn("audio_recorded','download", CLIENT)

    def test_follow_feed_includes_audio_when_either_candidate_is_followed(self):
        self.assertIn("duel-audio.php", FEED)
        self.assertIn("profileIds: state.following.join(',')", FEED)
        self.assertIn("feedType: 'duel_audio'", FEED)
        self.assertIn("candidateA", FEED)
        self.assertIn("candidateB", FEED)
        self.assertIn("Parce que vous suivez", FEED)
        self.assertIn("Un membre PASS50 commente son vote", FEED)
        self.assertIn("<audio controls", FEED)
        self.assertIn("PASS50-FOLLOW-FEED-PAGE-V2.2", FEED)
        self.assertIn("mon-fil.js?v=2.2", FEED_HTML)

    def test_no_ranking_or_public_state_write(self):
        combined = API + CLIENT + FEED
        for forbidden in (
            "UPDATE app_state",
            "INSERT INTO app_state",
            "DELETE FROM app_state",
            "metrics-ranking-publication-apply.php",
            "p50_mr_calculate",
            "rank_position=",
        ):
            self.assertNotIn(forbidden, combined)

    def test_loader_and_cache_are_versioned(self):
        self.assertIn("duel-audio-feed-v1.js?v=1.0", LOADER)
        self.assertIn("data-pass50-duel-audio-feed", LOADER)
        self.assertIn("duel-audio-feed-v1.js?v=1.0", SW)
        self.assertIn("mon-fil.js?v=2.2", SW)
        self.assertRegex(SW, r"pass50-v\d+-[a-z0-9-]+")


if __name__ == "__main__":
    unittest.main()
