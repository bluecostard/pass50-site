#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class Cloudflare524MitigateV1Tests(unittest.TestCase):
    def test_registry_sync_is_stale_gated_on_scan_paths(self):
        core = read("api/data-engine-core.php")
        endpoint = read("api/live-status-v4.php")
        hub = read("api/data-hub.php")
        self.assertIn("function p50_de_sync_registry_if_stale", core)
        self.assertIn("registry_last_sync_at", core)
        self.assertIn("p50_de_sync_registry_if_stale(300)", endpoint)
        self.assertIn("p50_de_sync_registry_if_stale(300, $forceRegistrySync)", core)
        self.assertIn("p50_de_hub_payload($forceSync)", hub)

    def test_live_radar_crons_are_staggered(self):
        p0 = read(".github/workflows/live-radar-p0.yml")
        quick = read(".github/workflows/live-radar-quick.yml")
        sweep = read(".github/workflows/live-radar-sweep.yml")
        meta = read(".github/workflows/meta-live-sweep.yml")
        metrics = read(".github/workflows/metrics-priority-15m.yml")
        self.assertIn("cron: '0,5,10", p0)
        self.assertIn("cron: '1,6,11", quick)
        self.assertIn("cron: '3,8,13", sweep)
        self.assertIn("cron: '2,7,12", meta)
        self.assertIn("cron: '7,22,37,52", metrics)
        self.assertNotIn("cron: '*/5 * * * *'", quick)
        self.assertNotIn("cron: '*/15 * * * *'", metrics)

    def test_quick_sweep_reduced_fanout(self):
        quick = read(".github/workflows/live-radar-quick.yml")
        self.assertIn("for pass in 1 2;", quick)
        self.assertIn("batch=10", quick)
        self.assertIn("cancel-in-progress: true", quick)

    def test_sweep_cancels_stale_runs(self):
        sweep = read(".github/workflows/live-radar-sweep.yml")
        self.assertIn("cancel-in-progress: true", sweep)

    def test_cloudflare_cache_rule_lists_live_status_v4(self):
        setup = read("cloudflare/PASS50-CLOUDFLARE-SETUP.txt")
        self.assertIn("/api/live-status-v4.php", setup)


if __name__ == "__main__":
    unittest.main()
