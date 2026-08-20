#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class PeakLoadCacheLiveV1Tests(unittest.TestCase):
    def test_status_uses_fast_cache_path(self):
        legacy = read("api/live-status.php")
        cache = read("api/live-status-cache-core.php")
        self.assertIn("live-status-cache-core.php", legacy)
        self.assertIn("p50_live_status_cache_respond", legacy)
        self.assertIn("mode === 'status'", legacy)
        self.assertIn("PASS50-LIVE-STATUS-CACHE-V1", cache)
        self.assertIn("GET_LOCK('pass50_live_status_cache'", cache)
        self.assertIn("Cache-Control: public, max-age=", cache)
        self.assertIn("JSON_EXTRACT(data, '$.liveStreams')", cache)
        self.assertNotIn("p50_de_load_public_state()", cache)
        self.assertNotIn("p50_de_sync_registry_from_state", cache)

    def test_scan_path_warms_status_snapshot(self):
        endpoint = read("api/live-status-v4.php")
        self.assertIn("live-status-cache-core.php", endpoint)
        self.assertIn("p50_live_status_cache_store", endpoint)

    def test_hot_paths_advertise_http_cache(self):
        ranking = read("api/public-ranking.php")
        feed = read("api/content-feed.php")
        boot = read("api/app-bootstrap.php")
        self.assertIn("Cache-Control: public, max-age=60, stale-while-revalidate=120", ranking)
        self.assertIn("Cache-Control: public, max-age=30, stale-while-revalidate=60", feed)
        self.assertIn("Cache-Control: public, max-age=60, stale-while-revalidate=120", boot)
        self.assertIn("Cache-Control: private, no-store", boot)

    def test_deploy_priority_includes_cache_core(self):
        deploy = read(".github/workflows/deploy-ionos.yml")
        self.assertIn("live-status-cache-core.php", deploy)
        self.assertIn("live-status-v4.php", deploy)


if __name__ == "__main__":
    unittest.main()
