from pathlib import Path
import unittest
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from live_radar_continuous_tick import AUTONOMY_REVISION, DEFAULT_TICK, DEFAULT_SECONDS  # noqa: E402

SOURCE = (ROOT / "api" / "live-radar-v4-source.php").read_text(encoding="utf-8")
CONTRACT = (ROOT / "api" / "live-radar-contract.php").read_text(encoding="utf-8")
P0 = (ROOT / ".github" / "workflows" / "live-radar-p0.yml").read_text(encoding="utf-8")
QUICK = (ROOT / ".github" / "workflows" / "live-radar-quick.yml").read_text(encoding="utf-8")
SWEEP = (ROOT / ".github" / "workflows" / "live-radar-sweep.yml").read_text(encoding="utf-8")
CLIENT = (ROOT / "live-radar-v3.js").read_text(encoding="utf-8")
NOTES = (ROOT / "LIVE_RADAR_V4_NOTES.md").read_text(encoding="utf-8")
TICK = (ROOT / "scripts" / "live_radar_continuous_tick.py").read_text(encoding="utf-8")


class LiveRadarAutonomyFreezeV1Tests(unittest.TestCase):
    def test_autonomy_constants_are_frozen(self):
        self.assertIn("PASS50_LIVE_RADAR_AUTONOMY_V1", SOURCE)
        self.assertIn("const P50_LIVE_RADAR_REQUIRES_APP_OPEN = false", SOURCE)
        self.assertIn("const P50_LIVE_RADAR_CONTINUOUS_TICK_SECONDS = 1", SOURCE)
        self.assertIn("const P50_LIVE_RADAR_DETECTION_OWNER = 'server'", SOURCE)
        self.assertEqual(AUTONOMY_REVISION, "PASS50_LIVE_RADAR_AUTONOMY_V1")
        self.assertEqual(DEFAULT_TICK, 1.0)
        self.assertGreaterEqual(DEFAULT_SECONDS, 240)

    def test_contract_exposes_autonomy_without_app(self):
        self.assertIn("'requiresAppOpen'=>P50_LIVE_RADAR_REQUIRES_APP_OPEN", CONTRACT)
        self.assertIn("'runs24x7'=>true", CONTRACT)
        self.assertIn("'continuousTickSeconds'=>P50_LIVE_RADAR_CONTINUOUS_TICK_SECONDS", CONTRACT)
        self.assertIn("'clientRole'=>'cache_read_only'", CONTRACT)
        self.assertIn("p0Continuous", CONTRACT)

    def test_server_jobs_run_without_client(self):
        self.assertIn("PASS50_LIVE_RADAR_AUTONOMY_V1", P0)
        self.assertIn("live_radar_continuous_tick.py", P0)
        self.assertIn("--tick 1", P0)
        self.assertIn("cron: '*/5 * * * *'", P0)
        self.assertIn("cron: '*/5 * * * *'", QUICK)
        self.assertIn("cron: '*/5 * * * *'", SWEEP)
        self.assertNotIn("cron: '*/15 * * * *'", QUICK)

    def test_client_is_cache_only_never_detection(self):
        self.assertIn("PASS50_LIVE_RADAR_AUTONOMY_V1", CLIENT)
        self.assertIn("mode:'status'", CLIENT)
        self.assertIn("document.hidden", CLIENT)
        self.assertNotIn("mode:'quick'", CLIENT)
        self.assertNotIn("mode:'full'", CLIENT.split("async function runFullSweep")[0])

    def test_docs_and_script_freeze_the_rule(self):
        self.assertIn("PASS50_LIVE_RADAR_AUTONOMY_V1", NOTES)
        self.assertIn("même si l’app", NOTES)
        self.assertIn("Tick cible **1 seconde**", NOTES)
        self.assertIn("requiresAppOpen === false", NOTES)
        self.assertIn("'requiresAppOpen': False", TICK)
        self.assertIn("app non requise", TICK)


if __name__ == "__main__":
    unittest.main()
