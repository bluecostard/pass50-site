#!/usr/bin/env python3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class CloudflareEdgeCacheV1Tests(unittest.TestCase):
    def test_htaccess_does_not_force_php_no_store(self):
        htaccess = read(".htaccess")
        self.assertIn('Cache-Control "no-store, no-cache, must-revalidate, max-age=0"', htaccess)
        self.assertIn("Ne PAS forcer no-store sur tous les .php", htaccess)
        self.assertNotIn(
            'FilesMatch "\\.(php)$">\n    Header always set Cache-Control "no-store"',
            htaccess,
        )

    def test_public_edge_cache_helper(self):
        boot = read("api/bootstrap.php")
        self.assertIn("function p50_public_edge_cache", boot)
        self.assertIn("Cloudflare-CDN-Cache-Control", boot)
        self.assertIn("CDN-Cache-Control", boot)

    def test_hot_endpoints_use_edge_helper(self):
        ranking = read("api/public-ranking.php")
        feed = read("api/content-feed.php")
        app = read("api/app-bootstrap.php")
        live = read("api/live-status-cache-core.php")
        self.assertIn("p50_public_edge_cache(60, 120)", ranking)
        self.assertIn("p50_public_edge_cache(30, 60)", feed)
        self.assertIn("p50_public_edge_cache(60, 120)", app)
        self.assertIn("p50_public_edge_cache(P50_LIVE_STATUS_CACHE_MAX_AGE", live)

    def test_cloudflare_setup_checklist_exists(self):
        setup = read("cloudflare/PASS50-CLOUDFLARE-SETUP.txt")
        self.assertIn("plan Free", setup)
        self.assertIn("Full (strict)", setup)
        self.assertIn("public-ranking.php", setup)
        self.assertIn("Bypass cache", setup)
        self.assertIn("Respect Existing Headers", setup)


if __name__ == "__main__":
    unittest.main()
