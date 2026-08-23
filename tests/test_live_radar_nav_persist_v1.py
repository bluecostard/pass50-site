from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class LiveRadarNavPersistV1Tests(unittest.TestCase):
    def test_cloud_hydrate_keeps_automatic_lives(self):
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        self.assertIn("function mergeLiveStreams(", index)
        self.assertIn("radarLivesKeep", index)
        self.assertIn("mergeLiveStreams(radarLivesKeep,cloud.liveStreams)", index)
        self.assertNotIn(
            "db.liveStreams=Array.isArray(cloud.liveStreams)?cloud.liveStreams:[]",
            index,
        )
        self.assertIn("await refreshLiveStatus();", index)

    def test_status_poll_is_fast_enough_after_navigation(self):
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        radar = (ROOT / "live-radar-v3.js").read_text(encoding="utf-8")
        feed = (ROOT / "mon-fil.js").read_text(encoding="utf-8")
        self.assertIn("setInterval(refreshLiveStatus,20000)", index)
        self.assertIn("const QUICK_INTERVAL=20_000", radar)
        self.assertIn("setInterval(refreshRadar, 20000)", feed)

    def test_full_scan_is_resilient_to_partial_failures(self):
        endpoint = (ROOT / "api" / "live-status-v4.php").read_text(encoding="utf-8")
        parsers = (ROOT / "api" / "live-radar-v4-parsers.php").read_text(encoding="utf-8")
        workflow = (ROOT / ".github" / "workflows" / "live-radar-sweep.yml").read_text(encoding="utf-8")
        self.assertIn("set_time_limit(120)", endpoint)
        self.assertIn("scan_batch_exception", parsers)
        self.assertIn("scan_pass_exception", endpoint)
        self.assertIn("Trop d'échecs HTTP pendant le balayage radar.", workflow)
        self.assertIn("batch=10", workflow)
        self.assertNotIn("--fail --silent --show-error --location --max-time 45", workflow)
        self.assertNotIn("--fail --silent --show-error --location --max-time 45 --retry 2 --retry-delay 2", workflow)


if __name__ == "__main__":
    unittest.main()
