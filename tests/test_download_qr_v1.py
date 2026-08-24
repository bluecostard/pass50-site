#!/usr/bin/env python3
"""Lien d'installation PASS50 hors stores + QR officiel."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOWNLOAD_URL = "https://pass50.store/telecharger"


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class DownloadQrV1Tests(unittest.TestCase):
    def test_download_page_is_direct_install_not_store(self):
        page = read("telecharger.html")
        self.assertIn(DOWNLOAD_URL, page)
        self.assertIn("./app.html?source=download", page)
        self.assertIn("App Store ni Google Play", page)
        self.assertIn("assets/qr-telecharger-pass50.svg", page)
        self.assertIn("assets/qr-telecharger-pass50.png", page)
        self.assertNotIn("apps.apple.com", page)
        self.assertNotIn("play.google.com", page)
        self.assertNotIn("itunes.apple.com", page)

    def test_qr_assets_match_download_url(self):
        script = read("scripts/generate_download_qr.py")
        svg = (ROOT / "assets" / "qr-telecharger-pass50.svg").read_text(encoding="utf-8")
        png = ROOT / "assets" / "qr-telecharger-pass50.png"
        self.assertIn(f'QR_URL = "{DOWNLOAD_URL}"', script)
        self.assertTrue(png.is_file())
        self.assertGreater(png.stat().st_size, 500)
        self.assertIn('width="492"', svg)
        self.assertIn('height="492"', svg)
        self.assertIn('stroke="#050705"', svg)
        self.assertGreater(len(svg), 500)

    def test_public_wiring_and_deploy(self):
        htaccess = read(".htaccess")
        sitemap = read("sitemap.php")
        index = read("index.html")
        boot = read("api/app-bootstrap.php")
        deploy = read(".github/workflows/deploy-ionos.yml")
        sw = read("sw.js")
        self.assertIn("RewriteRule ^telecharger/?$ telecharger.html [L]", htaccess)
        self.assertIn("/telecharger.html", sitemap)
        self.assertIn("./telecharger.html", index)
        self.assertIn("'download' => 'telecharger.html'", boot)
        self.assertIn("hors stores", boot)
        self.assertIn("put -O ${REMOTE_DIR@Q} .deploy/telecharger.html", deploy)
        self.assertIn("qr-telecharger-pass50.svg", deploy)
        self.assertIn("qr-telecharger-pass50.png", deploy)
        self.assertIn("./telecharger.html", sw)


if __name__ == "__main__":
    unittest.main()
