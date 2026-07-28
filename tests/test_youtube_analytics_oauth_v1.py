from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class YouTubeAnalyticsOAuthV1Tests(unittest.TestCase):
    def test_official_reports_query_contract(self):
        core = (ROOT / "api" / "youtube-analytics-core.php").read_text(encoding="utf-8")
        self.assertIn("https://youtubeanalytics.googleapis.com/v2/reports?", core)
        self.assertIn("'ids' => 'channel==MINE'", core)
        self.assertIn("'startDate'", core)
        self.assertIn("'endDate'", core)
        self.assertIn("'metrics'", core)
        self.assertNotIn("includeHistoricalChannelData", core)

    def test_private_data_is_isolated_from_public_ranking(self):
        combined = "\n".join(
            (ROOT / path).read_text(encoding="utf-8")
            for path in [
                "api/youtube-analytics-core.php",
                "api/youtube-analytics-summary.php",
                "migration-youtube-analytics-v1.sql",
            ]
        )
        self.assertNotIn("app_state", combined)
        self.assertNotIn("p50_metric_captures", combined)
        endpoint = (ROOT / "api" / "youtube-analytics-summary.php").read_text(encoding="utf-8")
        self.assertIn("'affectsPublicRanking' => false", endpoint)
        self.assertIn("auth_user()", endpoint)

    def test_disconnect_removes_private_snapshots(self):
        disconnect = (ROOT / "api" / "youtube-oauth-disconnect.php").read_text(encoding="utf-8")
        self.assertIn("DELETE FROM p50_youtube_analytics_snapshots WHERE user_id=?", disconnect)

    def test_temporary_diagnostic_is_removed(self):
        self.assertFalse((ROOT / "oauth-youtube-diagnostic.html").exists())

    def test_private_ui_is_loaded(self):
        loader = (ROOT / "public-copy-fixes.js").read_text(encoding="utf-8")
        self.assertIn("youtube-analytics-ui-v1.js?v=1.0", loader)
        ui = (ROOT / "youtube-analytics-ui-v1.js").read_text(encoding="utf-8")
        self.assertIn("Ces données ne modifient pas le classement public", ui)


if __name__ == "__main__":
    unittest.main()
