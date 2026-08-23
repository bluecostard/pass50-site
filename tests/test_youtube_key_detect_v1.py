from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class YoutubeKeyDetectV1Tests(unittest.TestCase):
    def test_key_resolve_helpers_exist(self):
        metrics = (ROOT / "api" / "metrics-core.php").read_text(encoding="utf-8")
        radar = (ROOT / "api" / "radar-core.php").read_text(encoding="utf-8")
        bootstrap = (ROOT / "api" / "bootstrap.php").read_text(encoding="utf-8")
        self.assertIn("function p50m_youtube_key_resolve()", metrics)
        self.assertIn("function p50m_youtube_key_status()", metrics)
        self.assertIn("getenv('PASS50_YOUTUBE_API_KEY')", metrics)
        self.assertIn("$GLOBALS['config'] = $config", bootstrap)
        self.assertIn("keySource", radar)
        self.assertIn("keyLength", radar)

    def test_staff_status_endpoint(self):
        endpoint = (ROOT / "api" / "youtube-key-status.php").read_text(encoding="utf-8")
        self.assertIn("require_role($user, 'owner', 'admin')", endpoint)
        self.assertIn("p50m_youtube_key_status()", endpoint)
        self.assertIn("'keyPrefix'", endpoint)
        self.assertIn("'keyLength'", endpoint)
        self.assertNotIn("'key' =>", endpoint)

    def test_live_check_does_not_overwrite_config(self):
        live = (ROOT / "api" / "live-check-youtube.php").read_text(encoding="utf-8")
        self.assertIn("$channels = json_decode", live)
        self.assertNotIn("$config = json_decode(@file_get_contents($configFile)", live)
        self.assertIn("Clé YouTube absente", live)

    def test_maj_summary_exposes_key_source(self):
        ui = (ROOT / "data-engine-ui.js").read_text(encoding="utf-8")
        self.assertIn("youtube.keySource", ui)
        self.assertIn("youtube.keyLength", ui)


if __name__ == "__main__":
    unittest.main()
